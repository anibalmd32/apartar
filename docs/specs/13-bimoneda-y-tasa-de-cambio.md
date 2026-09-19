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
  readonly sourceLabel: string;        // «BCV» · resuelto en RateSource::label()
  readonly publishedAt: string | null; // ISO 8601 · cuándo la publicó la fuente
  readonly isStale: boolean;           // > 24 h sobre publishedAt
}

/** Lo que toda página con precios recibe en sus props compartidas. */
export interface CurrencyPageProps {
  readonly display: CurrencyDisplay;   // resuelta en el servidor: sin parpadeo
  readonly rate: RateView;
  readonly timezone: string;           // la del negocio, no la del navegador
}
```

> **`RateView` lleva `publishedAt`, no `fetchedAt`.** Lo detectó `T-019`, que shipeó el contrato como estaba; el código se alineó en `T-017`. El contrato decía `fetchedAt` mientras `RateSnapshot::isStale()` mide sobre `publishedAt`, así que el sello de `REQ-FX-005` RB-2 —«Tasa BCV del DD/MM HH:mm»— habría mostrado **cuándo la leímos nosotros** y el aviso de RB-3 se habría disparado sobre otra fecha distinta de la mostrada. El fallo se ve un fin de semana: el BCV no publica, el job sigue leyendo cada media hora, y el catálogo estampa «hoy 09:00» sobre una tasa del viernes — exactamente el engaño que tener dos columnas venía a evitar.
>
> **`rate` es `number`, y es el único coma flotante de todo el sistema.** Está aquí, en el borde de salida, y en ningún sitio más: `REQ-FX-001` RB-1 prohíbe el `float` porque la aritmética del dinero tiene que ser exacta, y esta cifra **no participa en ninguna aritmética**. Los importes llegan ya proyectados y formateados en `MoneyView` (RB-6), así que lo único que el cliente hace con `rate` es pintarlo en el sello. Del lado PHP, `RateView::of()` es el único punto donde `RateSnapshot->rate` deja de ser una cadena decimal, y eso lo hace revisable de un vistazo. Si algún día aparece una multiplicación sobre este campo, el error no es el `float`: es que la cuenta está en el lado equivocado.
>
> `fetchedAt` **no viaja al cliente**. Su propio comentario dice que solo sirve para diagnosticar la ingesta, y un campo que se envía en todas las páginas con precios y significa algo sutilmente distinto de lo que parece es justo el que acaba pintado por error. La antigüedad de la ingesta se diagnostica en el servidor y se ve en Salud del sistema (`REQ-PLT-006` RB-4).
>
> **Los tres datos de `CurrencyPageProps` viajan juntos porque se usan juntos, y entran por `HandleInertiaRequests`** (`T-022`). No por cada controlador: los atraviesan catálogo, portal, feed y backoffice, y repetirlos garantiza que a la pantalla siguiente se le olvide a alguien. La preferencia la resuelve el **servidor** —cookie válida, `currency_display_default`, `usd_primary`— porque descubrirla al montar abriría la página en dólares y saltaría a bolívares en cada visita de todo el que hubiera elegido bolívares (RB-4). Y la tasa sale de `CurrentRate`, que la resuelve **una sola vez por respuesta**: si el sello sale de una consulta y los importes de otra, basta que la ingesta inserte entre las dos para estampar una tasa distinta de la que multiplicó, y ese fallo no tiene síntoma — el número es creíble. Su binding es `scoped()` por lo mismo que el del proveedor no es singleton (`REQ-FX-003` RB-5b).
>
> **Cómo se llama cada fuente lo dice el servidor, y `T-021` lo escribió en la pantalla.** `RateSource` no tiene `label()`, así que `ExchangeRateForm` guarda las tres etiquetas en una constante propia — el segundo censo contra el que avisa el comentario del tipo y lo que `PaymentMethod` ya resolvió con `label()` en el enum. Con un solo consumidor no hace daño; deja de ser inocuo en `T-022`, donde `RateStamp` tiene que escribir «Tasa BCV del DD/MM HH:mm» sobre las mismas tres fuentes: con dos diccionarios, la pantalla de ajustes y el sello del catálogo pueden acabar llamando cosas distintas a la misma tasa, y quien lo note será alguien que ya dudaba de la cifra. **Lo realinea `T-022`.** El **nombre** de la fuente se escribe una vez y sale del enum; lo que el desplegable añada para explicar la opción es presentación de esa opción, no un segundo nombre. **Hecho** (`0913525`): `RateSource::label()` da «BCV», «Paralelo» y «Manual», viajan resueltos en `RateView.sourceLabel`, y la pantalla de ajustes conserva solo su frase de ayuda —«tasa oficial», «la que yo fije»—, que no es un nombre sino lo que se le dice a quien está decidiendo.

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
- RB-5b **La conversión es bidireccional y simétrica.** `VES → USD` va por `bcdiv` a la misma escala 8 con el mismo redondeo único al final, y existe porque `REQ-FX-006` RB-2 la necesita para conciliar un pago declarado en bolívares. Dejarla sin implementar habría sido el muñón que se encuentra H4.
- RB-5c **Convertir a la misma moneda devuelve el importe y no mira la tasa.** Es una excepción deliberada a SP-4: validar siempre primero haría que una fila corrupta en el histórico de tasas apagara el catálogo entero, y `REQ-FX-005` RB-1 dice que **nunca** se bloquea la navegación por falta de tasa. Una conversión `USD → USD` no necesita tasa para ser correcta, así que pedirle una válida es inventar una dependencia y convertirla en un modo de caída.
- RB-6 El frontend recibe importes **ya formateados** en `MoneyView` (`usd` y `ves` como strings listos) además de los centavos; no reimplementa el formateo de la conversión.
- RB-7 Formato de presentación: USD `$1,234.56` (coma de miles, punto decimal); VES `Bs. 1.234,56` (punto de miles, coma decimal), conforme a la convención local.
- RB-8 **`isZeroOrBelowTolerance()` compara con signo (`cents <= tolerance`), no en valor absoluto.** Decidido en `T-017`, y es normativo porque de él dependen `LAY` y `PAY`: la pregunta que responde es *si se puede dar por saldado*, y **haber pagado de más no es una razón para dejar un apartado abierto**. Con `abs()`, un cliente que paga cinco dólares de sobra se queda con el apartado vivo y sin forma de cerrarlo. Qué hacer con ese crédito es de `REQ-PAY-…`, no de esta comparación.
- RB-10 **`fromDecimalString()` acepta un decimal llano y nada más**: signo negativo opcional, dígitos y como mucho dos decimales. Rechaza **separadores de millar**, y esa es la frontera que importa: `"1.234"` son mil doscientos treinta y cuatro para quien escribe en la convención venezolana y uno con pico en la nuestra, así que aceptarlo obliga a adivinar y adivinar aquí es equivocarse por un factor de mil. Rechaza también un tercer decimal —no es un importe, es un número al que alguien todavía tiene que decidir cómo redondear, y esa decisión no la toma un constructor en silencio sobre el precio de algo—, la notación exponencial, los espacios alrededor y **lo que produce el formateador** (`"$20.00"`, `"Bs. 1.234,56"`): el formateo es para personas y el parseo para datos, y que el ciclo no cierre es intencionado. El paso a céntimos va por `bcmul($amount, '100', 0)`, nunca por `(int) ($amount * 100)`.
- RB-9 **`MoneyCast` es de USD y lanza si le dan otra moneda.** Toda columna `*_cents` guarda centavos de dólar (RB-1), así que un parámetro de moneda en el cast sería la forma más discreta posible de romper la invariante: un `Money` en VES guardado ahí produce una fila **indistinguible de una correcta** —el número cabe, la columna lo acepta— y el error solo aparece cuando alguien suma ese saldo con otro. Los importes declarados en bolívares tienen su sitio propio (`payments.declared_amount`, con su tasa congelada al lado, `REQ-FX-006`) y no pasan por el cast.

**Happy path**
1. `products.price_cents = 2000` se hidrata como `Money(2000, USD)`.
2. `ProductDetailResource` calcula la cuota con `allocateEvenly()` y proyecta a VES con la tasa vigente.
3. La página recibe `{ cents: 2000, usd: "$20.00", ves: "Bs. 850,00" }`.

**Sad paths y edge cases**
- SP-1 `allocateEvenly(0)` → `InvalidArgumentException`.
- SP-2 Importe que no divide exacto (`$100 / 3`) → `[3333, 3333, 3334]` centavos: el residuo se acumula en la **última** parte y la suma es exactamente `10000`.
- SP-3 `subtract` que produce negativo → permitido en `Money` (existe el concepto de crédito), pero rechazado por las reglas de `LAY`/`PAY` que lo usen.
- SP-4 Tasa `0` o negativa → `InvalidRateException` antes de convertir. **Salvo cuando origen y destino son la misma moneda** (RB-5c), donde no se mira la tasa. Se comprueba también la **forma** antes que el valor: `bcmul("cuarenta", …)` es un `ValueError` de PHP cuyo mensaje habla de argumentos y no de tasas, y quien lo lea en un log a las tres de la mañana no sabrá que lo que falla es el parser de la fuente.
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
- SP-2 API que responde `200` con una tasa absurda (`0`, negativa o con variación > 30 % respecto a la última) → se **rechaza** la tasa, se registra una alerta y se conserva la anterior (protección contra datos corruptos del proveedor). **Este caso no se reintenta**: volver a pedir la misma respuesta absurda da la misma respuesta absurda, así que se registra la alerta y el job termina bien. Es lo que lo separa de SP-1, SP-3 y SP-4, donde el fallo es de transporte o de forma y un segundo intento sí puede salir distinto.
- SP-2b **La guarda de la variación exige una referencia de menos de 24 h; sin ella se abstiene y acepta, dejándolo en el log.** Un umbral del 30 % es una velocidad de cambio, y no hay velocidad sin base de tiempo: 30 % en media hora es una fila corrupta del proveedor y 30 % en cuatro meses es el bolívar. Comparar contra «la última fila» sin mirar su antigüedad produce un **cierre permanente**, y el escenario no es rebuscado: si la ingesta o la fuente están caídas una semana y la tasa se mueve de verdad, al volver **toda** lectura se rechaza —y como el rechazo no persiste nada, la referencia no avanza nunca—. La plataforma entera se queda proyectando la tasa de la semana pasada, con un `Log::error` cada media hora que nadie lee y un aviso discreto de obsolescencia que es exactamente el que la gente aprende a ignorar (`REQ-FX-005` RB-3). La abstención se activa justo cuando ya saltó la alarma de plataforma de `REQ-PLT-006` RB-4, que mide sobre las mismas 24 h: mientras haya vigilancia, la guarda protege; cuando la referencia ya es tan vieja que la propia plataforma la declara obsoleta, deja de tener autoridad para vetar la única cifra reciente que existe. Lo realinea **`T-021`**, que es la tarea siguiente del módulo.
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
- Tests: inserta al cambiar y no inserta si es idéntica; rechaza variación > 30 %; rechaza tasa ≤ 0; fallo de API conserva la anterior; `current()` cae a BD sin caché y a `null` sin datos; `WithoutOverlapping` aplicado; **una referencia de más de 24 h no veta una lectura nueva por mucho que se aleje** (SP-2b); **el rechazo por implausibilidad no consume reintentos** (SP-2).

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
- RB-3 `ManualRateProvider` toma su valor de `organization_settings.exchange_rate_manual` de **esa** organización y devuelve `RateSnapshot` con `source = manual` y **`publishedAt` = `fetchedAt` = fecha de la última edición**. En una tasa manual la publicación *es* la edición del `owner`: no hay fuente externa de la que separarla. Poner solo `fetchedAt` dejaría `publishedAt` sin valor y la tasa se vería **siempre obsoleta**, porque `isStale()` mide sobre esa columna — y una tasa que el dueño acaba de fijar a mano avisando de que está desactualizada es el aviso que enseña a ignorar todos los avisos.
- RB-4 Fijar una tasa manual requiere `owner`, se audita en la organización (`REQ-BOF-005`) y **no** inserta fila en `exchange_rates`: la tabla de plataforma solo guarda tasas de fuentes públicas, y la manual de un negocio es un dato suyo, no de la plataforma. Su histórico vive en la auditoría.
- RB-5 El binding del provider se resuelve en un `ServiceProvider` a partir de la config; añadir un proveedor nuevo no exige tocar el resto del sistema.
- RB-5b **El binding de `ExchangeRateProvider` no es singleton, y eso es parte del contrato.** `DolarApiProvider` memoriza la respuesta HTTP para servir las dos fuentes con una sola petición (`REQ-FX-002` RB-0), así que ese memo tiene que morir con la ejecución del job: el worker de colas es un proceso de vida larga y una instancia compartida serviría la respuesta de hace media hora en la vuelta siguiente —para siempre, y sin que nada fallara ni apareciera en ningún log—. Un `->singleton()` escrito por costumbre convierte la optimización que hace posible RB-0 en una tasa congelada. Se sostiene con dos tests que fijan las dos mitades: dos `fetch()` sobre la misma instancia son **una** petición, y dos instancias son **dos**. `ManualRateProvider` sí es singleton: no memoriza nada y lee los ajustes en cada llamada.
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

> **La cookie de la preferencia queda fuera del cifrado de cookies de Laravel** (`T-022`), y es consecuencia de RB-6, no un descuido. Si el cambio de toggle es puramente de cliente, la escribe el navegador y llega sin cifrar: con la exclusión el servidor la lee y el primer render ya es el correcto; sin ella la descarta en cada petición y el render vuelve siempre al default — el parpadeo que RB-4 existe para evitar, pero permanente y silencioso. Lo que guarda es cuál de dos monedas va primero: no identifica a nadie, no autoriza nada, y un valor que no reconocemos cae al default sin error (SP-3). Ninguna otra cookie sale del cifrado por este precedente; lo que lo justifica es que el cliente sea quien la escribe.
>
> **RB-7 y el punto de gate que lo acompaña se cierran en `T-084`, no en `T-022`.** El bus de telemetría —`TelemetryEventName` con sus 15 eventos, el buffer, el endpoint de ingesta y el test por evento— es `REQ-TLM-001…004`, en H8. `T-022` escribe `useCurrencyDisplay` sin emisor y **no** deja un colaborador provisional que se llame `track()` y no haga nada: un emisor que traga eventos en silencio es indistinguible de uno conectado, y ningún test se pondría rojo el día que el bus llegue y nadie lo enchufe. El enchufe se nombra donde sí se ve: `currency_toggled` sale de `useCurrencyDisplay`, y `T-084` lo conecta con el resto de los quince.

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

> **La hora del sello de RB-2 se escribe en la zona del negocio** (`organizations.timezone`), y viaja en las page props junto a la tasa (`T-022`). De las tres candidatas, la del navegador haría que el mismo sello dijera cosas distintas a dos personas mirando el mismo precio —quien lo abra desde Madrid leería «15:00» sobre lo que en Venezuela todo el mundo llama las nueve— y el sello existe justamente para poder cotejar la cifra con lo que publicó el BCV; la de la aplicación funciona hoy solo porque `APP_TIMEZONE` coincide, y deja de funcionar el día que cambie el despliegue, con un desfase que no parece una fecha mal puesta. **Y el patrón `DD/MM HH:mm` se compone explícitamente, no se delega en el *locale***: `es-VE` escribe `18/9` o `18/09` según la versión de ICU del entorno, que es el mismo motivo por el que `MoneyFormatter` no usa `NumberFormatter` (`REQ-FX-001` RB-7).

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
- Campos en `payments`: `amount_cents_usd`, `declared_currency`, `declared_amount`, `rate_applied DECIMAL(18,8)`, `rate_source`, `rate_published_at`, `settled_at`.
- `RateSnapshot` como parámetro obligatorio de `ApproveReceiptAction` y `RegisterManualPaymentAction`.

> **La columna es `rate_published_at`, no `rate_fetched_at`.** Es la misma corrección que `T-019` trajo a `RateView` y que ya estaba aplicada en [05 §4](05-design-tecnico.md) mientras este módulo seguía diciendo lo otro. `02` queda alineado aquí; en `01` se deja como está, porque es el registro de lo que se decidió el 10 de septiembre y lo que allí se decidió —que el pago congela su tasa— sigue en pie: lo que cambia es qué fecha de las dos se congela. Congelar la hora de **nuestra ingesta** dentro de un pago sería guardar un dato de diagnóstico nuestro en el sitio donde el negocio va a leer «con qué tasa se concilió este pago»: el historial de RB-5, la exportación de RB-6 y la confirmación de SP-5 —tasa de más de 30 días— hablan todos de **cuándo se publicó** la tasa, no de a qué hora corrió el job. Un fin de semana las dos fechas se separan tres días, y la que explica el importe es la de publicación. **Lo aplica la tarea que cree la tabla**, que es `T-054`.

**Reglas de negocio y precondiciones**
- RB-1 **La deuda es siempre en USD** (`ASU-FX-04`). El cronograma, el total y el saldo se expresan exclusivamente en centavos de USD.
- RB-2 Un pago declarado en VES se convierte a USD con la tasa **vigente el día de la conciliación**, y el resultado se congela en `payments`.
- RB-3 `rate_applied`, `rate_source` y `rate_published_at` son **inmutables**: no existe operación que los actualice. Corregir un pago exige anularlo y crear otro (fuera de alcance en v1: se resuelve con `REQ-PAY-009` reasignando, o cancelando el apartado).
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
