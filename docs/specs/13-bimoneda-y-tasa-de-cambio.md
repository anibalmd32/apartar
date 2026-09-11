# Módulo 13 — Bimoneda, `Money` y tasa de cambio (`FX`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Asunciones aplicadas: `ASU-FX-01` … `ASU-FX-08` · Decisión `D-6` de [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md)
> Requisitos: `REQ-FX-001` … `REQ-FX-006`
>
> **Este módulo es la base aritmética de todo el sistema.** `LAY`, `PAY` y `SUB` dependen de él; ningún otro módulo puede implementar conversión ni redondeo por su cuenta.
>
> **Efecto del pivote (`D-6`):** `exchange_rates` es una tabla **de plataforma**, no del tenant. La tasa del BCV es la misma para todos, así que se ingesta **una sola vez** para toda la plataforma en lugar de N veces. Lo que sí es por organización es **qué fuente usa** para proyectar (`REQ-FX-003`) y **cómo la presenta por defecto** (`REQ-FX-004`). Toda la aritmética sobrevive sin cambios.

## Contratos compartidos del módulo

```php
enum Currency: string { case USD = 'USD'; case VES = 'VES'; }

enum RateSource: string { case Bcv = 'bcv'; case Parallel = 'parallel'; case Manual = 'manual'; }

/** Tasa concreta usada en una operación, con su procedencia y antigüedad. */
final readonly class RateSnapshot
{
    public function __construct(
        public string $rate,                    // decimal en string: nunca float
        public RateSource $source,
        /** Cuándo la publicó la fuente (BCV). Es la antigüedad que le importa al negocio. */
        public CarbonImmutable $publishedAt,
        /** Cuándo la leímos nosotros. Solo sirve para diagnosticar la ingesta. */
        public CarbonImmutable $fetchedAt,
    ) {}

    /** Obsolescencia medida sobre publishedAt, no sobre fetchedAt. */
    public function isStale(int $maxAgeHours = 24): bool { /* … */ }
}

/** Contrato de obtención de la tasa. Toda fuente externa se implementa detrás de esta interfaz. */
interface ExchangeRateProvider
{
    /** @throws ExchangeRateUnavailableException */
    public function fetch(RateSource $source): RateSnapshot;
    public function supports(RateSource $source): bool;
}
```

```ts
/** Tasa vigente, presente en las page props de toda página con precios. */
export interface RateView {
  readonly rate: number | null;        // null ⇒ sin tasa disponible
  readonly source: 'bcv' | 'parallel' | 'manual';
  readonly fetchedAt: string | null;   // ISO 8601
  readonly isStale: boolean;           // > 24 h
}
```

---

### REQ-FX-001 · Value Object `Money` y almacenamiento en centavos

**Historia de usuario**
> Como responsable del negocio, quiero que los saldos cuadren al céntimo, para que ningún cliente termine con una deuda o un crédito inventado por un error de redondeo.

**Contratos de datos**
- `Money`, `Currency`, `MoneyFormatter` en `app/Domain/Money/`.
- Cast de Eloquent `MoneyCast` que mapea `*_cents` ↔ `Money`.
- Tipos TS `CentsUsd`, `MoneyView`.

```php
final readonly class Money
{
    public function __construct(public int $cents, public Currency $currency = Currency::USD) {}

    public static function fromDecimalString(string $amount, Currency $c = Currency::USD): self { /* … */ }

    public function add(self $other): self { /* … */ }
    public function subtract(self $other): self { /* … */ }
    public function multiply(int $factor): self { /* … */ }
    public function isNegative(): bool { /* … */ }
    public function isZeroOrBelowTolerance(int $toleranceCents): bool { /* … */ }

    /**
     * Reparte el importe en $parts partes iguales, acumulando el residuo
     * de la división entera en la ÚLTIMA parte.
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array { /* … */ }

    public function convertTo(Currency $target, RateSnapshot $rate): self { /* … */ }
}
```

**Reglas de negocio y precondiciones**
- RB-1 Todo importe se almacena como `BIGINT` de **centavos de USD** con sufijo `_cents`. `FLOAT`/`DOUBLE` está prohibido en el esquema y en el código (regla verificable en CI).
- RB-2 `Money` es inmutable: toda operación devuelve una instancia nueva.
- RB-3 Operar entre monedas distintas lanza `CurrencyMismatchException`; no hay conversión implícita.
- RB-4 `allocateEvenly(n)` cumple la invariante **`sum(partes) === cents`** para cualquier `cents` y `n ≥ 1`; el residuo va a la última parte (`ASU-FX-05`).
- RB-5 La conversión a VES usa `bcmul` con escala 8 y redondea **half-up** a 2 decimales al final; nunca se redondea en pasos intermedios.
- RB-6 El frontend recibe importes **ya formateados** en `MoneyView` (`usd` y `ves` como strings listos) además de los centavos; no reimplementa el formateo de la conversión.
- RB-7 Formato de presentación: USD `$1,234.56` (coma de miles, punto decimal); VES `Bs. 1.234,56` (punto de miles, coma decimal), conforme a la convención local.

**Happy path**
1. `products.price_cents = 2000` se hidrata como `Money(2000, USD)`.
2. `ProductDetailResource` calcula la cuota con `allocateEvenly()` y proyecta a VES con la tasa vigente.
3. La página recibe `{ cents: 2000, usd: "$20.00", ves: "Bs. 850,00" }`.

**Sad paths y edge cases**
- SP-1 `allocateEvenly(0)` → `InvalidArgumentException`.
- SP-2 Importe que no divide exacto (`$100 / 3`) → `[3333, 3333, 3334]` centavos: el residuo se acumula en la **última** parte y la suma es exactamente `10000`.
- SP-3 `subtract` que produce negativo → permitido en `Money` (existe el concepto de crédito), pero rechazado por las reglas de `LAY`/`PAY` que lo usen.
- SP-4 Tasa `0` o negativa → `InvalidRateException` antes de convertir.
- SP-5 Importe que excede el rango de `BIGINT` → imposible en la práctica; se documenta el límite y se valida en el CRUD (precio máximo $1.000.000).
- SP-6 Cast de Eloquent con valor `null` (columnas opcionales como `compare_at_price_cents`) → devuelve `null`, no `Money(0)`.

**Fuera de alcance inmediato**
- Monedas distintas de USD y VES.
- Aritmética de porcentajes (descuentos, intereses).
- Librería monetaria externa (`brick/money`, `moneyphp`): se documenta como sustitución posible del VO propio.

**Gate de validación**
- Superficie esperada: `app/Domain/Money/Money.php`, `app/Domain/Money/Currency.php`, `app/Domain/Money/MoneyFormatter.php`, `app/Casts/MoneyCast.php`, `resources/js/lib/money.ts`.
- Tipos esperados: `Money`, `Currency`, `CurrencyMismatchException`, `InvalidRateException`, `CentsUsd`, `MoneyView`.
- Anti-`SHALLOW`: `Money::allocateEvenly()`, `Money::convertTo()` y `MoneyFormatter::format()` son métodos > 4 líneas **en el mismo archivo** que sus clases; `MoneyCast::get()/set()` idem.
- Tests: propiedad `sum(allocateEvenly(n)) === cents` verificada con 500 casos aleatorios; residuo en la última parte; mezcla de monedas lanza excepción; formateo USD/VES exacto; cast con `null`; conversión con tasa inválida.

---

### REQ-FX-002 · Ingesta programada y persistencia histórica de la tasa

**Historia de usuario**
> Como operador, quiero que la tasa se actualice sola y quede registrada, para poder justificar ante un cliente con qué tasa se le procesó un pago.

**Contratos de datos**
- Entidad `ExchangeRate` (`exchange_rates`), **tabla de plataforma**: `source`, `rate DECIMAL(18,8)`, `published_at`, `fetched_at`, `INDEX(source, published_at)`. Sin `organization_id`.
- Job `FetchExchangeRate` (cola `high`), programado cada 30 minutos, **una sola ejecución para toda la plataforma**, con **una sola petición** a `GET https://ve.dolarapi.com/v1/dolares` que trae oficial y paralelo a la vez.
- `ExchangeRateService::current(?Organization $organization = null): RateSnapshot|null` — resuelve la fuente configurada por esa organización (`REQ-FX-003`) y devuelve la tasa vigente de esa fuente.

**Reglas de negocio y precondiciones**
- RB-0 La ingesta es **de plataforma y única** (`D-6`): un job cada 30 minutos consulta las fuentes disponibles (`bcv` y `parallel`) y las persiste, con independencia de cuántas organizaciones existan. Escalar a 10.000 negocios no multiplica las llamadas a la API externa.
- RB-1 El job se ejecuta cada **30 minutos**; Redis cachea la tasa vigente **por fuente** (`rate:{source}`) con TTL **35 minutos** (ligeramente mayor que el intervalo, para que un fallo aislado no vacíe la caché).
- RB-2 La tabla `exchange_rates` es un **histórico inmutable**: solo `INSERT`. Nunca se actualiza ni se borra una fila.
- RB-3 Si la tasa obtenida es idéntica a la última persistida de la misma `source` **y su `published_at` no cambió**, **no** se inserta una fila nueva; solo se refresca la caché (evita crecimiento inútil de la tabla).
- RB-3b Se persisten **dos marcas de tiempo**: `published_at` (la `fechaActualizacion` que declara la fuente) y `fetched_at` (cuándo la leímos). La obsolescencia que se muestra al usuario y la que dispara alertas se mide sobre `published_at`: al cliente le importa cuándo el BCV publicó la tasa, no cuándo nuestro job corrió.
- RB-4 `ExchangeRateService::current()` lee la caché de la fuente pedida; si está vacía, lee la última fila de esa `source`; si tampoco existe, devuelve `null`.
- RB-5 El frontend **nunca** llama a la API externa: recibe `RateView` en las page props y la refresca contra un endpoint interno (`GET /api/tasa?source=`) cada 5 minutos con `@tanstack/react-query` (`ASU-FX-03`).
- RB-6 El endpoint interno es público, cacheado por 60 s y sin datos sensibles. Recibe la fuente como parámetro y **no** revela qué organización usa cuál: la fuente ya es pública en el catálogo por su sello.

**Happy path**
1. El scheduler despacha `FetchExchangeRate` a las :00 y :30.
2. El job consulta el provider configurado, obtiene `RateSnapshot`, inserta en `exchange_rates` (si cambió) y refresca la caché.
3. Todas las páginas siguientes reciben la tasa nueva; las pestañas abiertas la reciben en su próximo refresco de 5 minutos.

**Sad paths y edge cases**
- SP-1 API externa caída o `5xx` → el job reintenta con backoff (3 intentos: 10 s, 60 s, 300 s); si falla, registra el error y **conserva** la caché y el histórico existentes (`REQ-FX-005`).
- SP-2 API que responde `200` con una tasa absurda (`0`, negativa o con variación > 30 % respecto a la última) → se **rechaza** la tasa, se registra una alerta y se conserva la anterior (protección contra datos corruptos del proveedor).
- SP-3 Timeout de red → tratado como SP-1; el timeout HTTP es de 8 s para no bloquear la cola.
- SP-4 Cambio de formato de la respuesta del proveedor → el parser lanza `ExchangeRateUnavailableException` y se comporta como SP-1; test de contrato contra un payload fijado (fixture).
- SP-5 Dos jobs concurrentes (por reintento y por scheduler) → bloqueo `WithoutOverlapping` sobre la clave del job.
- SP-6 Reloj desfasado del contenedor → `fetched_at` lo escribe la base de datos, no el proceso PHP.

**Fuera de alcance inmediato**
- Tasa por hora del día o promedios ponderados.
- Gráfica histórica de la tasa en el backoffice.
- Alertas automáticas por variación brusca (solo se registra en log).

**Gate de validación**
- Superficie esperada: `app/Jobs/FetchExchangeRate.php`, `app/Domain/Exchange/ExchangeRateService.php`, `app/Models/ExchangeRate.php`, `routes/api.php`, `app/Http/Controllers/Api/ExchangeRateController.php`.
- Tipos esperados: `ExchangeRate`, `RateSnapshot`, `RateSource`, `ExchangeRateUnavailableException`.
- Anti-`SHALLOW`: `ExchangeRateService::current()` (caché → BD → null) y `FetchExchangeRate::handle()` (validación de plausibilidad, deduplicación, caché) superan 4 líneas en sus archivos.
- Tests: inserta al cambiar y no inserta si es idéntica; rechaza variación > 30 %; rechaza tasa ≤ 0; fallo de API conserva la anterior; `current()` cae a BD sin caché y a `null` sin datos; `WithoutOverlapping` aplicado.

---

### REQ-FX-003 · Provider intercambiable y fuente de tasa por organización

**Historia de usuario**
> Como negocio, quiero elegir si mis precios se proyectan con la tasa del BCV, con la paralela o con una tasa mía, para vender con el criterio con el que yo trabajo.

**Contratos de datos**
- `ExchangeRateProvider` (interfaz), `DolarApiProvider`, `ManualRateProvider`.
- Config `config/services.php → exchange_rate.driver`, `.url`, `.timeout`.
- Ajustes **por organización**: `organization_settings.exchange_rate_source`, `organization_settings.exchange_rate_manual`.
- Ruta `PUT /backoffice/ajustes/tasa` (solo `owner`, `REQ-ORG-003`).

**Reglas de negocio y precondiciones**
- RB-1 **La fuente es una decisión de cada organización**, no de la plataforma ni por producto (`D-6`, `ASU-FX-02`): `bcv | parallel | manual` en `organization_settings`. Dos negocios pueden mostrar el mismo producto proyectado con tasas distintas, y cada catálogo lleva su propio sello (`REQ-FX-005` RB-2).
- RB-2 La **ingesta** sigue siendo de plataforma y única (`REQ-FX-002` RB-0). La elección de fuente solo afecta a la **lectura**: `ExchangeRateService::current($organization)` resuelve qué fuente leer.
- RB-3 `ManualRateProvider` toma su valor de `organization_settings.exchange_rate_manual` de **esa** organización y devuelve `RateSnapshot` con `source = manual` y `fetchedAt` = fecha de la última edición.
- RB-4 Fijar una tasa manual requiere `owner`, se audita en la organización (`REQ-BOF-005`) y **no** inserta fila en `exchange_rates`: la tabla de plataforma solo guarda tasas de fuentes públicas, y la manual de un negocio es un dato suyo, no de la plataforma. Su histórico vive en la auditoría.
- RB-5 El binding del provider se resuelve en un `ServiceProvider` a partir de la config; añadir un proveedor nuevo no exige tocar el resto del sistema.
- RB-6 La UI del backoffice muestra, junto al selector, la tasa vigente de cada fuente disponible con su antigüedad, para que el `owner` compare antes de elegir.
- RB-7 Las **facturas de suscripción** (`REQ-SUB-006` RB-4) se convierten siempre con la fuente que fije la **plataforma** en `platform_settings`, nunca con la de la organización: la plataforma no puede cobrar a la tasa que elija su cliente.

**Happy path**
1. El `owner` abre Ajustes → Tasa de cambio y ve "BCV Bs. 42,50 (hace 12 min) · Paralelo Bs. 47,80 (hace 12 min)".
2. Elige "Paralelo".
3. Se persiste el ajuste, se invalida la caché de ajustes de esa organización y sus catálogos pasan a proyectar con 47,80, con el sello "Tasa paralelo".

**Sad paths y edge cases**
- SP-1 Cambio a `manual` sin definir valor → `422`; se exige el valor en la misma operación.
- SP-2 Tasa manual con formato inválido o ≤ 0 → `422`.
- SP-3 `member` intentando cambiarla → `403` (`REQ-ORG-003`).
- SP-4 Elegir una fuente sin tasa reciente en la plataforma → se permite y se muestra la última disponible con su sello de antigüedad (`REQ-FX-005`); si no hay ninguna de esa fuente, solo USD.
- SP-5 Driver inexistente en `.env` → el `ServiceProvider` falla en el arranque con un mensaje explícito, no en tiempo de request.
- SP-6 Organización con `manual` que abandona la plataforma y vuelve → conserva su tasa manual en sus ajustes.
- SP-7 Cambio de fuente con apartados vigentes → **no** recalcula nada: los pagos ya conciliados conservan su snapshot (`REQ-FX-006` RB-3) y las cuotas están en USD.

**Fuera de alcance inmediato**
- Tasa distinta por producto, por categoría o por método de pago.
- Margen o comisión sobre la tasa oficial.
- Selección automática de la mejor tasa.
- Fuentes de tasa propias de cada organización (una API distinta por negocio).

**Gate de validación**
- Superficie esperada: `app/Domain/Exchange/ExchangeRateProvider.php`, `app/Domain/Exchange/DolarApiProvider.php`, `app/Domain/Exchange/ManualRateProvider.php`, `app/Domain/Exchange/ExchangeRateService.php`, `app/Providers/ExchangeServiceProvider.php`, `app/Actions/Settings/UpdateExchangeRateSettingsAction.php`.
- Tipos esperados: `ExchangeRateProvider`, `DolarApiProvider`, `ManualRateProvider`, `RateSource`.
- Anti-`SHALLOW`: `DolarApiProvider::fetch()` (petición, parseo, validación, mapeo) y `ExchangeRateService::current()` (resolución de la fuente de la organización, caché, fallback) superan 4 líneas en sus archivos.
- Tests: dos organizaciones con fuentes distintas proyectan importes distintos para el mismo producto (test explícito de RB-1); la ingesta no se multiplica por organización (assert de una sola llamada HTTP con N organizaciones); `manual` no inserta en `exchange_rates`; `member` recibe `403`; la factura de suscripción usa la fuente de plataforma y no la del negocio; cambio de fuente no recalcula pagos.

---

### REQ-FX-004 · Proyección bimoneda y toggle de visualización

**Historia de usuario**
> Como cliente venezolano, quiero ver los precios en bolívares o en dólares según me convenga, para entender de inmediato cuánto voy a pagar.

**Contratos de datos**
- `CurrencyDisplay` = `'usd_primary' | 'ves_primary'`.
- Hook `useCurrencyDisplay()`, componente `<MoneyLabel money={…} />`.
- `MoneyView` (`cents`, `usd`, `ves`).
- Ajuste **por organización** `organization_settings.currency_display_default`.

**Reglas de negocio y precondiciones**
- RB-1 Modo `usd_primary` (default): monto USD destacado y VES secundario entre paréntesis → `$20.00 (Bs. 850,00)`.
- RB-2 Modo `ves_primary`: invertido → `Bs. 850,00 ($20.00)`.
- RB-3 El toggle afecta **toda** la aplicación de forma consistente: catálogo, portal, feed, precios, cuotas, saldos, cronograma e historial de pagos. La preferencia es **de la persona**, no de la organización: se conserva al pasar del catálogo de un negocio al de otro y en el feed multi-negocio (`REQ-ACC-003`).
- RB-4 La preferencia se guarda en `localStorage` **y** en una cookie de 1 año, para que el primer render del servidor ya sea correcto y no haya parpadeo (`ASU-FX-08`).
- RB-5 El valor inicial para un visitante nuevo es `organization_settings.currency_display_default` de la organización del catálogo por el que entró. En el feed, donde conviven varias organizaciones, el default es el de plataforma (`usd_primary`) hasta que la persona elija.
- RB-6 El cambio de toggle es **puramente de cliente**: no genera petición al servidor, porque `MoneyView` ya trae ambas representaciones.
- RB-7 Cada cambio emite `currency_toggled` (`REQ-TLM-002`).
- RB-8 Los importes **históricos ya conciliados** se muestran con la tasa de su snapshot, no con la tasa vigente (`REQ-FX-006`), y llevan una marca de "tasa del día del pago".

**Happy path**
1. El visitante entra: la cookie está vacía, se aplica el default `usd_primary`.
2. Pulsa el toggle en la cabecera; todos los importes de la página cambian de orden sin recargar.
3. Vuelve al día siguiente y su preferencia persiste.

**Sad paths y edge cases**
- SP-1 `localStorage` bloqueado (modo privado) → se usa solo la cookie; si tampoco, el toggle funciona en memoria durante la sesión.
- SP-2 Sin tasa disponible → el toggle se deshabilita con tooltip y todo se muestra en USD (`REQ-FX-005`).
- SP-3 Cookie con valor corrupto → se descarta y se aplica el default sin error.
- SP-4 Tasa actualizada mientras el usuario navega → los importes se reproyectan en la siguiente navegación Inertia o en el refresco de 5 minutos; **no** se reproyectan los importes ya conciliados.
- SP-5 Importes en emails y exportaciones → siempre en **ambas** monedas explícitas, sin depender del toggle.

**Fuera de alcance inmediato**
- Selector de idioma o de formato regional.
- Mostrar una tercera moneda.
- Recordar la preferencia por dispositivo en el servidor.

**Gate de validación**
- Superficie esperada: `resources/js/features/currency/hooks/useCurrencyDisplay.ts`, `resources/js/features/currency/components/MoneyLabel.tsx`, `resources/js/features/currency/components/CurrencyToggle.tsx`, `app/Http/Resources/Concerns/ProjectsMoney.php`.
- Tipos esperados: `CurrencyDisplay`, `MoneyView`, `RateView`.
- Anti-`SHALLOW`: `useCurrencyDisplay` (lectura de cookie + `localStorage` + default + persistencia + emisión de telemetría) supera 4 líneas junto a su tipo; el trait `ProjectsMoney::money()` compone `MoneyView` completo.
- Tests (Vitest): renderiza ambos modos con el formato exacto; sin tasa muestra solo USD y deshabilita el toggle; `localStorage` inaccesible no rompe; cookie corrupta cae al default; emite `currency_toggled`.

---

### REQ-FX-005 · Degradación ante indisponibilidad de la tasa

**Historia de usuario**
> Como responsable del negocio, quiero que la tienda siga vendiendo aunque la fuente de la tasa falle, para no perder apartados por un problema ajeno.

**Contratos de datos**
- `RateView.rate = null`, `RateView.isStale`.
- `ExchangeRateUnavailableException`.
- Componente `<RateStamp rate={…} />`.

**Reglas de negocio y precondiciones**
- RB-1 **Nunca** se bloquea la navegación, la PDP ni la creación de un apartado por falta de tasa (`ASU-FX-07`).
- RB-2 Con tasa disponible (aunque antigua), se proyecta y se muestra el sello "Tasa BCV del DD/MM HH:mm".
- RB-3 Con tasa de más de **24 h**, además del sello se muestra un aviso discreto de posible desactualización.
- RB-4 Sin ninguna tasa en el histórico, la UI **oculta** por completo el monto en VES (no muestra `Bs. 0,00` ni `—`) y desactiva el toggle.
- RB-5 Un comprobante declarado en VES **sí puede** subirse sin tasa; la conversión se resuelve al aprobar (`REQ-PAY-005`), y la aprobación **sí** exige tasa: si no hay ninguna, el administrador debe fijar una manual antes de aprobar.
- RB-6 El estado de degradación es visible para el negocio en su dashboard (`REQ-BOF-044`) y para la plataforma en Salud del sistema (`REQ-PLT-006` RB-4): una tasa obsoleta afecta a **todas** las organizaciones a la vez, así que es una alerta de plataforma, no de un solo negocio.

**Happy path**
1. La API del proveedor lleva 3 horas caída.
2. Los catálogos siguen proyectando con la última tasa persistida y muestran el sello con su hora.
3. La operación continúa con normalidad; el dashboard del backoffice muestra la advertencia de antigüedad.

**Sad paths y edge cases**
- SP-1 Instalación nueva sin ninguna tasa (primer despliegue antes del primer job) → el catálogo funciona en USD puro; el seeder inserta una tasa inicial para evitar este estado en producción.
- SP-2 Aprobación de comprobante en VES sin tasa → `422` con instrucción explícita ("fija la tasa manual en Ajustes para conciliar este pago").
- SP-3 Tasa que vuelve tras horas caída → los importes vigentes se reproyectan; los ya conciliados no cambian (`REQ-FX-006`).
- SP-4 Provider que responde muy lento en el request del endpoint interno → el endpoint **nunca** consulta el provider; solo lee caché/BD, así que no hay riesgo de latencia.

**Fuera de alcance inmediato**
- Página de estado público del servicio.
- Notificación automática al administrador por caída de la tasa.

**Gate de validación**
- Superficie esperada: `app/Domain/Exchange/ExchangeRateService.php`, `app/Http/Resources/Concerns/ProjectsMoney.php`, `resources/js/features/currency/components/RateStamp.tsx`, `app/Actions/Payment/ApproveReceiptAction.php`.
- Tipos esperados: `RateView`, `ExchangeRateUnavailableException`, `MissingExchangeRateException`.
- Anti-`SHALLOW`: `ProjectsMoney::money()` decide entre proyectar y omitir VES (> 4 líneas); `RateSnapshot::isStale()` junto a su clase.
- Tests: sin tasa el `MoneyView.ves` es `null`; tasa de 30 h marca `isStale`; aprobar en VES sin tasa da `422`; navegación y creación de apartado funcionan sin tasa; el sello muestra la fecha correcta.

---

### REQ-FX-006 · Snapshot inmutable de tasa en operaciones financieras

**Historia de usuario**
> Como administrador, quiero que un pago conciliado conserve para siempre la tasa con la que se procesó, para que el balance del cliente no cambie cuando la tasa suba.

**Contratos de datos**
- Campos en `payments`: `amount_cents_usd`, `declared_currency`, `declared_amount`, `rate_applied DECIMAL(18,8)`, `rate_source`, `rate_fetched_at`, `settled_at`.
- `RateSnapshot` como parámetro obligatorio de `ApproveReceiptAction` y `RegisterManualPaymentAction`.

**Reglas de negocio y precondiciones**
- RB-1 **La deuda es siempre en USD** (`ASU-FX-04`). El cronograma, el total y el saldo se expresan exclusivamente en centavos de USD.
- RB-2 Un pago declarado en VES se convierte a USD con la tasa **vigente el día de la conciliación**, y el resultado se congela en `payments`.
- RB-3 `rate_applied`, `rate_source` y `rate_fetched_at` son **inmutables**: no existe operación que los actualice. Corregir un pago exige anularlo y crear otro (fuera de alcance en v1: se resuelve con `REQ-PAY-009` reasignando, o cancelando el apartado).
- RB-4 Un pago declarado en USD guarda `rate_applied = 1`, `rate_source` = la vigente y `declared_amount` en USD; así el historial es homogéneo.
- RB-5 El historial del cliente y del backoffice muestra cada pago con el par (monto USD, monto declarado + tasa aplicada), nunca reconvertido con la tasa de hoy.
- RB-6 Las exportaciones a Excel incluyen `rate_applied` y `rate_source` por fila de pago (`REQ-BOF-043`).
- RB-7 Cambios posteriores de la tasa **no** disparan ningún recálculo de saldos (invariante verificable: un test cambia la tasa y comprueba que `paid_cents` y `remaining_cents` no se mueven).

**Happy path**
1. El cliente paga Bs. 850 el día en que la tasa es 42,50 y sube el comprobante.
2. El administrador lo aprueba; `ApproveReceiptAction` toma `RateSnapshot(42.50, bcv, …)`, calcula `2000` centavos USD y persiste el snapshot completo.
3. Un mes después, con la tasa a 60, el historial sigue mostrando "Bs. 850,00 · tasa 42,50 · $20.00" y el saldo es el mismo.

**Sad paths y edge cases**
- SP-1 Aprobación sin tasa disponible → `MissingExchangeRateException` → `422` (`REQ-FX-005` SP-2).
- SP-2 El administrador ajusta el monto recibido al aprobar (`REQ-PAY-005`) → se recalcula el USD con la **misma** tasa del snapshot, no con otra.
- SP-3 El administrador quiere aplicar una tasa distinta de la vigente (pago recibido días antes) → **permitido**: el formulario de aprobación deja editar `rate_applied`, con `rate_source = manual` forzado y auditoría obligatoria.
- SP-4 Conversión que produce 0 centavos (importe declarado ínfimo) → `422`, no se crean pagos de 0.
- SP-5 Pago en VES con tasa de un histórico muy antiguo (más de 30 días) → se permite pero la UI exige confirmación explícita del administrador.
- SP-6 Redondeo de la conversión → half-up a centavo; la diferencia de redondeo la absorbe el saldo, nunca se genera un ajuste automático.

**Fuera de alcance inmediato**
- Anulación/reverso de un pago ya conciliado.
- Ajustes contables por diferencia cambiaria.
- Reexpresión del histórico a otra moneda.

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/ApproveReceiptAction.php`, `app/Actions/Payment/RegisterManualPaymentAction.php`, `app/Models/Payment.php`, `database/migrations/*_create_payments_table.php`.
- Tipos esperados: `Payment` con los seis campos de snapshot, `RateSnapshot`, `MissingExchangeRateException`.
- Anti-`SHALLOW`: `ApproveReceiptAction::handle()` (resolución de tasa, conversión, creación del pago con snapshot, asignación FIFO, recálculo de balance, todo transaccional) es el método > 4 líneas que resuelve el `SHALLOW` de este requisito.
- Tests: pago en VES congela la tasa; subir la tasa después no altera `paid_cents` ni `remaining_cents`; pago en USD guarda `rate_applied = 1`; tasa editada manualmente se audita; conversión a 0 centavos rechazada; el historial no reconvierte.
