# Módulo 50 — Planes, límites y facturación de suscripciones (`SUB`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Decisiones: `Q3`, `Q6`, `Q7` de [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md)
> Requisitos: `REQ-SUB-001` … `REQ-SUB-008`

## Contratos compartidos del módulo

```php
enum UsageMetric: string
{
    case Users = 'users';
    case Catalogs = 'catalogs';
    case Products = 'products';
    case ActiveLayaways = 'active_layaways';
    case StorageMb = 'storage_mb';
    case PaymentAccounts = 'payment_accounts';

    /** Columna del plan que declara el techo de esta métrica. */
    public function planColumn(): string { /* … */ }
    /** true si el valor se recalcula por consulta agregada en lugar de por contador incremental. */
    public function isDerived(): bool { /* … */ }
}

enum PlanFeature: string
{
    case Branding = 'branding';
    case ExcelExport = 'excel_export';
    case WebPush = 'web_push';
    case SubscriberDigest = 'subscriber_digest';
    case FullAnalytics = 'full_analytics';
    case CustomDomain = 'custom_domain';
}

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';     // factura vencida, dentro de la gracia
    case Grace = 'grace';          // bajada de plan pendiente de aplicar
    case Suspended = 'suspended';  // gracia agotada
    case Cancelled = 'cancelled';

    public function allowsTeamAccess(): bool { /* … */ }
    public function allowsCreation(): bool { /* … */ }
}

/**
 * Único punto de decisión sobre si una organización puede crear algo más
 * o usar una función. Ninguna Action comprueba límites por su cuenta.
 */
final class LimitEnforcer
{
    /** @throws PlanLimitReachedException */
    public function assertCanCreate(UsageMetric $metric, int $amount = 1): void { /* … */ }

    /** @throws PlanFeatureUnavailableException */
    public function assertHasFeature(PlanFeature $feature): void { /* … */ }

    public function remaining(UsageMetric $metric): ?int { /* … */ }
    public function hasFeature(PlanFeature $feature): bool { /* … */ }
}
```

```ts
export interface PlanState {
  readonly planCode: 'free' | 'pro' | 'business';
  readonly planName: string;
  readonly status: 'active' | 'past_due' | 'grace' | 'suspended' | 'cancelled';
  readonly currentPeriodEnd: string | null;
  readonly graceEndsAt: string | null;
  readonly usage: Readonly<Record<UsageMetric, UsageState>>;
  readonly features: readonly PlanFeature[];
}

export interface UsageState {
  readonly used: number;
  /** null = ilimitado. */
  readonly limit: number | null;
  readonly isAtLimit: boolean;
  readonly percentage: number;
}

export interface InvoiceRow {
  readonly id: number;
  readonly periodStart: string;
  readonly periodEnd: string;
  readonly amount: MoneyView;
  readonly status: 'pending' | 'under_review' | 'paid' | 'overdue' | 'void';
  readonly dueDate: string;
  readonly receiptStatus: 'none' | 'pending_review' | 'rejected';
}
```

---

### REQ-SUB-001 · Catálogo de planes

**Historia de usuario**
> Como negocio interesado, quiero ver con claridad qué incluye cada plan y cuánto cuesta, para decidir si me sirve antes de registrarme.

**Contratos de datos**
- Entidad `Plan` (`plans`): `code`, `name`, `price_cents`, `interval`, `is_public`, `position`, las siete columnas de límite y `features JSON`.
- Rutas `GET /precios` (público) y `GET /backoffice/suscripcion`.
- Seeder `PlanSeeder` con los tres planes de la matriz aprobada.

**Reglas de negocio y precondiciones**
- RB-1 Los planes son **datos, no código**: viven en `plans` y se pueden ajustar sin desplegar. El código nunca compara contra `'pro'` para decidir un límite; consulta el plan.
- RB-2 Límites como columnas explícitas (`max_users`, `max_catalogs`, `max_products`, `max_products_per_catalog`, `max_active_layaways`, `max_storage_mb`, `max_payment_accounts`) y funciones booleanas en `features JSON`. `NULL` en una columna de límite significa **ilimitado**.
- RB-3 Matriz inicial aprobada (§5 de [03](03-pivote-saas-impacto.md)):
  | | Free | Pro | Business |
  |---|---|---|---|
  | `max_users` | 1 | 5 | 15 |
  | `max_catalogs` | 1 | 10 | 50 |
  | `max_products` | 30 | 300 | 2.000 |
  | `max_products_per_catalog` | 20 | 100 | 500 |
  | `max_active_layaways` | 15 | 200 | 2.000 |
  | `max_storage_mb` | 200 | 5.120 | 25.600 |
  | `max_payment_accounts` | 1 | 5 | 15 |
  | `features` | — | branding, excel, push, digest, analytics | + dominio propio |
- RB-4 **Los suscriptores de catálogo no tienen límite en ningún plan** (`REQ-ACC-002` RB-9). No es una métrica de consumo.
- RB-5 Free **incluye el sistema de apartado**, con tope de 15 activos: sin apartados la capa gratuita no demostraría el valor del producto.
- RB-6 `is_public` permite planes ocultos (precios negociados) sin exponerlos en `/precios`.
- RB-7 Cambiar el precio de un plan **no** altera las suscripciones vigentes hasta su siguiente período: la factura ya emitida es inmutable (`REQ-SUB-006` RB-3).
- RB-3b **Los precios de Pro y Business están sin decidir.** Ni §5 de [03](03-pivote-saas-impacto.md) ni RB-3 fijan cifra, y `T-011` sembró los tres planes a `0` en lugar de inventarla. No bloquea nada hasta `REQ-SUB-006` (emisión de facturas, H5); fijarlo entonces es un `UPDATE` sobre `plans`, coherente con RB-1, y no altera facturas ya emitidas (RB-7).
- RB-8 La página de precios es la misma fuente de verdad que el backoffice: se renderiza desde `plans`, sin tabla escrita a mano en el frontend.

**Happy path**
1. Un prospecto abre `/precios` y compara los tres planes con sus límites reales leídos de la base de datos.
2. Pulsa "Empezar gratis" y llega al registro (`REQ-ORG-001`).

**Sad paths y edge cases**
- SP-1 Plan sin `features` definidas → se interpreta como sin funciones de pago, no como error.
- SP-2 Columna de límite en `NULL` → ilimitado, y la UI lo muestra como "ilimitado", no como "0".
- SP-3 Plan despublicado (`is_public = false`) con organizaciones dentro → siguen en él con normalidad; solo desaparece de `/precios`.
- SP-4 Precio cambiado → afecta a las facturas del siguiente período, nunca a las emitidas.
- SP-5 Seeder ejecutado dos veces → idempotente por `UNIQUE(code)`.

**Fuera de alcance inmediato**
- Planes anuales con descuento, cupones y precios promocionales.
- Planes por país o por moneda.
- Add-ons por métrica (comprar 100 productos extra).

**Gate de validación**
- Superficie esperada: `app/Models/Plan.php`, `app/Domain/Subscription/PlanFeature.php`, `app/Domain/Subscription/UsageMetric.php`, `database/seeders/PlanSeeder.php`, `app/Http/Controllers/Marketing/PricingController.php`.
- Tipos esperados: `Plan`, `PlanFeature`, `UsageMetric`, `PlanState`.
- Anti-`SHALLOW`: `UsageMetric::planColumn()` e `isDerived()` (mapas completos, > 4 líneas) junto al enum; `Plan::limitFor(UsageMetric)` y `Plan::hasFeature(PlanFeature)` junto al modelo.
- Tests: el seeder crea los tres planes con la matriz exacta de RB-3; `NULL` se interpreta como ilimitado; `/precios` se renderiza desde la BD; cambiar el precio no toca facturas emitidas; seeder idempotente.

---

### REQ-SUB-002 · Alta automática en el plan gratuito

**Historia de usuario**
> Como negocio que se registra, quiero poder usar la herramienta de inmediato sin dar datos de pago, para probarla con mi inventario real.

**Contratos de datos**
- `StartFreeSubscriptionAction(Organization $organization): Subscription`, invocada dentro de `RegisterOrganizationAction` (`REQ-ORG-001` RB-1).
- Entidad `Subscription` (`subscriptions`): `organization_id` (`UNIQUE`), `plan_id`, `status`, `started_at`, `current_period_start`, `current_period_end`, `grace_ends_at?`, `cancelled_at?`.

**Reglas de negocio y precondiciones**
- RB-1 Toda organización tiene **exactamente una** suscripción (`UNIQUE(organization_id)`), desde el instante del registro.
- RB-2 El plan inicial es **siempre Free** y su suscripción nace `active`, **sin factura** y **sin fecha de fin**: `current_period_end` es `null` en Free.
- RB-3 **No hay prueba gratuita de plan superior.** La capa gratuita es la prueba, y así se evita el acantilado de la expiración: nadie pierde acceso a su inventario por terminar un trial.
- RB-4 No se piden datos de pago ni RIF para el alta.
- RB-5 Al crearse, se inicializan los contadores de consumo ~~en cero~~ con lo que la organización consume en ese instante: el `owner` ya cuenta en `users`, y lo demás es cero (`REQ-SUB-003`). *(Corregido en `T-066`: ver la nota de `REQ-SUB-003`.)*
- RB-6 Una organización nunca puede quedarse sin suscripción: cancelar un plan pago **degrada a Free**, no deja a la organización sin plan (`REQ-SUB-005` RB-4).

**Happy path**
1. Se completa el registro; en la misma transacción nace la suscripción Free `active`.
2. El backoffice muestra "Plan Free · 0 de 30 productos · 0 de 1 catálogo".

**Sad paths y edge cases**
- SP-1 Plan Free ausente en `plans` (base mal sembrada) → el registro **falla** con un error explícito en lugar de crear una organización sin plan.
- SP-2 Intento de crear una segunda suscripción → violación de `UNIQUE`, convertida en excepción de dominio.
- SP-3 Registro con rollback → no queda suscripción huérfana.

**Fuera de alcance inmediato**
- Prueba gratuita de Pro.
- Alta directa en plan pago desde el registro.

**Gate de validación**
- Superficie esperada: `app/Actions/Subscription/StartFreeSubscriptionAction.php`, `app/Models/Subscription.php`, `app/Domain/Subscription/SubscriptionStatus.php`.
- Tipos esperados: `Subscription`, `SubscriptionStatus`, `StartFreeSubscriptionAction`, `MissingFreePlanException`.
- Anti-`SHALLOW`: `StartFreeSubscriptionAction::handle()` (resolución del plan Free, creación, inicialización de contadores) supera 4 líneas; `SubscriptionStatus::allowsTeamAccess()`/`allowsCreation()` junto al enum.
- Tests: el registro crea la suscripción Free `active` sin factura; `current_period_end` nulo en Free; segunda suscripción rechazada; sin plan Free en BD el registro falla; contadores ~~en cero~~ con lo medido —`users` en 1— (RB-5 corregido).

---

### REQ-SUB-003 · Medición del consumo

**Historia de usuario**
> Como dueño, quiero ver en todo momento cuánto he consumido de mi plan, para saber cuándo me conviene subir antes de chocar con un límite.

**Contratos de datos**
- Entidad `UsageCounter` (`usage_counters`): `organization_id`, `metric`, `value`, `computed_at`, `UNIQUE(organization_id, metric)`.
- `UsageCalculator::recalculate(Organization $org, ?UsageMetric $metric = null): void`.
- Comando `subscriptions:recalculate-usage`, job diario, más recálculo puntual tras cada mutación relevante.

**Reglas de negocio y precondiciones**
- RB-1 El consumo es **materializado** en `usage_counters`: comprobar un límite no puede costar seis consultas agregadas en cada creación.
- RB-2 Definición exacta de cada métrica, y esto es normativo:
  | Métrica | Qué cuenta |
  |---|---|
  | `users` | Usuarios **activos** (`disabled_at` nulo) **más invitaciones pendientes** (`REQ-ORG-004` RB-2) |
  | `catalogs` | Catálogos **publicados** (`Listed` o `Unlisted`); los borradores no cuentan |
  | `products` | Productos no archivados y no borrados lógicamente |
  | `active_layaways` | Apartados en `pending_down_payment`, `active` u `overdue` |
  | `storage_mb` | Suma de `file_size` de comprobantes + medios de producto + exportaciones vigentes |
  | `payment_accounts` | Cuentas de cobro **activas** |
- RB-3 El contador se actualiza **dentro de la misma transacción** de la mutación que lo altera (crear producto, publicar catálogo, activar apartado…), nunca de forma diferida. La excepción es `storage_mb` (RB-5): se vuelve a sumar **en cuanto se confirma** lo que cambió el archivo —subida, recompresión, borrado—, en una transacción propia. *(Corregido en `T-066`: ver la nota de abajo.)*
- RB-4 Un job diario **recalcula desde cero** todas las métricas de todas las organizaciones y corrige derivas, igual que `layaways:reconcile` hace con los balances (`REQ-PAY-008`). Y uno **cada hora** recalcula `catalogs`, que baja sola cuando un catálogo caduca. *(Ampliado en `T-066`: ver la nota de abajo.)*
- RB-5 `storage_mb` es la única métrica **derivada** (`isDerived() === true`): se recalcula por agregación y no por incremento, porque las conversiones de imagen y las purgas la mueven de forma asíncrona.
- RB-6 El consumo se expone en las page props del backoffice (`PlanState.usage`) para que la UI pueda avisar **antes** de que el usuario choque con el límite.
- RB-7 A partir del **80 %** de una métrica, el backoffice muestra un aviso no bloqueante con el plan que lo desbloquea.
- RB-8 Un contador **nunca queda negativo**: el recálculo lo acota a cero y registra la anomalía.

> *(Histórico: **RB-1 rige desde `T-066`**; ver «Cómo quedó» bajo el gate.)* **RB-1 no rige todavía, y `T-066` es quien lo pone en pie.** `T-013` construyó `LimitEnforcer` con la firma definitiva, pero midiendo la métrica de su propia tabla —tanto para decidir como para pintar— y dejando escrito lo medido, en lugar de leer el valor materializado. El motivo es de hoy y no de siempre: quien defiende ese valor frente a la deriva es el recálculo diario de RB-4, que llega con `T-066`, y las once llamadas restantes de `REQ-SUB-004` RB-3, que llegan con `T-067`. Hasta entonces el contador solo lo mueve el equipo, así que arrastra lo que nadie cuadra —el propio registro de una organización deja los seis en cero con el `owner` ya creado—. Hacer cumplir un techo contra una cifra que nadie mantiene es hacer cumplir una ficción, y pintarla junto a la lista que la contradice es peor: nadie vuelve a creerse ese número. **Cuando `T-066` mantenga los contadores, el valor materializado pasa a ser el camino único y la medida se muda a `UsageCalculator`**: el `match` de `LimitEnforcer::measure()` es literalmente lo que `T-066` amplía y después traslada. Lo que no cambia en ningún momento es el bloqueo: lo que serializa dos creaciones rivales es la fila de `usage_counters` cogida con `FOR UPDATE`, no el conteo.

**Happy path**
1. El negocio crea su producto número 24 de 30; el contador sube en la misma transacción.
2. Al pasar de 24 sube el aviso del 80 % con el enlace a Suscripción.
3. El job nocturno recalcula y confirma que el valor materializado coincide con el real.

**Sad paths y edge cases**
- SP-1 Deriva detectada por el job → se corrige y se registra; los datos de negocio no se tocan.
- SP-2 Producto archivado → baja `products`; el apartado que lo referencia no se altera (`REQ-LAY-002`).
- SP-3 Apartado completado o cancelado → baja `active_layaways`, liberando cupo. Es la métrica que "respira".
- SP-4 Comprobante subido → sube `storage_mb`; recompresión posterior lo baja (`REQ-PAY-002` RB-6), y el job lo cuadra.
- SP-5 Invitación revocada → baja `users` (`REQ-ORG-004` RB-6).
- SP-6 Catálogo despublicado → baja `catalogs`; volver a publicarlo vuelve a comprobar el límite.
- SP-7 Recálculo sobre 10.000 organizaciones → `chunkById` con una consulta agregada por métrica y por lote, sin `N+1`.
- SP-8 Contador negativo por un bug → acotado a cero con registro (RB-8).

**Fuera de alcance inmediato**
- Histórico de consumo y gráficas de evolución.
- Alertas por email al acercarse al límite.
- Consumo por usuario del equipo.

**Gate de validación**
- Superficie esperada: `app/Domain/Subscription/UsageCalculator.php`, `app/Models/UsageCounter.php`, `app/Jobs/RecalculateUsage.php`, `app/Console/Commands/RecalculateUsageCommand.php`.
- Tipos esperados: `UsageCounter`, `UsageMetric`, `UsageCalculator`, `UsageState`.
- Anti-`SHALLOW`: ~~`UsageCalculator::recalculate()`~~ `UsageCalculator::recalculateMany()` (las seis definiciones de RB-2 en consultas agregadas, acotado a cero, marca de tiempo) supera 4 líneas junto a su clase; las definiciones, en `UsageAggregates::measure()`. *(Corregido en `T-066`: `recalculate()` conserva la firma del contrato y delega en la versión por lotes.)*
- Tests: una prueba por métrica con la definición exacta de RB-2; el contador se mueve en la misma transacción; el job corrige una deriva inyectada; completar un apartado libera cupo; invitación pendiente cuenta y revocada libera; contador nunca negativo; sin `N+1` en el recálculo masivo.

> **Cómo quedó, en `T-066`.** **El contador materializado es el camino único** (RB-1): `LimitEnforcer` ya no cuenta nada, ni para decidir (`assertCanCreate()`, con la fila bloqueada) ni para pintar (`state()`, `usageOf()`); `measure()` y `synchronise()` desaparecieron. **Las seis definiciones de RB-2 viven en un solo sitio**, `App\Queries\Platform\UsageAggregates`, como consultas agregadas por lote de organizaciones (`whereIn` + `groupBy`); los estados que cuentan los responde el propio tipo —`ProductStatus::countsTowardPlanLimit()`, `CatalogVisibility::countsTowardPlanLimit()`, `LayawayStatus::countsTowardPlanLimit()`—, y la visibilidad del catálogo, que no tiene columna, se reescribe en SQL con un test que compara las dos lecturas. `UsageCalculator` (`recalculate()`, `refresh()`, `measure()`, `recalculateMany()`) es quien mide y escribe.
>
> - **El orden de bloqueo es parte del contrato.** En `users`, **el contador primero** y después las filas, en todas las mutaciones (`LimitEnforcer::lockCounter()`): desactivar coge además las filas de los `owner` y su hueco en el índice, que es donde invitar inserta, y con el orden al revés las dos se esperaban en cruz (lo cazó la revisión; el ciclo existía desde antes de esta tarea). En `catalogs`, también el contador primero (ver abajo). En `products` y `active_layaways`, `ProductLock` primero —es la regla de `REQ-LAY-003` RB-8— y el contador después. En `payment_accounts`, la cuenta y después el contador.
> - **RB-3 · las cinco métricas exactas se incrementan, no se miden.** Cada Action suma o resta con `LimitEnforcer::adjust()` la diferencia que **su** escritura produjo, calculada sobre la fila de lo que cambia **releída con bloqueo**: invitar `+1`, revocar `-1`, desactivar `-1` y reactivar `+1` (`users`; también el borrado de la propia cuenta desde Ajustes); crear y cambiar de estado una cuenta de cobro; crear un producto `+1` y archivar/restaurar `∓1` (publicar y despublicar no mueven nada: el borrador ya cuenta); publicar un borrador o un caducado `+1` y despublicar uno vigente `-1` (`catalogs`). **Por qué no se mide dentro de la transacción de la Action:** en `REPEATABLE READ` la instantánea se toma en la primera lectura sin bloqueo, que en una Action ya ocurrió, y dos invitaciones rivales escribirían las dos «2» en lugar de «3». El incremento con la fila bloqueada lee lo último confirmado; y releer la fila de lo que cambia con bloqueo es lo que impide que dos bajas simultáneas del mismo miembro resten dos veces (`UsageCounterConcurrencyTest`).
> - **RB-3, RB-5 · `storage_mb` se vuelve a sumar justo después del `COMMIT`** (`UsageCalculator::refresh()`): al subir un comprobante (`SubmitReceiptAction`, `afterCommit`), al recomprimirlo (`CompressReceiptImage`) y al subir o borrar imágenes de producto (`ProductImageController`). En una transacción propia porque medir **sí** necesita ver lo confirmado por otros. Un fallo al medir se informa y no deshace nada —tampoco convierte en `500` una subida que sí ocurrió—: la noche lo cuadra. En la subida de imágenes va en un `finally`: si la séptima falla, las seis primeras ya pesan. **El comprobante de cliente se cuenta y nunca se rechaza** (`REQ-PAY-001` RB-10): `ReceiptCreateTest` lo afirma con un plan de 1 MB y 5 MB ya ocupados, y el contador queda en 6.
> - **Cómo se mide el almacenamiento:** `SUM(receipts.file_size)` + `SUM(media.size)` de los productos de la organización —también los borrados lógicamente, cuyos archivos siguen en el disco—, en bytes, y **redondeado hacia arriba a MB enteros (MiB) una sola vez, al final, por organización**: 1.000,4 MB no caben en un plan de 1.000, y cien comprobantes de 30 KB no son cien megas. `media` no tiene `organization_id`: se une con `products` por `model_type`/`model_id`. **No cuentan las conversiones WebP** (no tienen tamaño en la base, y son una decisión de la plataforma para servir más rápido); **las exportaciones vigentes las añade `T-070`** con su tabla.
> - **RB-4, SP-1, SP-7 · el recálculo**: `subscriptions:recalculate-usage [--organization=] [--metric=]` y el job `RecalculateUsage` (cola `telemetry`, diario a las 02:00 y `catalogs` cada hora a y cuarto). `chunkById` de 200 organizaciones —también las cerradas— y, por lote, **una consulta agregada por métrica** (siete: el almacenamiento son dos) —los tests comparan las consultas de tres organizaciones y de nueve, en el cálculo y en el job—. El candado de la pasada lleva el alcance **y la organización**: una pasada del soporte sobre un negocio no salta la de las 02:00. En su propia transacción, **primero bloquea los contadores del lote y después mide**: la lectura con bloqueo no abre la instantánea, así que la medida ve todo lo que confirmaron quienes tenían la fila, y ninguna mutación puede moverla entre la medida y la escritura. Corrige lo que no cuadra, sella `computed_at` en lo que sí, crea el contador que falte ya medido y registra cada deriva como `usage_counter.drift` con lo que decía y lo que mide. Los datos de negocio no se tocan. El comando **siempre corrige** —a diferencia de `layaways:reconcile`—: el contador es una cifra derivada.
> - **Los catálogos caducan solos** (`UsageMetric::shrinksWithTime()`): un catálogo que caduca a su hora no escribe nada, así que el contador de `catalogs` **puede estar por encima** de lo medido hasta una hora —el recálculo horario lo baja—, y eso no se registra como deriva; por debajo, sí. **Para decidir no espera a esa hora:** publicar y despublicar **empiezan** por `UsageCalculator::recountLocked()`, que coge el contador y lo deja exacto con una lectura con bloqueo compartido (lee lo último confirmado, no la instantánea), y después se mueven con `adjust()`. Sin esto, republicar una campaña caducada antes de la pasada horaria la contaba dos veces, y con el cupo de Free (uno) `T-067` habría respondido `422` a quien no tiene ningún catálogo vivo.
> - **RB-8, SP-8 · nunca negativo**: `adjust()` acota a cero y registra `usage_counter.below_zero` (`FailsOnCounterDrift` hace fallar el test que lo provoque); el recálculo escribe `max(0, medido)`.
> - **`REQ-SUB-002` RB-5, corregido:** el alta inicializa cada contador con `UsageCalculator::measure()` dentro de su transacción, así que una organización recién registrada nace con `users = 1` y no contradice a su propia pantalla de Equipo.
> - **Lo que queda:** la **comprobación** de cada cupo antes de crear —las doce llamadas a `assertCanCreate()` de `REQ-SUB-004` RB-3— es de **`T-067`**; el brazo de las exportaciones en `storage_mb`, de **`T-070`**.

---

### REQ-SUB-004 · Aplicación de límites y funciones de plan

**Historia de usuario**
> Como dueño en plan gratuito, quiero que cuando llegue a un límite se me diga con claridad qué pasó y qué plan lo resuelve, en lugar de encontrarme un error incomprensible.

**Contratos de datos**
- `LimitEnforcer` (ver contratos compartidos), inyectado en las `Action` que crean recursos limitados.
- Middleware `EnforcePlanLimit` para las rutas de creación.
- Componentes `<LimitBanner />` y `<UpgradePrompt />`.

**Reglas de negocio y precondiciones**
- RB-1 `LimitEnforcer` es el **único punto de decisión** sobre límites y funciones. Ninguna `Action` compara contadores por su cuenta, y el frontend nunca decide: solo refleja.
- RB-2 La comprobación ocurre **en la `Action`**, dentro de la transacción y **antes** de crear, con el contador bloqueado para lectura, de modo que dos creaciones simultáneas no puedan superar el techo.
- RB-3 Puntos exactos de aplicación:
  | Acción | Métrica o función |
  |---|---|
  | Invitar o reactivar miembro | `users` |
  | Publicar catálogo | `catalogs` |
  | Crear producto | `products` |
  | Añadir producto a un catálogo | `max_products_per_catalog` |
  | Activar apartado | `active_layaways` |
  | Subir imagen o comprobante | `storage_mb` |
  | Crear cuenta de cobro | `payment_accounts` |
  | Aplicar logo y color | `branding` |
  | Exportar a Excel | `excel_export` |
  | Enviar Web Push a clientes | `web_push` |
  | Avisar a suscriptores | `subscriber_digest` |
  | Analítica completa | `full_analytics` |
- RB-4 **`active_layaways` es el único límite que puede afectar al cliente final**, y por eso su tratamiento es especial: cuando se alcanza, el formulario de apartado del catálogo **se deshabilita con un mensaje neutro** ("este negocio no puede recibir nuevos apartados en este momento"), sin exponer que el negocio está en un plan gratuito ni humillarlo ante su cliente. Al negocio, en cambio, se le dice exactamente qué pasó.
- RB-5 Toda excepción de límite lleva la métrica, el valor actual, el techo y el **plan mínimo** que lo resuelve, para que la UI pueda construir un mensaje útil sin lógica propia.
- RB-6 Superar un límite **nunca** afecta a lo ya creado: solo bloquea crear más (`REQ-SUB-008`).
- RB-7 Una función de plan ausente se comporta como "no disponible", no como error: el control se muestra deshabilitado con el motivo, no oculto. El usuario debe poder **ver** lo que se está perdiendo.
- RB-8 Cuando la suscripción está `suspended`, `allowsCreation()` es falso para **todo**, con independencia de los contadores (`REQ-SUB-007`).

**Happy path**
1. El negocio en Free intenta publicar su segundo catálogo.
2. `LimitEnforcer::assertCanCreate(Catalogs)` lanza `PlanLimitReachedException` con `used: 1, limit: 1, minimumPlan: 'pro'`.
3. La UI muestra "Tu plan permite 1 catálogo publicado. Pro permite 10." con el botón para cambiar de plan.

**Sad paths y edge cases**
- SP-1 Dos publicaciones simultáneas con un cupo disponible → el bloqueo del contador serializa: una pasa y la otra recibe la excepción.
- SP-2 Cliente final apartando cuando el negocio agotó `active_layaways` → mensaje neutro (RB-4), y el negocio recibe una alerta en su dashboard de que está perdiendo ventas por el límite. Ese es el aviso que mejor convierte, y es honesto: se le dice cuando de verdad le está costando dinero.
- SP-3 Función de pago invocada por API directa en Free → `403` con el motivo, no `404`.
- SP-4 Bajada de plan que deja el consumo por encima del techo → no se bloquea la lectura ni se destruye nada; solo se bloquea crear (`REQ-SUB-008`).
- SP-5 Límite `NULL` (ilimitado) → `assertCanCreate` siempre pasa y `remaining()` devuelve `null`.
- SP-6 Suscripción `suspended` → todo bloqueado salvo lo que `REQ-ORG-008` RB-3 permite explícitamente (aprobar comprobantes y leer cobranza).
- SP-7 Frontend que oculta el botón pero el usuario llama al endpoint → la comprobación de servidor es la autoritativa (RB-1).

**Fuera de alcance inmediato**
- Superación temporal del límite con cargo adicional.
- Límites por usuario o por catálogo definidos por el negocio.
- Autoescalado de plan.

**Gate de validación**
- Superficie esperada: `app/Domain/Subscription/LimitEnforcer.php`, `app/Http/Middleware/EnforcePlanLimit.php`, `resources/js/features/plan/components/LimitBanner.tsx`, `resources/js/features/plan/components/UpgradePrompt.tsx`, `resources/js/features/plan/hooks/usePlanLimits.ts`.
- Tipos esperados: `LimitEnforcer`, `PlanLimitReachedException`, `PlanFeatureUnavailableException`, `PlanState`, `UsageState`.
- Anti-`SHALLOW`: `LimitEnforcer::assertCanCreate()` (lectura bloqueada del contador, comparación, resolución del plan mínimo, excepción enriquecida) y `assertHasFeature()` superan 4 líneas junto a sus tipos.
- Tests: un test por punto de aplicación de RB-3 (doce); concurrencia con un cupo disponible; el mensaje al cliente final no revela el plan del negocio (test explícito de RB-4); límite `NULL` no bloquea; `suspended` bloquea todo; la función de pago da `403` por API; el negocio recibe la alerta de ventas perdidas.

> **Cómo quedó, en `T-067`.** **Los doce puntos de RB-3**, con su test cada uno (el mapa está en el docblock de `PlanLimitEnforcementTest`):
>
> - **Cupos, en la Action y dentro de su transacción** (RB-2), con `assertCanCreate()` y el contador bloqueado **antes de escribir nada**: invitar y reactivar (ya estaban); publicar, solo cuando el catálogo no contaba —cambiar la fecha o la vitrina de uno publicado no ocupa nada—, con el contador recién recontado (`recountLocked()`, `T-066`); crear producto, **y restaurar un archivado**, que vuelve a ocupar sitio; activar apartado (ya estaba); subir imagen, **archivo a archivo**, con el contador de `storage_mb` cogido y **recontado** antes de comprobar y otra vez antes de soltarlo —el almacenamiento no se incrementa, y dos subidas que leyeran el contador sin recontar pasarían las dos con un hueco— y con los MB que el archivo **añade al total**, redondeando una vez (`UsageCalculator::megabytesAdded()`); con la cuota agotada en el séptimo, los seis primeros se quedan; crear o reactivar cuenta de cobro (ya estaba). **El comprobante de cliente nunca se comprueba** (`REQ-PAY-001` RB-10).
> - **`max_products_per_catalog`, aparte**: no es una `UsageMetric` (se cuenta por catálogo), así que lo decide `LimitEnforcer::assertCatalogHolds()` —el único punto de decisión sigue siendo el mismo (RB-1)— con la fila del catálogo bloqueada en `SyncCatalogProductsAction`, y lanza `CatalogProductLimitReachedException`, con el mismo cuerpo que las demás y la clave `products_per_catalog`. **Solo rechaza lo que entra** (RB-6, `REQ-SUB-008`): un catálogo por encima del techo tras bajar de plan se reordena y se vacía, pero no recibe productos nuevos, ni cambiando uno por otro. El cuerpo lleva `requested` y `excess`, el exceso exacto de `REQ-CAT-023` SP-3.
> - **Funciones, en la ruta**: `EnforcePlanLimit` (alias `plan-feature:<clave>`) llama a `assertHasFeature()` y responde **`403` con el motivo y el plan que la incluye** (SP-3); una clave mal escrita revienta en lugar de dejar la ruta abierta. **El middleware es solo para funciones**: un cupo comprobado ahí sería una segunda lectura sin bloqueo. Las cuatro de RB-3 sin código —exportar, Web Push, avisar a suscriptores, analítica completa— se prueban con rutas de atrezo; sus rutas de verdad lo declaran al llegar (`T-070`, `T-083`, `T-078`, `T-085`). **El branding no se bloquea al guardar** (`REQ-ORG-006` SP-3): se guarda siempre y se aplica según el plan, como ya hacía.
> - **RB-5 · las excepciones llevan todo**: métrica, consumo, techo, plan mínimo y, nuevo, la **salida sin cambiar de plan** (`UsageMetric::freeUpHint()`: «despublica otro catálogo», «archiva un producto»… — es el «sugerir despublicar otro» de `REQ-BOF-024` SP-3). `PlanFeature::label()` da el nombre de cada función, y lo usan `/precios`, el `403` y la interfaz.
> - **Cómo se responde.** A quien pide JSON, `422`/`403` con el cuerpo (`errors.plan`, `limit` o `feature`, `upgradeUrl`). A un formulario de Inertia —y a una visita de Inertia a la página de una función que falta—, **de vuelta** con el mensaje en `errors.plan` y el cuerpo en la sesión (`planLimit`, `planFeature`, `planBlocked`), que pinta `UpgradePrompt` desde el layout: antes era siempre JSON, con el error bajo `email` fuese cual fuese el formulario, y una visita de Inertia lo habría pintado como página de error.
> - **RB-4 · el cliente final no sabe nada del plan**: la ficha pública pregunta a `LimitEnforcer::acceptsLayaways()` —cupo de apartados, suscripción y organización— y, si no, `UnavailableReason::BusinessUnavailable` con su mensaje neutro y el formulario apagado; un test recorre la ficha entera buscando «plan», «límite», «suscrip» y «free». Lo que autoriza sigue siendo `assertCanCreate()` dentro de `CreateLayawayAction`.
> - **SP-2 · la alerta de ventas perdidas**: cada apartado que el límite rechaza deja una fila en `plan_limit_rejections` —métrica y fecha, **nada del cliente**—, escrita **después** de deshacer la transacción del intento; **y también la ficha pública que ya no ofrece apartar porque el cupo está lleno** (`LimitEnforcer::offersLayaways()`), como mucho una por hora y métrica: sin esto, con el formulario apagado nadie llegaba a enviar el intento y el aviso no salía justo cuando más se pierde; el panel avisa (`plan_limit_blocking_sales`) solo si hay una de los últimos 7 días, uno por métrica. Una suscripción suspendida también rechaza, pero no anota venta perdida: subir de plan no la arregla.
> - **SP-1 · concurrencia con un cupo**: `PublishCatalogConcurrencyTest`, con dos conexiones, además del de invitar (`LimitEnforcerConcurrencyTest`).
> - **La interfaz refleja, no decide** (RB-1, RB-7): `HandleInertiaRequests` comparte —**solo con el equipo de la propia organización**; alguien del equipo de otro negocio que mire su catálogo público no recibe nada— `planState` (`LimitEnforcer::state()`, ahora con los seis contadores en una consulta) con lo que la UI necesita ya resuelto: el plan que da más sitio a cada métrica a partir del 80 %, el más barato con cada función que falta y los nombres de métricas y funciones. `usePlanLimits` lo lee; `LimitBanner` avisa a partir del 80 % en productos (y almacenamiento), catálogos, equipo, cuentas de cobro y, en el panel, apartados activos; el panel de publicación enseña el cupo antes de pulsar (`blockersFor()`); y a quien no puede abrir Suscripción, en lugar de «Ver planes» se le dice que se lo pida al dueño; `UpgradePrompt` enseña la función que falta —**a la vista, no oculta**— y el último rechazo. `GET /backoffice/suscripcion` existe (solo lectura, sobrevive a la suspensión); cambiar de plan es de `T-068`.

---

### REQ-SUB-005 · Cambio de plan

**Historia de usuario**
> Como dueño, quiero subir de plan cuando el negocio crece y bajar si me equivoqué, sin perder nada de lo que ya cargué.

**Contratos de datos**
- ~~`ChangePlanAction(Organization $org, Plan $target, User $actor): Subscription`.~~ `ChangePlanAction::handle(Organization $org, Plan $target, User $actor, bool $confirmed = false): Subscription`: `$confirmed` es la confirmación explícita de SP-2. *(Corregido en `T-068`: ver la nota de abajo.)*
- Rutas `GET /backoffice/suscripcion`, `POST /backoffice/suscripcion/cambiar`.

**Reglas de negocio y precondiciones**
- RB-1 Solo el `owner` cambia de plan (`REQ-ORG-003`).
- RB-2 **Subir de plan es inmediato**: los límites nuevos aplican al instante y las funciones se activan sin reconfigurar nada (el branding ya guardado en Free se aplica solo, `REQ-ORG-006` SP-3). La factura del nuevo plan se emite en el acto (`REQ-SUB-006`).
- RB-3 **Bajar de plan no es inmediato**: la suscripción pasa a `grace` hasta el fin del período ya pagado, y la degradación se aplica al vencer (`REQ-SUB-008`). Nadie pierde funciones que ya pagó.
- RB-4 **Cancelar un plan pago degrada a Free**, nunca deja a la organización sin plan (`REQ-SUB-002` RB-6).
- RB-5 Antes de confirmar una bajada, la UI muestra **exactamente qué quedará por encima del límite** y qué pasará con ello: "tienes 8 catálogos publicados; en Free quedará 1 publicado y 7 se despublicarán el 30/09".
- RB-6 Sin prorrateo ni notas de crédito en esta iteración: el período pagado se respeta completo y no se devuelve dinero.
- RB-7 Un cambio de plan programado se puede **revertir** antes de que venza el período, dejando la suscripción `active` en el plan original sin coste ni rastro operativo.
- RB-8 Todo cambio de plan se audita en la organización y en la plataforma (`REQ-PLT-004`).

**Happy path**
1. El negocio en Free choca con el límite de catálogos y abre Suscripción.
2. Elige Pro; la suscripción pasa a Pro `past_due` con su factura emitida, y **los límites de Pro ya aplican** para que pueda seguir trabajando mientras paga.
3. Sube el comprobante; al aprobarse, la suscripción queda `active`.

**Sad paths y edge cases**
- SP-1 `member` intentando cambiar de plan → `403`.
- SP-2 Bajada con consumo muy por encima → permitida, con el detalle de RB-5 y confirmación explícita.
- SP-3 Subida con una factura anterior impagada → permitida: cobrar más es mejor que bloquear; la factura vieja sigue pendiente y ambas se muestran.
- SP-4 Cambio al mismo plan → operación nula, sin factura.
- SP-5 Bajada revertida el último día → se cancela la degradación (RB-7).
- SP-6 Subida inmediata que activa Web Push → las notificaciones se habilitan sin necesidad de que los clientes se resuscriban (`REQ-NOT-001`).
- SP-7 Bajada mientras hay una exportación en curso → el job termina; los siguientes se bloquean cuando la degradación se aplique.
- SP-8 Cambio de plan de una organización `suspended` → se permite subir de plan como vía de regularización; la suspensión se levanta al aprobarse el pago (`REQ-SUB-007` RB-5).

**Fuera de alcance inmediato**
- Prorrateo, notas de crédito y devoluciones.
- Planes anuales y cambio de intervalo.
- Descuentos de retención al intentar bajar.

**Gate de validación**
- Superficie esperada: `app/Actions/Subscription/ChangePlanAction.php`, `app/Http/Controllers/Backoffice/SubscriptionController.php`, `app/Http/Requests/Backoffice/ChangePlanRequest.php`, ~~`resources/js/pages/backoffice/subscription/Index.tsx`~~ `resources/js/pages/backoffice/subscription/Show.tsx`, la página que `T-067` ya creó y que es el destino de cada «Ver planes». *(Corregido en `T-068`: ver la nota de abajo.)*
- Tipos esperados: `ChangePlanAction`, `PlanChangePreview` (qué queda por encima del límite), `SubscriptionStatus`.
- Anti-`SHALLOW`: `ChangePlanAction::handle()` (dirección del cambio, aplicación inmediata o programada, emisión de factura, cálculo de la previsualización, auditoría) y `PlanChangePreview::forDowngrade()` superan 4 líneas.
- Tests: subir aplica límites al instante; bajar programa y no quita nada hasta el vencimiento; cancelar deja Free; previsualización lista lo que excede; revertir antes del vencimiento funciona; `member` recibe `403`; subir con factura impagada permitido.

> **Cómo quedó, en `T-068`.** `ChangePlanAction` decide primero la **dirección** (`PlanChangeDirection`, por la posición del plan en el escaparate y nunca por su código ni por su precio, que hoy es cero en los tres; **ir a Free es siempre bajar**, aunque se venga de un plan negociado con la posición por defecto; a igual posición, bajar), y las tres ramas no se parecen:
>
> - **RB-2 · subir es inmediato**: la suscripción apunta al plan nuevo y empieza un período (hoy → hoy + 1 mes, `Plan::periodEndFrom()`, que lanza con un intervalo que no sea mensual). `LimitEnforcer` lee el plan de la suscripción en cada petición, así que `planState` ya lo trae en la siguiente, sin caché que invalidar (`ChangePlanPageTest`). `grace` vuelve a `active`; **una suscripción en impago conserva su estado y su período** (SP-3, SP-8): el fin del período es el vencimiento de lo que se debe, lo enseña el panel, y subir no lo corre; lo levanta el pago (`T-069`). Subir durante una bajada programada la cancela (`REQ-SUB-008` SP-5). **La factura es de `T-069`**: su sitio es `upgrade()`, dentro de la transacción, y dos cosas lo marcan —`ChangePlanActionTest::test_the_upgrade_invoice_is_still_pending()` se pone rojo cuando exista `IssueInvoiceAction`, y mientras tanto **subir a un plan con precio lanza**: sin factura se regalaría (hoy no bloquea nada, porque los tres valen cero)—. El estado mientras se paga (el *happy path* dice `past_due`) y el RIF de `REQ-SUB-006` RB-5 los decide `T-069`.
> - **RB-3 · bajar se programa**: dos columnas nuevas, `scheduled_plan_id` y `scheduled_plan_at` (migración `2026_10_09_000301`), y no `grace_ends_at`, que es la gracia del impago: con una sola columna, programar una bajada movería el plazo de pago. La fecha es el fin del período pagado (`PlanChangePreview::effectiveDateOf()`), o **ahora** si no queda período pagado —abierto, vencido o con la factura sin pagar: así la bajada a Free como escape del impago (`REQ-SUB-007` SP-8) vence ya, sin esperar el fin de un período que nadie pagó—. Una suscripción `active` pasa a `grace`; una en impago conserva su estado. **Lo que dice que hay una bajada pendiente es `scheduled_plan_id`, no `grace`.** Nada cambia hasta que `T-072` la aplique, y por eso sigue siendo revertible hasta entonces.
> - **RB-4 · cancelar es bajar a Free**, con `cancelled_at` sellado; volver a elegir otro plan de pago lo vacía. **No es `SubscriptionStatus::Cancelled`**, que es la baja de la organización (`REQ-ORG-008`).
> - **RB-7, SP-4 · al mismo plan** no se escribe ni se audita nada; con una bajada programada, es **revertirla**: se vacían las dos columnas y `cancelled_at`, `grace` vuelve a `active` (el impago no: revertir no paga nada) y el período no se toca. Desde la pantalla, «Mantener Pro» del aviso de la bajada. Pedir otra vez la bajada ya programada tampoco cambia nada.
> - **RB-5, SP-2 · la previsualización** (`PlanChangePreview`) compara el **contador** de cada métrica con el techo del destino —no las filas: un contador que dice 4 manda aunque haya 3—, más los catálogos cuyo pivote pasa de `max_products_per_catalog` (sin los productos en la papelera, como `SyncCatalogProductsAction`). Cada exceso lleva su consecuencia: **despublicar** para los catálogos (`REQ-SUB-008` RB-2) y **bloquear crear** para todo lo demás. La pantalla recibe cada plan del escaparate con su previsualización ya calculada, y la acción la vuelve a calcular: una bajada con exceso lanza `PlanDowngradeNeedsConfirmationException` sin `confirmed`, que el controlador devuelve como error de `confirmed`. Los catálogos «se despublicarán **después del**» día del cambio, no «el»: `REQ-SUB-008` RB-2 da `downgrade_grace_days` desde el cambio, y sumarlo es de `T-072`.
> - **RB-1, SP-1**: `SubscriptionPolicy::changePlan` (capacidad `subscription`, solo el dueño) dentro de la acción y sobre la fila bloqueada. Solo se entra en un plan público; el actual, aunque se haya retirado, se admite, porque es como se revierte. La ruta sobrevive a la suspensión.
> - **RB-8**: `subscription.plan_changed` en el registro de la organización, con plan, estado, bajada programada, baja y período antes y después (`AuditEventTest`: 27 emisores, se abre el bloque Suscripción). **El lado de la plataforma no existe todavía**: lo lee de ese mismo registro el panel de `REQ-PLT-004` (`T-086`).
> - **Serializa la fila de la suscripción** (`lockForUpdate`), y nada más la bloquea: no hay ciclo posible con los órdenes de bloqueo de los contadores. Quien la bloquee en adelante (`T-069`, `T-071`, `T-072`) la coge **antes** que cualquier contador, catálogo o `ProductLock`.
> - **De paso:** `grace` ya no da el aviso «factura vencida» del panel (`DashboardWarningsQuery`): es una bajada programada, no una deuda.
> - **Las fechas se leen en la zona del negocio**, en el mensaje del servidor y en la pantalla (`timezone` viaja con la página).
> - **Lo que queda:** la factura del plan nuevo, el RIF y el estado mientras se paga, de **`T-069`**; **aplicar** la bajada al vencer —que cancelar deje de verdad en Free—, de **`T-072`** (`test_applying_the_scheduled_downgrade_is_still_pending()` se pone rojo cuando exista `ApplyDowngradeAction`); levantar la suspensión al bajar a Free en impago, de **`T-071`**.

---

### REQ-SUB-006 · Facturación y cobro por comprobante

**Historia de usuario**
> Como dueño, quiero pagar mi suscripción con pago móvil o transferencia como pago todo lo demás, para no necesitar una tarjeta internacional.

**Contratos de datos**
- Entidades `SubscriptionInvoice` (`subscription_invoices`) y `SubscriptionReceipt` (`subscription_receipts`), ambas de **plataforma**.
- `IssueInvoiceAction`, `SubmitSubscriptionReceiptAction`, `ApproveSubscriptionReceiptAction`, `RejectSubscriptionReceiptAction`.
- Rutas `GET /backoffice/suscripcion/facturas`, `POST /backoffice/suscripcion/facturas/{invoice}/comprobante`; lado plataforma en `REQ-PLT-004`.

**Reglas de negocio y precondiciones**
- RB-1 Cobro **manual por comprobante** (`Q3`): la organización sube su comprobante y la plataforma lo aprueba. Es el mismo mecanismo que [22-comprobantes-y-conciliacion.md](22-comprobantes-y-conciliacion.md), aplicado un nivel más arriba.
- RB-2 Reutiliza las reglas ya especificadas de comprobantes: tipos y tamaño de archivo, validación de MIME real, disco privado, URL firmada, `sha256` y bloqueo de duplicados. **No se reimplementan**: `SubmitSubscriptionReceiptAction` comparte los colaboradores de `REQ-PAY-002` y `REQ-PAY-003`.
- RB-3 Una factura emitida es **inmutable** en importe, período y plan. Corregir exige anularla (`void`) y emitir otra.
- RB-4 Las facturas de la suscripción se denominan en **USD**, como el resto del sistema, y se pagan con la mecánica bimoneda de [13](13-bimoneda-y-tasa-de-cambio.md): el comprobante en bolívares se convierte con la tasa del día de la aprobación y queda congelada en el registro.
- RB-5 El RIF de la organización es **obligatorio para emitir la primera factura** (`REQ-ORG-001` RB-7); si falta, se le pide antes de subir de plan.
- RB-6 Al aprobar el comprobante: la factura pasa a `paid`, la suscripción a `active`, el período se extiende y se levanta cualquier suspensión.
- RB-7 Al rechazar: motivo obligatorio, la factura sigue pendiente y la organización puede subir otro comprobante (misma mecánica de `REQ-PAY-006`).
- RB-8 La renovación emite factura automáticamente **7 días antes** del fin del período, con `due_date` en la fecha de vencimiento, para que haya margen de pago sin llegar a `past_due`.
- RB-9 Free **nunca** genera facturas.
- RB-10 Toda emisión, aprobación y rechazo se audita en la plataforma y es visible para la organización en su historial de facturas.

**Happy path**
1. Siete días antes del vencimiento se emite la factura de Pro por $15.
2. El `owner` la ve en el backoffice con el importe en USD y su equivalente en bolívares a la tasa vigente, y con las cuentas de cobro de la plataforma.
3. Paga, sube el comprobante y queda en `under_review`.
4. La plataforma lo aprueba: factura `paid`, suscripción `active`, período extendido 30 días.

**Sad paths y edge cases**
- SP-1 Comprobante duplicado → bloqueado con la misma regla de `REQ-PAY-003`.
- SP-2 Comprobante por un importe menor al de la factura → la plataforma puede aprobarlo **parcialmente**: la factura queda pendiente por la diferencia, sin activar la suscripción. Se le informa del faltante exacto.
- SP-3 Rechazo del comprobante → la factura sigue pendiente; si ya venció, entra en `past_due` con su gracia (`REQ-SUB-007`).
- SP-4 Sin RIF al emitir la primera factura → se solicita antes de permitir el cambio de plan.
- SP-5 Sin tasa de cambio disponible al aprobar un comprobante en bolívares → `422`, igual que en `REQ-FX-005` SP-2.
- SP-6 Factura anulada con comprobante ya subido → el comprobante se conserva como evidencia y se marca huérfano.
- SP-7 Organización que cancela con una factura pendiente → degrada a Free y la factura queda pendiente; no se persigue el cobro automáticamente.
- SP-8 Dos comprobantes para la misma factura → ambos quedan en revisión; el primero aprobado la liquida y el segundo se rechaza por "ya pagada".

**Fuera de alcance inmediato**
- Pasarela de pago y cobro recurrente automático.
- Factura fiscal, IVA y retenciones.
- Recordatorios de pago por email más allá de la notificación in-app.
- Cobro en bolívares como moneda de denominación.

**Gate de validación**
- Superficie esperada: `app/Models/SubscriptionInvoice.php`, `app/Models/SubscriptionReceipt.php`, `app/Actions/Subscription/IssueInvoiceAction.php`, `app/Actions/Subscription/ApproveSubscriptionReceiptAction.php`, `app/Jobs/IssueRenewalInvoices.php`.
- Tipos esperados: `SubscriptionInvoice`, `SubscriptionReceipt`, `InvoiceStatus`, `InvoiceRow`, `IssueInvoiceAction`.
- Anti-`SHALLOW`: `ApproveSubscriptionReceiptAction::handle()` (conversión con snapshot de tasa, liquidación de la factura, extensión del período, levantamiento de suspensión, auditoría) supera 4 líneas; `IssueInvoiceAction::handle()` idem.
- Tests: la renovación emite 7 días antes; Free no genera facturas; factura inmutable; aprobación extiende el período y levanta la suspensión; pago parcial deja pendiente la diferencia; duplicado bloqueado reutilizando el colaborador de `REQ-PAY-003` (test de equivalencia); sin tasa da `422`; segundo comprobante de una factura pagada se rechaza.

---

### REQ-SUB-007 · Impago, gracia y suspensión

**Historia de usuario**
> Como dueño, quiero un margen razonable si me atraso un pago, y quiero certeza de que mis clientes no se verán afectados por eso.

**Contratos de datos**
- `subscriptions.status`, `grace_ends_at`; `SuspendOrganizationAction` (`REQ-ORG-008`).
- Job diario `subscriptions:process-dunning`.
- Ajuste `platform_settings.subscription_grace_days` (7).

**Reglas de negocio y precondiciones**
- RB-1 Secuencia: factura vencida → `past_due` con `grace_ends_at = due_date + subscription_grace_days` → si la gracia se agota, `suspended` (`REQ-ORG-008`).
- RB-2 En `past_due` **no se pierde nada**: la organización opera con normalidad y ve un aviso persistente con el importe y la fecha límite.
- RB-3 **Regla no negociable (`Q7`):** ni `past_due` ni `suspended` interrumpen jamás un apartado en curso, el portal del cliente final, la subida de comprobantes, el chat con los clientes ni el acceso del cliente a su historial. **El perjudicado nunca puede ser el cliente que le debe dinero al negocio.**
- RB-4 En `suspended` (detalle en `REQ-ORG-008` RB-2 y RB-3): los catálogos se despublican, el equipo pierde la creación pero **conserva lectura de cobranza y la capacidad de aprobar comprobantes**. Cobrar lo que ya le deben no puede quedar bloqueado por deberle una suscripción a la plataforma.
- RB-5 Pagar en cualquier momento levanta la suspensión de inmediato (`REQ-SUB-006` RB-6), sin republicar catálogos automáticamente (`REQ-ORG-008` RB-4).
- RB-6 Avisos al `owner`: al emitir la factura, al vencer, y a los 3 y 1 días del fin de la gracia. Idempotentes por (factura, tipo), con la misma mecánica de `REQ-NOT-005`.
- RB-7 La suspensión **no** borra datos ni inicia ningún reloj de eliminación en esta iteración.
- RB-8 El job de dunning es idempotente y su ejecución diaria recalcula estados desde cero, sin depender de haber corrido ayer.

**Happy path**
1. Vence una factura sin pagar: la suscripción pasa a `past_due` con 7 días de gracia y aviso al `owner`.
2. Al séptimo día sin pago pasa a `suspended`: los catálogos se despublican.
3. Sus 34 clientes con apartados siguen pagando, subiendo comprobantes y escribiendo por el chat sin notar nada.
4. El `owner` paga; se levanta la suspensión y él elige qué catálogos republicar.

**Sad paths y edge cases**
- SP-1 Comprobante en revisión al agotarse la gracia → **no se suspende**: la gracia se congela mientras la plataforma tiene un comprobante pendiente de revisar, igual que en `REQ-LAY-006` RB-2. No se castiga al negocio por la demora de la plataforma.
- SP-2 Comprobante rechazado con la gracia ya vencida → prórroga de 24 h antes de suspender, misma lógica que `REQ-LAY-006` RB-3.
- SP-3 Cliente final abriendo un catálogo de una organización suspendida → `410`; sus apartados previos siguen accesibles (RB-3).
- SP-4 Equipo intentando crear un producto en `suspended` → `403` con el enlace de pago.
- SP-5 Suspensión de una organización con apartados que vencen esa semana → los recordatorios a sus clientes **siguen enviándose**: son parte de la operación del apartado, protegida por RB-3.
- SP-6 Impago prolongado de meses → permanece `suspended` indefinidamente; sin borrado automático (RB-7).
- SP-7 Job de dunning que no corre un día → al reanudarse recalcula y aplica lo pendiente.
- SP-8 Bajada a Free como vía de escape del impago → permitida: pasa a Free y la factura pendiente queda registrada (`REQ-SUB-005` SP-7). Es mejor conservar la organización activa en Free que perderla.

**Fuera de alcance inmediato**
- Cobro automático de la deuda pendiente.
- Eliminación de datos por impago prolongado.
- Reintentos automáticos contra una pasarela.

**Gate de validación**
- Superficie esperada: `app/Jobs/ProcessSubscriptionDunning.php`, `app/Actions/Organization/SuspendOrganizationAction.php`, `app/Notifications/InvoiceOverdueNotification.php`, `app/Domain/Subscription/SubscriptionStatus.php`.
- Tipos esperados: `SubscriptionStatus`, `ProcessSubscriptionDunning`, `subscriptions.grace_ends_at`.
- Anti-`SHALLOW`: `ProcessSubscriptionDunning::handle()` (transición por fechas, congelación por comprobante en revisión, prórroga por rechazo, avisos idempotentes, suspensión) supera 4 líneas.
- Tests: la secuencia completa de estados por fechas; **`suspended` no afecta a apartados, portal, comprobantes, chat ni recordatorios** (cinco tests explícitos de RB-3); comprobante en revisión congela la gracia; rechazo tardío da 24 h; pagar levanta la suspensión sin republicar; avisos idempotentes; job idempotente.

---

### REQ-SUB-008 · Degradación no destructiva

**Historia de usuario**
> Como dueño que baja de plan, quiero conservar todo lo que cargué y decidir yo qué queda visible, para no perder trabajo por un cambio de plan.

**Contratos de datos**
- `ApplyDowngradeAction(Subscription $subscription): DowngradeOutcome`.
- Job diario `subscriptions:apply-downgrades`; ajuste `platform_settings.downgrade_grace_days` (7).

**Reglas de negocio y precondiciones**
- RB-1 **Bloqueo blando, nunca destructivo (`Q7`).** Al bajar de plan, **nada se borra ni se oculta**. Los datos existentes se leen siempre, y solo se bloquea la creación de nuevos.
- RB-2 Único efecto automático: si los **catálogos publicados** exceden el nuevo techo, se despublican los excedentes conservando los **más antiguos** publicados, tras `downgrade_grace_days` desde el cambio.
- RB-3 **Los apartados vigentes nunca se tocan**, aunque `active_layaways` quede muy por encima del techo: hay clientes finales con una deuda real. El exceso simplemente bloquea apartados nuevos (`REQ-SUB-004` RB-4).
- RB-4 Miembros del equipo por encima del techo: **se conservan activos**. Solo se bloquea invitar hasta bajar del límite. Expulsar a un empleado por un cambio de plan sería inaceptable.
- RB-5 Productos por encima del techo: **se conservan y siguen visibles** en sus catálogos. Solo se bloquea crear.
- RB-6 Almacenamiento por encima del techo: **nada se borra**. Se bloquean las subidas nuevas, incluidas las de comprobantes de clientes — con una excepción crítica: **un comprobante de pago de un cliente final nunca se rechaza por cuota de almacenamiento**. Se acepta y se registra el exceso, porque bloquearlo rompería la cobranza del negocio y perjudicaría a su cliente (RB-3 de `REQ-SUB-007`).
- RB-7 Cuentas de cobro por encima del techo: se conservan activas; solo se bloquea crear.
- RB-8 Funciones de plano perdidas (branding, exportación, push, digest) se desactivan al aplicarse la degradación, y su configuración **se conserva** para reactivarse sola si se vuelve a subir (`REQ-ORG-006` SP-3).
- RB-9 Antes y después, la UI muestra con precisión qué está por encima del límite y qué implica (`REQ-SUB-005` RB-5).
- RB-10 La degradación se audita con el detalle de lo que se despublicó.

**Happy path**
1. Un negocio baja de Pro a Free con 8 catálogos publicados y 240 productos.
2. Durante 7 días no cambia nada; se le avisa de qué ocurrirá.
3. Al octavo día se despublican 7 catálogos, conservando el más antiguo. Los 240 productos siguen ahí, los 6 miembros del equipo siguen activos y los 180 apartados vigentes siguen su curso.

**Sad paths y edge cases**
- SP-1 Los catálogos despublicados tenían suscriptores → las suscripciones **no se borran**, quedan inactivas y revivirán si el catálogo se vuelve a publicar (`REQ-ACC-002` RB-8).
- SP-2 Un catálogo despublicado tenía apartados en curso → los apartados siguen intactos y sus clientes conservan el acceso; solo deja de captar nuevos.
- SP-3 Comprobante de un cliente con la cuota de almacenamiento agotada → **se acepta** (RB-6), y el negocio recibe la alerta del exceso.
- SP-4 Subida de imagen de producto por el negocio con la cuota agotada → **se rechaza** con el mensaje del plan: aquí sí es el negocio el afectado por su propia decisión.
- SP-5 Subida de plan durante la gracia de degradación → se cancela la degradación (`REQ-SUB-005` RB-7).
- SP-6 Degradación de una organización que ya está por debajo de todos los techos → operación nula.
- SP-7 Job de degradación ejecutado dos veces → idempotente; no despublica más de lo debido.
- SP-8 Empate de fecha de publicación al elegir qué catálogos conservar → desempate por `id` ascendente, para que el resultado sea determinista y reproducible en los tests.

**Fuera de alcance inmediato**
- Elección manual de qué catálogos conservar publicados al degradar.
- Archivado automático del exceso de productos.
- Compra de cupo adicional para evitar la degradación.

**Gate de validación**
- Superficie esperada: `app/Actions/Subscription/ApplyDowngradeAction.php`, `app/Jobs/ApplyScheduledDowngrades.php`, `app/Domain/Subscription/DowngradeOutcome.php`.
- Tipos esperados: `ApplyDowngradeAction`, `DowngradeOutcome`, `PlanChangePreview`.
- Anti-`SHALLOW`: `ApplyDowngradeAction::handle()` (selección determinista de catálogos a despublicar, preservación de equipo, productos y apartados, desactivación de funciones conservando su configuración, auditoría) supera 4 líneas junto a `DowngradeOutcome`.
- Tests: **nada se borra** (test por cada una de las seis métricas); despublica los excedentes conservando los más antiguos con desempate por `id`; apartados vigentes intactos; equipo intacto; comprobante de cliente aceptado con cuota agotada y subida del negocio rechazada (los dos lados de RB-6); suscripciones inactivas revividas al republicar; subir de plan durante la gracia cancela la degradación; job idempotente.
