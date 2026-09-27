# Módulo 20 — Apartado, estados y cronograma de amortización (`LAY`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md), [12-catalogos-curados.md](12-catalogos-curados.md), [13-bimoneda-y-tasa-de-cambio.md](13-bimoneda-y-tasa-de-cambio.md), [50-planes-y-suscripciones.md](50-planes-y-suscripciones.md)
> Requisitos: `REQ-LAY-001` … `REQ-LAY-011`
>
> **Núcleo del negocio.** Toda la aritmética de este módulo sobrevive al pivote sin cambios; lo que cambia es que el apartado **nace dentro de un catálogo**, pertenece a una organización y consume cupo de su plan.

## Contratos compartidos del módulo

```php
enum LayawayStatus: string
{
    case PendingDownPayment = 'pending_down_payment';
    case Active = 'active';
    case Overdue = 'overdue';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<self> Estados a los que puede transicionar este estado. */
    public function allowedTransitions(): array { /* … */ }
    public function isTerminal(): bool { /* … */ }
    public function acceptsPayments(): bool { /* … */ }
    /** true si consume cupo del límite active_layaways del plan. */
    public function countsTowardPlanLimit(): bool { /* … */ }
}

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
}

/** Condiciones comerciales congeladas al crear el apartado. */
final readonly class LayawayTerms
{
    public function __construct(
        public Money $unitPrice,
        public Money $downPayment,
        public int $quantity,
        public int $installmentsCount,
        public int $frequencyDays,
    ) {}

    public function total(): Money { /* unitPrice × quantity */ }
    public function totalDownPayment(): Money { /* downPayment × quantity */ }
    public function financedAmount(): Money { /* total − totalDownPayment */ }
}
```

```ts
export interface LayawaySummary {
  readonly code: string;
  readonly status: LayawayStatus;
  readonly productName: string;
  readonly quantity: number;
  readonly total: MoneyView;
  readonly paid: MoneyView;
  readonly remaining: MoneyView;
  readonly nextDueDate: string | null;
  readonly overdueInstallments: number;
  /** Negocio al que pertenece este apartado: imprescindible en el portal multi-negocio. */
  readonly business: { readonly name: string; readonly slug: string };
  readonly catalogSlug: string | null;
}

export type LayawayStatus =
  | 'pending_down_payment' | 'active' | 'overdue'
  | 'completed' | 'cancelled' | 'expired';
```

---

### REQ-LAY-001 · Creación del apartado desde el catálogo

**Historia de usuario**
> Como cliente con el enlace de un catálogo, quiero apartar un producto indicando cantidad y mis datos, para reservarlo y pagarlo por cuotas sin tener que registrarme.

**Contratos de datos**
- Rutas `GET /c/{catalog:slug}/apartar/{product:slug}` y `POST` de la misma.
- `StoreLayawayRequest` → `CreateLayawayAction`.
- Entidad `Layaway` con `organization_id`, `catalog_id?` y `code` público (`UNIQUE` global).

```php
final readonly class CreateLayawayData
{
    public function __construct(
        public Catalog $catalog,          // origen: de aquí sale la organización
        public Product $product,
        public int $quantity,
        public CustomerContactData $contact,
        public TermsConsent $consent,     // T-043: la evidencia, no un booleano
        public string $idempotencyKey,    // UUID del formulario
        // ?Account $account llega con H6 (RB-4)
    ) {}
}
```

> **El contrato de `CreateLayawayData` cambió en dos campos al escribirlo (`T-045`).** `bool $termsAccepted` pasa a ser **`TermsConsent $consent`**: `T-043` decidió que la evidencia —instante e IP— viaja como un objeto que no se construye sin ella, y un booleano diría «aceptó» sin decir cuándo ni desde dónde (razonado bajo `REQ-IDN-001`). `StoreLayawayRequest` (`T-045b`) valida `accepted` y construye el objeto con `TermsConsent::acceptedNowFrom()`. Y **`?Account $account` no está todavía**, porque `Account` es de H6: el enganche está nombrado en `CreateLayawayAction` donde irán `LinkAccountAction` y `SubscribeToCatalogAction`, y `CreateLayawayActionTest` tiene un caso que se pone rojo el día que alguno de los tres exista. El constructor comprueba lo que se puede comprobar sin la base: cantidad entre 1 y 10 (el tope de `stock` de RB-5 solo es cierto bajo el bloqueo) y clave con forma de UUID.

> **Dónde vive la clave de RB-12, y qué pasa con dos envíos a la vez** (decidido en `T-045`). La spec no lo fijaba. Es una **columna, `layaways.idempotency_key`, con `UNIQUE(organization_id, idempotency_key)`**, y no una caché: el caso secuencial lo resuelve cualquier registro, pero el simultáneo solo lo resuelve algo que la base haga cumplir **dentro** de la transacción. Con el tenant delante (`REQ-ORG-002` RB-3), así que no es una cuarta excepción de `GlobalUniqueExceptions`. Los tres casos: **uno detrás de otro**, el segundo sale antes de abrir transacción con el mismo apartado; **a la vez y del mismo producto**, el segundo espera el bloqueo del producto y encuentra el apartado ya confirmado —también si era la última unidad: devuelve el apartado, no un `422` de stock—; **a la vez con otro producto** (formulario manipulado), choca el índice, la transacción del segundo se deshace entera —ficha, reserva, cupo— y se devuelve el del primero, releído con `FOR UPDATE` porque la foto de quien llama puede ser anterior (medido en `CreateLayawayConcurrencyTest`). En los tres, el apartado devuelto tiene `wasRecentlyCreated` a `false`, que es lo que `T-045b` mira para no mandar el correo dos veces.

> **Los rechazos de RB-6, con su tipo** (`T-045`). Catálogo despublicado o caducado, y organización suspendida (SP-3, SP-5): `CatalogUnavailableException`, `422`, una sola frase para todos los motivos. Producto fuera de ese catálogo, de otra organización, sin publicar o archivado mientras tanto (SP-4): `ProductNotAvailableException`. Stock (SP-1): `InsufficientStockException` con `available`. Cupo del plan o suscripción suspendida (SP-2): **`NotAcceptingLayawaysException`**, con el mensaje neutro de `REQ-SUB-004` RB-4 y el `PlanLimitReachedException` real como `getPrevious()`, para el aviso del dashboard (`T-065`). `PlanLimitReachedException` sigue en los tipos esperados porque es lo que viaja dentro; al cliente no le llega nunca.

> **La superficie, tal como quedó** (`T-045b`). **El `GET` del formulario resuelve el producto como la ficha** —catálogo cerrado o producto que no está dan `404`/`410`—, pero **el `POST` no**: resuelve el producto sin *binding* anidado (`withoutScopedBindings()`), solo por el global scope —el de otra organización sigue siendo `404`—, y deja decidir a la acción, porque con el *binding* un catálogo despublicado mientras el cliente escribía sería un `404` y perdería el formulario, cuando SP-3 pide un `422`. Cada rechazo va a su campo: `catalog` para `CatalogUnavailableException` y `NotAcceptingLayawaysException` (se pintan arriba del formulario, no tienen campo), `product` para `ProductNotAvailableException`, `quantity` para `InsufficientStockException`, y `documentNumber` / `phone` para los del contacto; los nombres de los campos son los de `LayawayFormValues`. **El resumen del plan** lo calcula el servidor sobre el apartado entero (`LayawayPlanResource`) y se refresca con una recarga parcial de `plan` al cambiar la cantidad; las fechas son **estimadas**, como si se activara hoy. **La página de gracias es solo de la sesión que apartó**: el código es media credencial del portal y la confirmación no tiene por qué ser una segunda puerta; recargarla funciona mientras dure la sesión. **El correo** sale solo si hay correo y el apartado se acaba de crear (`wasRecentlyCreated`), va a la cola sin modelos dentro, y un fallo al enviarlo no toca el apartado (SP-9). Y **el envío lleva `throttle:apartados`**, diez por minuto y por IP: es público, sin sesión, y cada apartado reserva stock durante 72 horas, así que sin techo un bucle vaciaría el inventario de un negocio sin pagar nada.

```ts
export interface LayawayFormValues {
  readonly quantity: number;
  readonly fullName: string;
  readonly documentNumber: string;
  readonly phone: string;               // obligatorio
  readonly email: string;               // opcional
  readonly termsAccepted: boolean;
  readonly idempotencyKey: string;
}
```

**Reglas de negocio y precondiciones**
- RB-1 **El apartado nace dentro de un catálogo**, y de ahí se deriva la organización (`REQ-ORG-002` RB-4). No existe forma de crear un apartado sin catálogo de origen desde la cara pública. `catalog_id` se persiste para telemetría y contexto.
- RB-2 **No se exige registro ni cuenta** (`REQ-ACC-002` RB-2): el cliente se resuelve o se crea en la misma transacción (`REQ-IDN-001`). Es el requisito de conversión más importante del producto.
- RB-3 Datos obligatorios: nombre, **documento**, **teléfono** y aceptación de términos. El email es **opcional** (`REQ-IDN-001` RB-2 y RB-3).
- RB-4 Con sesión de cuenta, el email se precarga y bloquea, y la ficha resultante se vincula a la cuenta (`REQ-IDN-002`). Además queda **suscrita al catálogo** de origen (`REQ-ACC-002` RB-5).
- RB-5 `quantity` entre 1 y `min(product.stock, 10)`.
- RB-6 Precondiciones verificadas en la `Action`, en este orden:
  1. el catálogo es accesible (`Listed` o `Unlisted`, publicado, no expirado);
  2. el producto pertenece a ese catálogo y está `published`;
  3. hay stock suficiente (bajo bloqueo);
  4. **hay cupo en el límite `active_layaways` del plan** (`REQ-SUB-004` RB-3);
  5. la organización no está suspendida.
- RB-7 El apartado nace en `pending_down_payment` con `expires_at = now() + organization_settings.down_payment_window_hours` (72 h por defecto).
- RB-8 **No** se genera cronograma al crear: se genera al activar (`REQ-LAY-004`).
- RB-9 El `code` es único **global** (para que sirva como prueba de acceso, `REQ-IDN-008` RB-3), legible y no adivinable: `AP-` + 8 caracteres en base32 sin `0`, `O`, `1` ni `I`.
- RB-10 Al crear se muestra y se envía el `code` con las instrucciones de pago y **las cuentas de cobro del negocio** (`REQ-ORG-007`). Si hay email, también por correo; si no, la pantalla de confirmación es el único canal y lo advierte, invitando a anotarlo.
- RB-11 Se emite `layaway_created` con `catalog_id`, `organization_id` y el origen (`REQ-TLM-001`).
- RB-12 Idempotencia por `idempotencyKey` (UUID generado en el cliente al montar el formulario): un segundo `POST` con la misma clave devuelve el apartado ya creado.
- RB-13 Un cliente puede tener varios apartados simultáneos en el mismo negocio y en negocios distintos; no hay límite por cliente.

**Happy path**
1. El cliente pulsa "Apartar" en la ficha y llega al formulario con el producto y `quantity = 1`.
2. Ve el resumen del plan (total, inicial, número de cuotas, importe de cada cuota, fechas estimadas) en bimoneda, y las cuentas de cobro del negocio.
3. Completa nombre, documento, teléfono, acepta los términos y envía.
4. `CreateLayawayAction`, en una transacción: valida las cinco precondiciones, **bloquea el producto**, comprueba el cupo del plan, **resuelve el cliente**, congela los `LayawayTerms`, crea el `Layaway`, reserva stock, incrementa el consumo del plan, vincula la cuenta si la hay y encola la confirmación.

> **El orden de bloqueo del paso 4 estaba invertido respecto a [05 §5.1], y se ha corregido (antes de `T-044`).** El texto decía «resuelve el cliente, bloquea el producto», y el diagrama de §5.1 dice lo contrario y lo anota expresamente: **producto primero, cliente después**, que es el orden canónico de `REQ-LAY-003` RB-8. No es una cuestión de estilo: `ResolveCustomerAction` toma un `FOR UPDATE` sobre la ficha al reintentar tras una colisión (`REQ-IDN-001` SP-5, `T-043`), así que dos transacciones que tomaran los dos bloqueos en orden distinto se abrazarían. Y el fallo **no aparece en la suite**: un *deadlock* entre dos conexiones solo se observa sobre filas confirmadas, así que un orden invertido pasa todos los tests ordinarios y se manifiesta en producción, bajo carga, como un `1213` intermitente sin causa aparente. Dos consecuencias para quien implemente: el bloqueo del producto se toma **antes** que cualquier otra fila del tenant, `ReserveStockAction` incluido —que lo vuelve a pedir y lo obtiene por ser reentrante en la misma transacción—; y el caso de tres peticiones simultáneas que `T-043` dejó anotado (una colisión entre las dos perdedoras) se resuelve por aquí, no dentro de la resolución del cliente.
5. Redirección a `/c/{slug}/apartar/{code}/gracias` con el código bien visible, las instrucciones de pago y el CTA para subir el comprobante.

**Sad paths y edge cases**
- SP-1 Stock insuficiente al enviar → `422` con el stock real y la opción de reducir la cantidad; **nada** se persiste.
- SP-2 **Límite `active_layaways` del plan agotado** → `422` con un mensaje **neutro** que no expone el plan del negocio (`REQ-SUB-004` RB-4); al negocio se le avisa en su dashboard de que está perdiendo ventas por el límite.
- SP-3 Catálogo despublicado o expirado entre la carga y el envío → `422` "este catálogo ya no está disponible".
- SP-4 Producto despublicado o archivado → `422` "ya no está disponible".
- SP-5 Organización suspendida → el catálogo ya estaría despublicado, así que se resuelve como SP-3.
- SP-6 Documento, teléfono o términos inválidos → `422` por campo, preservando el resto del formulario.
- SP-7 Doble envío → idempotencia por clave (RB-12).
- SP-8 `down_payment >= price` o `installments_count = 0` → `422`; el CRUD debería impedirlo (`REQ-BOF-020` RB-1).
- SP-9 Fallo del envío del email → **no** revierte el apartado; el job reintenta y el apartado sigue accesible con el código.
- SP-10 Cliente sin email → apartado válido; el acceso al portal será por documento + código (`REQ-IDN-008`).
- SP-11 Cuenta con conflicto de vínculo → el apartado **se completa** y el vínculo no se cambia (`REQ-IDN-002` RB-5).
- SP-12 Caída de la BD a mitad → rollback total: sin cliente huérfano, sin stock reservado, sin consumo incrementado.

**Fuera de alcance inmediato**
- Carrito con varios productos en un apartado.
- Elección por el cliente del número de cuotas o de la frecuencia.
- Pago de la inicial con pasarela.
- Apartado sin catálogo de origen (creación manual desde el backoffice).

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/CreateLayawayAction.php`, `app/Http/Requests/Catalog/StoreLayawayRequest.php`, `app/Http/Controllers/Catalog/LayawayController.php`, `app/Domain/Layaway/LayawayTerms.php`, `app/Support/LayawayCode.php`, `resources/js/pages/catalog/LayawayCreate.tsx`, `resources/js/features/layaway/hooks/useLayawayForm.ts`.
- Tipos esperados: `Layaway`, `LayawayStatus`, `LayawayTerms`, `CreateLayawayData`, `LayawayFormValues`, `InsufficientStockException`, `ProductNotAvailableException`, `PlanLimitReachedException`.
- Anti-`SHALLOW`: `CreateLayawayAction::handle()` (cinco precondiciones, transacción de ocho pasos) y `LayawayTerms::total()`/`financedAmount()` residen junto a sus tipos; `LayawayCode::generate()` incluye el alfabeto sin ambigüedades y el reintento por colisión.
- Tests: crea cliente, apartado, reserva y consumo en una transacción; **el apartado hereda la organización del catálogo** (test de tenancy); producto de otro catálogo rechazado; límite de plan da mensaje neutro; stock insuficiente no persiste nada; doble `POST` con la misma clave devuelve el mismo `code`; sin email funciona; con cuenta vincula y suscribe; rollback completo ante fallo; `code` sin caracteres ambiguos.

---

### REQ-LAY-002 · Snapshot inmutable de condiciones comerciales

**Historia de usuario**
> Como cliente, quiero que el precio y el plan que acepté no cambien después, para que nadie me suba la deuda a mitad del pago.

**Contratos de datos**
- Campos de `layaways`: `unit_price_cents`, `down_payment_cents`, `installments_count`, `frequency_days`, `quantity`, `product_name_snapshot`, `product_sku_snapshot`, `total_cents`.
- `LayawayTerms` reconstruible desde la fila con `Layaway::terms()`.

**Reglas de negocio y precondiciones**
- RB-1 Al crear se copian las condiciones del producto a la fila del apartado; a partir de ahí el apartado **no** vuelve a leer `products` para nada financiero.
- RB-2 `total_cents = unit_price_cents × quantity`; `totalDownPayment = down_payment_cents × quantity`.
- RB-3 `installments_count` y `frequency_days` **no** se multiplican por la cantidad.
- RB-4 Cambiar precio, inicial o plan del producto en el backoffice **no** afecta a ningún apartado existente (invariante con test).
- RB-5 Se guardan `product_name_snapshot` y `product_sku_snapshot` para que el historial y las exportaciones sean legibles aunque el producto se renombre o se archive.
- RB-6 `layaways.product_id` se conserva para navegación, pero es informativo; la FK es `restrict` para impedir el borrado físico del producto.
- RB-7 `layaways.catalog_id` es el catálogo de origen y **también es informativo**: si el catálogo se despublica, se expira o el producto se quita de él, el apartado **no se altera** en absoluto.
- RB-8 Ninguna acción modifica estos campos después de la creación; solo `RescheduleInstallmentAction` toca fechas de cuotas, nunca importes (`REQ-LAY-011`).

> **RB-1 y RB-8, impedidos y no solo pedidos** (`T-045`). `Layaway::terms()` reconstruye `LayawayTerms` **desde la fila** y es la única fuente de las cifras del apartado; `CreateLayawayAction` congela las condiciones **del producto bloqueado**, que es la versión que de verdad se vende. Y la guarda `saving` del modelo, la misma que protege el estado, **lanza** si se guarda un apartado con cualquiera de `Layaway::SNAPSHOT_COLUMNS` cambiada: las ocho del snapshot, más `code`, `idempotency_key` y `product_id`. `customer_id` queda fuera porque la fusión de fichas lo mueve (`REQ-IDN-006`), y `catalog_id` porque lo anula la base si el catálogo desaparece (SP-4). El test de RB-4 crea un apartado de dos unidades, **sube el precio y la inicial, cambia cuotas y frecuencia, renombra y archiva** el producto, y afirma que `terms()` sigue diciendo precio $200, inicial $50, 2 unidades, 5 cuotas cada 15 días, total $400, inicial total $100 y financiado $300, con el nombre y el SKU originales.

**Happy path**
1. Se crea un apartado de un producto de $200 con inicial de $50 en 5 cuotas.
2. El negocio sube el precio a $250 y archiva el producto.
3. El apartado sigue mostrando total $200, inicial $50, cuotas de $30 y el nombre original del producto.

**Sad paths y edge cases**
- SP-1 Producto archivado y renombrado → el apartado y su exportación siguen mostrando nombre y SKU originales.
- SP-2 Intento de "recalcular" un apartado tras cambiar el producto → no existe tal operación; se cancela y se crea uno nuevo.
- SP-3 Producto borrado por acceso directo a la BD → la FK `restrict` lo impide.
- SP-4 Catálogo de origen eliminado o despublicado → el apartado intacto; `catalogSlug` deja de enlazar y la UI lo omite.
- SP-5 `quantity` editada después de crear → no existe esa operación.
- SP-6 Organización que cambia su `default_frequency_days` → no afecta a apartados vigentes.

**Fuera de alcance inmediato**
- Renegociación de condiciones de un apartado vigente.
- Cambio de cantidad o de producto en un apartado existente.

**Gate de validación**
- Superficie esperada: `app/Models/Layaway.php` (casts y `terms()`), `app/Domain/Layaway/LayawayTerms.php`, migración de `layaways`.
- Tipos esperados: `LayawayTerms`, `Layaway` con las ocho columnas de snapshot.
- Anti-`SHALLOW`: `Layaway::terms(): LayawayTerms` reconstruye el VO desde las columnas (> 4 líneas) en `Layaway.php`.
- Tests: cambiar el precio del producto no altera el apartado; snapshot de nombre y SKU tras renombrar y archivar; borrado del producto rechazado por FK; despublicar el catálogo de origen no altera el apartado; `total_cents` refleja la cantidad; cambiar los ajustes de la organización no afecta a vigentes.

---

### REQ-LAY-003 · Reserva y liberación de stock

**Historia de usuario**
> Como negocio, quiero que un apartado inmovilice la unidad, para no vender dos veces el mismo producto físico.

**Contratos de datos**
- Campo `products.stock`; `ReserveStockAction` / `ReleaseStockAction`; campo `layaways.stock_released_at`.
- Campo `products.stock_shortfall` (`T-044`, ver SP-2) y `ProductLock::acquire()`, el único sitio que bloquea la fila del producto.
- `ReserveStockAction::handle(Product $product, int $quantity): Product` —**no abre transacción**: corre dentro de la del apartado, y fuera de una lanza—; `ReleaseStockAction::handle(Layaway $layaway): void`, que llama `LayawayStateMachine` al transicionar a `expired` o `cancelled` (`LayawayStatus::releasesStock()`).

**Reglas de negocio y precondiciones**
- RB-1 El stock se **decrementa al crear** el apartado (`pending_down_payment`), no al activarlo: si no, dos clientes podrían apartar la última unidad y solo uno pagaría.
- RB-2 Se **libera** al pasar a `expired` (`REQ-LAY-006`) o a `cancelled` (`REQ-LAY-009`). **No** se libera al completar: la unidad se entregó.
- RB-3 Toda operación de stock ocurre dentro de la transacción del apartado, con `SELECT … FOR UPDATE` sobre la fila del producto.
- RB-4 `stock` nunca queda negativo: la comprobación es `stock >= quantity` bajo el bloqueo.
- RB-5 `stock = 0` no cambia el estado del producto: sigue `published` y visible con badge "Agotado" (`REQ-CAT-007` RB-4).
- RB-6 El backoffice muestra, junto al stock disponible, las unidades reservadas en apartados no terminales (dato calculado, no columna).
- RB-7 El bloqueo es **por producto de una organización**; el global scope garantiza que nunca se bloquee ni se lea el producto de otra (`REQ-ORG-002`).
- RB-8 Orden de bloqueo canónico en todas las acciones: **producto primero, cliente después**, para evitar deadlocks.

**Happy path**
1. Producto con `stock = 3`; el cliente aparta 2 unidades.
2. La transacción bloquea la fila, valida `3 >= 2`, decrementa a `1` y crea el apartado.
3. El cliente no paga la inicial en 72 h; el job de expiración devuelve el stock a `3`.

**Sad paths y edge cases**
- SP-1 Dos apartados concurrentes de la última unidad → el bloqueo serializa: uno crea, el otro recibe `InsufficientStockException` → `422`.
- SP-2 El negocio reduce el stock por debajo de lo reservado → permitido (puede haber pérdida física); el stock queda en 0, el backoffice muestra la inconsistencia como alerta y los apartados existentes **no** se cancelan.
- SP-3 Liberación doble (job reintentado) → idempotente por `stock_released_at`.
- SP-4 Cancelación de un `completed` → no permitida por la máquina de estados, así que no hay liberación indebida.
- SP-5 Producto sin inventario real (servicio) → se modela con stock alto; no hay stock infinito en v1.
- SP-6 Deadlock potencial entre dos transacciones → prevenido por el orden canónico de RB-8.
- SP-7 Apartado expirado de una organización suspendida → el job libera stock igual: es mantenimiento de datos, no una operación de negocio bloqueada.

> **Qué significa «el stock queda en 0» de SP-2** (decidido en `T-044`). La frase admitía dos lecturas —que se guarda lo que el negocio escribió, o que se guarda cero—, y la respuesta sale de preguntar **qué cuenta el negocio cuando escribe «Existencias»**: lo que tiene en la tienda, y las unidades apartadas que aún no entregó **están en la tienda**. Si el campo fuera «disponibles», bajar por debajo de lo reservado no significaría nada —1 disponible con 3 apartadas es un producto con 4 unidades—, y SP-2 no tendría caso. Así que son cuatro cifras, cada una en su sitio. Con **1 en existencias y 3 apartadas**:
>
> | | Qué es | En el ejemplo |
> |---|---|---|
> | `products.stock` | **disponibles para apartar**: existencias − reservadas, nunca negativa (RB-4) | **0** |
> | reservadas | suma de `quantity` de los apartados no terminales (RB-6), **calculada** | 3 |
> | `products.stock_shortfall` | apartadas que **no están** en la tienda: reservadas − existencias | **2** |
> | «Existencias» en el formulario | disponibles + reservadas − faltante: lo que el negocio escribió y reenvía | **1** |
>
> La pantalla enseña «Existencias: 1», debajo «3 unidades en apartados vigentes · ninguna disponible para apartar», y la alerta: **«Faltan 2 unidades para cubrir los apartados vigentes»**, que no cancela ninguno. El listado enseña 0 disponibles, «3 apartadas» y el sello «Faltan 2 apartadas».
>
> **El faltante es una columna, y no contradice RB-6.** Las reservadas no la tienen porque se derivan de los apartados y una segunda cuenta podría discrepar; el faltante **no se deriva de nada** —es la diferencia entre lo que el negocio contó y lo que tenía comprometido—, y como `stock` no puede ser negativo, sin columna se perdería al guardar. Y perderlo no es cosmético: al cancelarse uno de esos tres apartados, la liberación sumaría a `stock` una unidad que no existe y el siguiente cliente apartaría una nevera fantasma — exactamente la venta doble que este requisito existe para impedir. Con la columna, **la liberación absorbe primero el faltante** y solo devuelve a la venta lo que queda (`ReleaseStockAction`).
>
> Dos consecuencias que conviene no perder. **Editar las existencias es una operación de stock**: `UpsertProductAction` bloquea el producto (RB-8) y cuenta las reservadas bajo ese bloqueo antes de repartir, así que un apartado creado mientras el formulario estaba abierto no recupera su unidad al guardar. Y **completar un apartado con faltante no lo recalcula**: el faltante se acota a las reservadas al leerlo (`Product::shortfallUnits()`), y el siguiente guardado de existencias lo rehace desde lo declarado.

> **Cómo se cumple RB-8, y qué se pone rojo si alguien lo invierte** (`T-044`). El bloqueo del producto lo toma **un solo sitio**, `ProductLock::acquire()`, y lo piden `ReserveStockAction`, `ReleaseStockAction`, `UpsertProductAction` al editar y `LayawayStateMachine` **antes de su propio `FOR UPDATE` sobre el apartado** cuando la transición devuelve stock: la cancelación y la expiración bloquean producto → apartado → contador del plan, el mismo orden que la creación. Como un abrazo mortal no se puede provocar en un solo proceso, `StockLockOrderTest` lo afirma **sobre el registro de consultas**: la primera sentencia que bloquea o escribe una fila del tenant tiene que ser el `FOR UPDATE` de `products`. Se comprobó moviendo el bloqueo de la máquina detrás del del apartado: los dos casos de transición se ponen rojos y nombran la sentencia culpable. El *helper* está en `tests/Concerns/AssertsStockLockOrder` para que `T-045` lo aplique a `CreateLayawayAction`.

**Fuera de alcance inmediato**
- Stock por ubicación o almacén.
- Reserva temporal previa al formulario.
- Reposición automática.

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/ReserveStockAction.php`, `app/Actions/Layaway/ReleaseStockAction.php`, `app/Actions/Layaway/CreateLayawayAction.php`, `app/Models/Layaway.php`.
- Tipos esperados: `InsufficientStockException`, `ReserveStockAction`, `ReleaseStockAction`, `ProductLock`.
- Anti-`SHALLOW`: `ReserveStockAction::handle()` (bloqueo, comprobación, decremento, excepción) y `ReleaseStockAction::handle()` (idempotencia + incremento) superan 4 líneas.
- Tests: concurrencia real con dos transacciones sobre la última unidad; stock nunca negativo; liberación idempotente; expiración devuelve stock y completar no; el bloqueo no alcanza productos de otra organización (fuga); orden de bloqueo respetado.

---

### REQ-LAY-004 · Generación del cronograma de amortización

**Historia de usuario**
> Como cliente, quiero conocer las fechas y el importe exacto de cada cuota desde el principio, para organizar mis pagos.

**Contratos de datos**
- Entidad `Installment` (`installments`): `organization_id`, `layaway_id`, `number`, `due_date`, `amount_cents`, `paid_cents`, `status`, `paid_at?`, `UNIQUE(organization_id, layaway_id, number)` (con el tenant delante desde `T-039`, [05 §4] bloque 7).
- `ScheduleGenerator` (dominio puro), `AmortizationSchedule`, `ScheduledInstallment`.

```php
final class ScheduleGenerator
{
    /**
     * Construye el cronograma a partir de las condiciones congeladas.
     *
     * El importe financiado se reparte en partes iguales; el residuo de la
     * división entera se acumula en la última cuota.
     *
     * No lanza por rangos: `InvalidScheduleException` sale de `new LayawayTerms(…)`.
     */
    public function generate(
        LayawayTerms $terms,
        CarbonImmutable $activatedAt,
        DateTimeZone $organizationTimezone,
    ): AmortizationSchedule { /* … */ }
}
```

> **Reparte el total, nunca multipliques un reparto (fijado en `T-041`).** `Money::allocateEvenly()` reparte con división entera y deja el resto en la última parte, así que **no es lineal**: $50 financiados por unidad en 3 cuotas son `1666, 1666, 1668`, y por dos unidades daría `3332, 3332, 3336`; los $100 del apartado en 3 cuotas son `3333, 3333, 3334`. Un importe repartido es un resultado, no un ingrediente. **En ningún sitio puede existir una cuota por unidad multiplicada por la cantidad y presentada como la cuota real** —ni en el formulario de `T-045` cuando el cliente elige cantidad, ni en el correo, ni en el portal—: la cuota con cantidad se pide a `LayawayTerms::financingPlan()` con la cantidad puesta. La ficha del producto sí enseña el plan de **una** unidad, porque ahí la cantidad aún no se ha elegido, y es correcto que con `quantity > 1` difiera del cronograma.
>
> **`LayawayTerms` y `FinancingPlan` no reparten dos veces.** `LayawayTerms::financingPlan()` construye el `FinancingPlan` del **apartado entero** —total e inicial ya multiplicados— y de él salen `financedAmount()` y las cuotas de `ScheduleGenerator`, así que en el sistema hay una sola resta y un solo reparto, los de `FinancingPlan`. Con `quantity = 1` las entradas son las del producto y el resultado es idéntico por construcción; `ScheduleGeneratorTest` lo afirma importe a importe, residuo incluido, sobre 200 condiciones al azar (el caso que `T-035` dejó diferido). `FinancingPlan` es un plan que se puede pintar y exige financiado mayor que cero; `LayawayTerms` son condiciones que se pueden congelar, incluidas las de SP-2, y ahí `financingPlan()` devuelve `null` y el cronograma sale vacío.
>
> **Los rangos de RB-7 se comprueban al congelar las condiciones, no al generar.** El contrato de arriba ponía `@throws InvalidScheduleException` en `generate()`, pero el cronograma se genera al aprobar la inicial, días después de crear el apartado: comprobar ahí dejaría crear un apartado imposible, cobrar su inicial y fallar con el dinero ya dentro. `new LayawayTerms(…)` lanza `InvalidScheduleException` con el rango en el mensaje, lo que adelanta el fallo a `CreateLayawayAction` (`T-045`), donde todavía es un `422`, y `generate()` no puede recibir unas condiciones fuera de rango. **El censo de los rangos vive en `ScheduleGenerator`** (`MIN_INSTALLMENTS`…`MAX_FREQUENCY_DAYS`), y `CoherentFinancingTerms` (`T-027`), que llevaba su copia, lee desde `T-041` esas constantes.

**Reglas de negocio y precondiciones**
- RB-1 El cronograma se **materializa al activar** el apartado, no al crearlo.
- RB-2 Importe financiado = `total − totalDownPayment`, repartido con `Money::allocateEvenly()`; el residuo cae en la **última** cuota (`REQ-FX-005`).
- RB-3 `due_date` de la cuota N = `activatedAt + (N × frequencyDays)` días calendario, calculado en la **zona horaria de la organización** (`REQ-ORG-006` RB-7). La cuota 1 vence a los `frequencyDays` de la activación, no el mismo día.
- RB-4 Las fechas **no** se desplazan por domingo ni por feriado.
- RB-5 Invariante dura: `sum(installments.amount_cents) === total_cents − totalDownPayment`, verificada por test de propiedad y por `layaways:reconcile`.
- RB-6 Las cuotas son inmutables en importe; solo `RescheduleInstallmentAction` mueve `due_date` (`REQ-LAY-011`).
- RB-7 `frequencyDays` ∈ [1, 90]; `installmentsCount` ∈ [1, 36]. Fuera de rango → `InvalidScheduleException`. Los valores del apartado vienen del snapshot, cuyos defaults son `organization_settings.default_frequency_days` y `default_installments_count`.
- RB-8 Al generarse se fija `layaways.next_due_date` = la `due_date` de la primera cuota no pagada.

**Happy path**
1. Apartado de $200 con inicial $50, 5 cuotas quincenales; la inicial se aprueba el 10/09.
2. `ScheduleGenerator` reparte $150 en 5 cuotas de $30 con vencimientos 25/09, 10/10, 25/10, 09/11 y 24/11 en hora de Caracas.
3. Se insertan 5 `installments`, `next_due_date = 25/09` y el apartado pasa a `active`.

**Sad paths y edge cases**
- SP-1 Importe financiado no divisible (`$100 / 3`) → `3333`, `3333`, `3334` centavos; la suma es exactamente `10000`.
- SP-2 Financiado 0 (inicial = precio total) → el apartado pasa directamente a `completed` al aprobar la inicial, sin generar cuotas.
- SP-3 `installmentsCount = 1` → una sola cuota con todo el financiado.
- SP-4 Vencimiento en 29 de febrero o fin de mes → sin problema: la frecuencia es en días, no en meses, y así se documenta.
- SP-5 Reintento del job de activación → idempotente por `UNIQUE(organization_id, layaway_id, number)`.
- SP-6 Cronograma ya existente al activar → la acción aborta con excepción y no duplica.
- SP-7 Organización en otra zona horaria → las fechas se calculan en la suya, no en UTC ni en la del servidor.
- SP-8 Cambio del `default_frequency_days` de la organización tras activar → irrelevante: el apartado usa su snapshot (`REQ-LAY-002`).

**Fuera de alcance inmediato**
- Cuotas de importe desigual definidas a mano.
- Frecuencia mensual real (mismo día de cada mes).
- Calendario de feriados.
- Intereses o comisiones sobre el financiado.

**Gate de validación**
- Superficie esperada: `app/Domain/Layaway/ScheduleGenerator.php`, `app/Domain/Layaway/AmortizationSchedule.php`, `app/Domain/Layaway/ScheduledInstallment.php`, `app/Models/Installment.php`, `app/Actions/Layaway/ActivateLayawayAction.php`.
- Tipos esperados: `ScheduleGenerator`, `AmortizationSchedule`, `ScheduledInstallment`, `Installment`, `InstallmentStatus`, `InvalidScheduleException`.
- Anti-`SHALLOW`: `ScheduleGenerator::generate()` es el método > 4 líneas del archivo que declara la clase; `AmortizationSchedule::totalAmount()` y `::firstUnpaid()` acompañan a su tipo.
- Tests: invariante de suma con 500 combinaciones aleatorias; residuo en la última cuota; fechas exactas con frecuencia 7/15/30; dos organizaciones con zonas distintas producen fechas distintas para la misma activación; financiado 0 no genera cuotas; idempotencia; rangos inválidos rechazados.

---

### REQ-LAY-005 · Máquina de estados del apartado

**Historia de usuario**
> Como negocio, quiero que cada apartado tenga un estado inequívoco y transiciones controladas, para que mi cobranza y mis reportes sean fiables.

**Contratos de datos**
- `LayawayStatus` con `allowedTransitions()`, `isTerminal()`, `acceptsPayments()`, `countsTowardPlanLimit()`.
- `LayawayStateMachine::transition(Layaway $layaway, LayawayStatus $to): void`.
- Campos `activated_at`, `completed_at`, `cancelled_at`, `expires_at`, `delivered_at`.

> **Cómo se impide escribir el estado fuera de la máquina (decidido en `T-042`).** RB-2 no se queda en una frase: está impedido en **dos capas**, y cada una cubre lo que la otra no ve.
>
> 1. **La guarda del modelo `Layaway`.** Un `saving` que **lanza** si se guarda un apartado con `status` cambiado, o si se crea en un estado que no sea `pending_down_payment` (`REQ-LAY-001` RB-7). Cubre `save()`, `forceFill()->save()` y la asignación directa; `status` además **no es asignable en masa**, y `LayawayStateMachineTest` lo afirma para que `T-045`, al declarar `$fillable`, no lo incluya.
> 2. **`LayawayStatusWritesTest`**, en `tests/Feature/Quality` —el gate más barato—: lo que no pasa por un modelo (`update()` del *query builder*, `DB::table('layaways')`, SQL escrito a mano) no dispara eventos de Eloquent, así que un test de arquitectura recorre `app/` y rechaza cualquier escritura de `status` en un archivo que hable de apartados, salvo en `LayawayStateMachine`. El detector lleva sus propios casos positivos y negativos, para no pasar en verde sobre un conjunto vacío.
>
> La máquina escribe por el *query builder* precisamente porque es la única escritura que la primera capa no rechaza y la única que la segunda admite. Si alguien escribe el estado en otro sitio, se pone rojo el test de arquitectura (*query builder*) o lanza `LogicException` en el primer test que ejercite ese código (modelo).
>
> **Qué viaja con cada transición**, todo dentro de `transition()` y en una transacción con la fila bloqueada: el estado; la marca —`activated_at` **solo al salir de `pending_down_payment`** (la vuelta desde `overdue` no reescribe la fecha de activación), `completed_at` y `cancelled_at`—; el cupo; y el evento. Entrar en mora, salir de ella y expirar no tienen columna en [05 §4]: su instante queda en `updated_at` y en el `occurredAt` del evento, y `expires_at` —que es el **plazo** de la inicial, no el momento en que expiró— no se toca. Los eventos son seis, uno por transición y todos `ShouldDispatchAfterCommit`: `LayawayActivated`, `LayawayBecameOverdue`, `LayawayCaughtUp` (la vuelta de RB-4), `LayawayCompleted`, `LayawayCancelled` y `LayawayExpired`, con identificadores y no el modelo, para que un listener encolado no tenga que rehidratarlo sin tenant.
>
> **Y desde `T-044`, el stock** (`REQ-LAY-003` RB-2): la transición a `expired` o a `cancelled` llama a `ReleaseStockAction` en la misma transacción, y **bloquea el producto antes que el apartado** (RB-8). Va aquí por lo mismo que el cupo: la expiración (`T-047`) y la cancelación (`T-053`) cambian el estado llamando a la máquina, y ninguna de las dos puede olvidarse de devolver la unidad. Completar no libera: la unidad se entregó.
>
> **El cupo se mueve con `LimitEnforcer::adjust()`**, que bloquea la fila de `usage_counters` dentro de la transacción, **nunca la deja negativa** y, si lo que se libera no estaba contado, la deja en cero y registra `usage_counter.below_zero` sin impedir la transición (SP-7, `REQ-SUB-003` RB-8). No pregunta por la suscripción (RB-9). Es el mismo método con el que `T-045` subirá el contador al crear.

**Reglas de negocio y precondiciones**
- RB-1 Transiciones permitidas:
  | Desde | Hacia |
  |---|---|
  | `pending_down_payment` | `active`, `expired`, `cancelled` |
  | `active` | `overdue`, `completed`, `cancelled` |
  | `overdue` | `active`, `completed`, `cancelled` |
  | `completed` | — (terminal; marcar entregado no es transición) |
  | `cancelled` | — (terminal) |
  | `expired` | — (terminal) |
- RB-2 Cualquier transición no listada lanza `IllegalLayawayTransitionException`; **ninguna** escritura de estado ocurre fuera de la máquina.
- RB-3 `acceptsPayments()` es verdadero en `pending_down_payment`, `active` y `overdue`; falso en los terminales.
- RB-4 `overdue` ⇄ `active` es bidireccional: al cubrirse todas las cuotas vencidas, vuelve a `active` automáticamente (`REQ-PAY-005`).
- RB-5 `completed` se alcanza cuando `remaining_cents <= organization_settings.settlement_tolerance_cents` (`REQ-FX-005`).
- RB-6 **`countsTowardPlanLimit()`** es verdadero en `pending_down_payment`, `active` y `overdue`; falso en los terminales. Toda transición **ajusta el consumo** `active_layaways` en la misma transacción (`REQ-SUB-003` RB-3): completar o cancelar libera cupo.
- RB-7 Cada transición registra su marca de tiempo y emite un evento de dominio (`LayawayActivated`, `LayawayCompleted`, …) que alimenta notificaciones y broadcast, siempre `afterCommit`.
- RB-8 Los estados terminales son inmutables: no existe reapertura.
- RB-9 **Las transiciones funcionan con la organización suspendida** (`REQ-SUB-007` RB-3): la operación del apartado nunca se detiene por el impago del negocio.

**Happy path**
1. `pending_down_payment` → se aprueba la inicial → `active`, con cronograma y `activated_at`.
2. Vence una cuota y pasan los días de gracia → `overdue`.
3. El cliente paga lo vencido → vuelve a `active`.
4. Paga la última cuota → `completed`, con `completed_at`, y se libera cupo del plan.

**Sad paths y edge cases**
- SP-1 Intento de activar un `expired` → excepción; el cliente debe crear uno nuevo.
- SP-2 Intento de cancelar un `completed` → excepción con mensaje claro.
- SP-3 Dos transiciones concurrentes → la fila del apartado se bloquea con `lockForUpdate` en toda acción que transicione.
- SP-4 Pago parcial insuficiente en `overdue` → permanece `overdue`; no hay estado intermedio.
- SP-5 Apartado que se completa teniendo cuotas vencidas → `completed` gana; no queda `overdue` residual.
- SP-6 Listener de notificación que falla → no revierte la transición (encolado y aislado).
- SP-7 Transición que libera cupo cuando el plan ya bajó → el consumo baja normalmente; nunca queda negativo (`REQ-SUB-003` RB-8).

**Fuera de alcance inmediato**
- Reapertura de apartados terminales.
- Estado "en disputa" o "en negociación".
- Historial completo de transiciones en tabla propia (parcialmente cubierto por `activity_log`).

**Gate de validación**
- Superficie esperada: `app/Domain/Layaway/LayawayStatus.php`, `app/Domain/Layaway/LayawayStateMachine.php`, `app/Events/Layaway/*.php`.
- Tipos esperados: `LayawayStatus`, `LayawayStateMachine`, `IllegalLayawayTransitionException`, eventos de dominio.
- Anti-`SHALLOW`: `LayawayStatus::allowedTransitions()` (mapa completo) junto al enum; `LayawayStateMachine::transition()` valida, escribe la marca, ajusta el consumo del plan y despacha el evento.
- Tests: matriz 6×6 de transiciones; `acceptsPayments()` y `countsTowardPlanLimit()` por estado; el consumo del plan se ajusta en cada transición; concurrencia bloquea; `completed` prevalece sobre `overdue`; fallo de listener no revierte; funciona con la organización suspendida.

---

### REQ-LAY-006 · Expiración por falta de cuota inicial

**Historia de usuario**
> Como negocio, quiero que los apartados sin pago inicial se liberen solos, para que mi inventario no quede bloqueado por reservas que nadie va a pagar.

**Contratos de datos**
- Campo `layaways.expires_at` (fijado al crear), `extended_at`.
- Job programado horario `ExpireLayawaysJob` → `ExpireLayawaysAction`.
- Ajuste `organization_settings.down_payment_window_hours` (72).

**Reglas de negocio y precondiciones**
- RB-1 Un apartado en `pending_down_payment` con `expires_at < now()` y **sin ningún comprobante en revisión ni aprobado** pasa a `expired`, libera stock y libera cupo del plan.
- RB-2 Si existe un comprobante en `pending_review`, el apartado **no** expira: la ventana se congela hasta que el negocio resuelva. No se castiga al cliente por la demora del negocio.
- RB-3 Si el comprobante se rechaza y `expires_at` ya pasó, se otorga una **prórroga de 24 h** desde el rechazo, una sola vez, marcada en `extended_at`.
- RB-4 La expiración libera stock de forma idempotente (`REQ-LAY-003` SP-3).
- RB-5 Se notifica al cliente un aviso **12 h antes** de expirar y otro al expirar, por los canales disponibles (`REQ-NOT-003`).
- RB-6 El job corre cada hora y procesa por lotes con `chunkById`, **recorriendo todas las organizaciones** con `withoutTenantScope()` y fijando el tenant por apartado (`REQ-ORG-002` RB-5).
- RB-7 La ventana la define **cada organización** en sus ajustes; el job lee la de la organización de cada apartado, no un valor global.
- RB-8 Funciona con la organización suspendida (`REQ-LAY-005` RB-9).

**Happy path**
1. Apartado creado el 10/09 a las 10:00 con `expires_at` el 13/09 a las 10:00.
2. El 12/09 a las 22:00 se envía el aviso de "quedan 12 horas".
3. El cliente no sube nada; el 13/09 a las 10:xx el job lo pasa a `expired`, devuelve el stock, libera cupo y notifica.

**Sad paths y edge cases**
- SP-1 Comprobante subido a las 09:59 del 13/09 → no expira; queda en espera de revisión, con alerta por antigüedad en la bandeja del negocio.
- SP-2 Comprobante rechazado el 13/09 a las 11:00 → prórroga hasta las 11:00 del 14/09.
- SP-3 Segunda prórroga → no se concede; `extended_at` ya está fijado.
- SP-4 Job solapado → `WithoutOverlapping` + idempotencia de la liberación.
- SP-5 Cambio del ajuste de 72 h a 48 h → afecta **solo** a apartados nuevos; los existentes conservan su `expires_at` (`REQ-ORG-006` RB-4).
- SP-6 Apartado ya `active` con `expires_at` pasado → ignorado por el filtro de estado; queda como dato histórico.
- SP-7 Job procesando apartados de 500 organizaciones → el tenant se fija y se limpia por apartado, sin fuga entre iteraciones (`REQ-ORG-002` SP-7).

**Fuera de alcance inmediato**
- Prórroga manual por el negocio.
- Ventana de expiración por producto o por catálogo.

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/ExpireLayawaysAction.php`, `app/Jobs/ExpireLayawaysJob.php`, `routes/console.php`, `app/Notifications/LayawayExpiringNotification.php`.
- Tipos esperados: `ExpireLayawaysAction`, `ExpirationOutcome`, `layaways.extended_at`.
- Anti-`SHALLOW`: `ExpireLayawaysAction::handle()` (filtro por estado y fecha, exclusión por comprobante pendiente, prórroga, liberación de stock y cupo, notificación, cambio de tenant por apartado) supera 4 líneas.
- Tests: expira sin comprobantes; no expira con comprobante en revisión; rechazo tardío concede 24 h una sola vez; idempotencia del stock; libera cupo del plan; cada apartado usa la ventana de **su** organización; el tenant se limpia entre iteraciones (fuga); funciona con organización suspendida.

---

### REQ-LAY-007 · Detección de mora y período de gracia

**Historia de usuario**
> Como negocio, quiero ver qué clientes están realmente en mora tras el margen de tolerancia, para priorizar a quién contactar.

**Contratos de datos**
- Job programado diario `DetectOverdueLayawaysJob` → `DetectOverdueAction`.
- `InstallmentStatus::Overdue`, `LayawayStatus::Overdue`.
- Ajuste `organization_settings.grace_period_days` (3).

**Reglas de negocio y precondiciones**
- RB-1 Una cuota es `overdue` cuando `due_date + grace_period_days < hoy` (en la zona de la organización) y `paid_cents < amount_cents`.
- RB-2 Un apartado es `overdue` cuando tiene **al menos una** cuota `overdue`.
- RB-3 **No** hay intereses, recargos ni penalizaciones: la mora es un estado y un disparador de notificaciones.
- RB-4 Una cuota parcialmente pagada sigue siendo `overdue` si no está cubierta al 100 % pasada la gracia.
- RB-5 El job es idempotente y corre una vez al día a las 06:00; recalcula estados de cuotas y de apartados en lote, **por organización**, usando la gracia y la zona horaria de cada una.
- RB-6 Al cubrirse todas las cuotas vencidas, el apartado vuelve a `active` en la misma transacción del pago (`REQ-PAY-005`), sin esperar al job.
- RB-7 A partir de `organization_settings.overdue_alert_days` (30) de mora, el apartado aparece en la alerta "mora prolongada" del dashboard del negocio, sin cambio de estado ni cancelación automática.
- RB-8 El job corre con la organización suspendida: la detección de mora es parte de la operación del apartado (`REQ-SUB-007` RB-3).

**Happy path**
1. Cuota con vencimiento 25/09 y gracia de 3 días.
2. El 29/09 a las 06:00 el job la marca `overdue` y pasa el apartado a `overdue`.
3. Se dispara la notificación de mora +1 (`REQ-NOT-002`).

**Sad paths y edge cases**
- SP-1 Pago el 28/09 (dentro de la gracia) → la cuota nunca se marca `overdue`.
- SP-2 Pago parcial de $10 sobre una cuota de $30 pasada la gracia → sigue `overdue` con `paid_cents = 1000`.
- SP-3 Cambio de la gracia de 3 a 5 días → el siguiente job recalcula y puede **devolver** cuotas de `overdue` a `pending`.
- SP-4 Apartado `completed` con cuotas históricas vencidas → el job ignora los estados terminales.
- SP-5 Job que no corre por caída del worker → al reanudarse recalcula todo desde cero.
- SP-6 Dos organizaciones con zonas horarias distintas → cada una se evalúa en la suya; "hoy" significa lo mismo que para su cliente.
- SP-7 Organización con gracia de 0 días → válido: la cuota es `overdue` el día siguiente al vencimiento.

**Fuera de alcance inmediato**
- Intereses de mora, recargos y refinanciación.
- Cancelación automática por mora prolongada.
- Score de riesgo del cliente.

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/DetectOverdueAction.php`, `app/Jobs/DetectOverdueLayawaysJob.php`, `app/Domain/Layaway/InstallmentStatus.php`, `app/Models/Installment.php` (scopes).
- Tipos esperados: `InstallmentStatus`, `DetectOverdueAction`, `OverdueSummary`.
- Anti-`SHALLOW`: `DetectOverdueAction::handle()` (recálculo por organización con su gracia y su zona, en lote) supera 4 líneas; `Installment::scopeOverdue()` junto al modelo.
- Tests: gracia respetada al día exacto; pago parcial sigue vencido; ampliar la gracia devuelve cuotas a `pending`; terminales ignorados; idempotencia; **dos organizaciones con gracias y zonas distintas se evalúan por separado** (test explícito de RB-5); funciona con organización suspendida.

---

### REQ-LAY-008 · Liquidación anticipada

**Historia de usuario**
> Como cliente, quiero pagar todo el saldo restante de una vez, para terminar antes y llevarme el producto.

**Contratos de datos**
- El portal expone `remaining` y un CTA "liquidar" (`REQ-LAY-021`).
- El pago se realiza por el flujo normal de comprobante (`REQ-PAY-001`).

**Reglas de negocio y precondiciones**
- RB-1 Permitida en cualquier momento por el saldo pendiente total, **sin descuento** por prepago.
- RB-2 El portal muestra el saldo exacto en USD y su equivalente en bolívares a la tasa vigente **de esa organización**, con la advertencia de que el equivalente puede variar al momento del pago.
- RB-3 Un pago que cubre el saldo completo se aplica FIFO, cierra todas las cuotas y el apartado pasa a `completed`, liberando cupo del plan.
- RB-4 Un pago que excede el saldo → se acepta hasta el saldo; el excedente **no** genera crédito ni se devuelve automáticamente (`REQ-PAY-005` RB-6).
- RB-5 La liquidación no altera el snapshot ni el importe original de las cuotas: solo las marca pagadas.

**Happy path**
1. El cliente ve "Saldo pendiente: $90.00 (Bs. 3.825,00)" y el CTA "Liquidar todo".
2. Sube un comprobante por el equivalente de $90.
3. Al aprobarse, las 3 cuotas restantes quedan `paid` y el apartado pasa a `completed`.

**Sad paths y edge cases**
- SP-1 La tasa cambia entre la consulta y el pago → el negocio ajusta al aprobar y, si el residuo queda bajo la tolerancia, se considera liquidado (`REQ-FX-005`).
- SP-2 Excedente significativo → el negocio concilia el saldo exacto y registra el excedente en la nota; su devolución se gestiona fuera del sistema.
- SP-3 Liquidación de un apartado `overdue` → permitida; al cubrirse todo pasa a `completed`.
- SP-4 Dos comprobantes que juntos exceden el saldo → el primero aprobado consume el saldo; el segundo se aprueba por el residuo o se rechaza con "saldo ya cubierto".
- SP-5 Liquidación desde `pending_down_payment` → se trata como inicial más financiado: el apartado se activa y se completa en la misma transacción, generando el cronograma para dejar trazabilidad.
- SP-6 Organización que cambió de fuente de tasa entre la consulta y el pago → el equivalente mostrado difiere; la conciliación usa la tasa del día de la aprobación (`REQ-FX-006`).

**Fuera de alcance inmediato**
- Descuento por pronto pago.
- Cálculo automático de devolución de excedentes.
- Pago en línea del saldo.

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/ApproveReceiptAction.php`, `app/Domain/Payment/FifoAllocator.php`, `resources/js/pages/portal/LayawayShow.tsx`.
- Tipos esperados: `AllocationPlan`, `LayawaySummary.remaining`.
- Anti-`SHALLOW`: `FifoAllocator::plan()` cubre el reparto que cierra todas las cuotas (> 4 líneas, junto a `AllocationPlan`).
- Tests: pago del saldo exacto completa y libera cupo; excedente se concilia al saldo; liquidación desde `overdue` completa; desde `pending_down_payment` activa y completa generando cronograma; residuo bajo tolerancia completa.

---

### REQ-LAY-009 · Cancelación por el negocio

**Historia de usuario**
> Como negocio, quiero cancelar un apartado que el cliente abandonó, para liberar el producto y cerrar el caso con constancia del motivo.

**Contratos de datos**
- `CancelLayawayAction(Layaway $layaway, User $actor, string $reason)`.
- Ruta `POST /backoffice/apartados/{layaway}/cancelar`; campos `cancelled_at`, `cancellation_reason`.

**Reglas de negocio y precondiciones**
- RB-1 La cancelación es **exclusivamente manual** por un usuario de la organización (`owner` o `member`); no hay cancelación automática por mora.
- RB-2 Motivo obligatorio, 10–500 caracteres, auditado (`REQ-BOF-005`).
- RB-3 Solo desde `pending_down_payment`, `active` u `overdue`.
- RB-4 Libera stock (`REQ-LAY-003`), libera cupo del plan (`REQ-LAY-005` RB-6) y notifica al cliente.
- RB-5 **No** se calcula reembolso ni penalización: los pagos conciliados permanecen en el historial y `paid_cents` queda intacto para constancia. La resolución económica ocurre fuera del sistema.
- RB-6 Las cuotas pendientes quedan como estaban; el apartado cancelado deja de aparecer en los filtros de cobranza.
- RB-7 La UI exige confirmación mostrando cuánto había pagado el cliente, para evitar cancelaciones accidentales de apartados con abonos.
- RB-8 El motivo se muestra **íntegro al negocio** y **no al cliente**, que ve un texto neutro (`REQ-LAY-021` SP-4).
- RB-9 **Se puede cancelar con la organización suspendida**: es parte de la gestión de la cobranza que `REQ-SUB-007` RB-4 preserva.

**Happy path**
1. El negocio filtra por "mora > 30 días" y abre un apartado con 2 de 5 cuotas pagadas.
2. Pulsa "Cancelar", escribe el motivo y confirma tras ver "el cliente ha pagado $60.00".
3. Se registra `cancelled_at`, se libera stock y cupo, se audita y se notifica al cliente.

**Sad paths y edge cases**
- SP-1 Cancelar un `completed` o ya `cancelled` → `422` por la máquina de estados.
- SP-2 Motivo demasiado corto → `422`.
- SP-3 Comprobante en `pending_review` al cancelar → se rechaza automáticamente con código `layaway_cancelled` y se notifica; no quedan comprobantes huérfanos en la bandeja.
- SP-4 Cancelación concurrente con la aprobación de un comprobante → el bloqueo serializa; la segunda falla con estado inválido.
- SP-5 Cliente que sube un comprobante tras la cancelación → rechazado en validación (`acceptsPayments()` falso).
- SP-6 Cancelación sin ningún pago → misma operación, sin confirmación reforzada.
- SP-7 Apartado de otra organización por manipulación de la URL → `404` por el binding filtrado (`REQ-ORG-002` RB-6).

**Fuera de alcance inmediato**
- Reembolsos, notas de crédito y retención contractual.
- Cancelación solicitada por el cliente desde el portal.

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/CancelLayawayAction.php`, `app/Http/Requests/Backoffice/CancelLayawayRequest.php`, `app/Policies/LayawayPolicy.php`, `resources/js/features/layaway/components/CancelLayawayDialog.tsx`.
- Tipos esperados: `CancelLayawayAction`, `CancellationData`, `IllegalLayawayTransitionException`.
- Anti-`SHALLOW`: `CancelLayawayAction::handle()` (transición, liberación de stock y cupo, rechazo de comprobantes pendientes, auditoría, notificación) supera 4 líneas.
- Tests: cancela desde los tres estados válidos y falla en terminales; motivo corto rechazado; rechaza comprobantes pendientes; libera stock y cupo; `paid_cents` preservado; apartado de otra organización da `404`; funciona con organización suspendida.

---

### REQ-LAY-010 · Entrega del producto

**Historia de usuario**
> Como negocio, quiero marcar cuándo entregué el producto, para saber qué apartados completados ya despaché.

**Contratos de datos**
- Campo `layaways.delivered_at`; `MarkDeliveredAction`.
- Ruta `POST /backoffice/apartados/{layaway}/entregar`.

**Reglas de negocio y precondiciones**
- RB-1 Solo se marca entregado un apartado en `completed`.
- RB-2 `delivered_at` es de un solo sentido: una vez fijado no se limpia.
- RB-3 Marcar la entrega **no** es una transición de estado: `completed` sigue siendo el estado y no cambia el consumo del plan.
- RB-4 Se audita y se notifica al cliente por los canales disponibles.
- RB-5 El backoffice ofrece el filtro "completados sin entregar" como cola de trabajo (`REQ-BOF-042` RB-2).
- RB-6 Sin dirección de envío, logística ni tracking: se asume retiro en tienda.

**Happy path**
1. El apartado pasa a `completed` al aprobarse la última cuota.
2. Aparece en el filtro "completados sin entregar".
3. El cliente retira el producto y el negocio pulsa "Marcar entregado".

**Sad paths y edge cases**
- SP-1 Marcar entregado un `active` u `overdue` → `422`.
- SP-2 Doble pulsación → idempotente: si `delivered_at` existe, no cambia nada y responde con éxito.
- SP-3 Apartado cancelado con producto entregado por error → no se puede marcar; queda como incidencia fuera del sistema.
- SP-4 Apartado de otra organización → `404`.

**Fuera de alcance inmediato**
- Dirección de envío, transportista y tracking.
- Firma o comprobante de entrega.
- Deshacer la entrega.

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/MarkDeliveredAction.php`, `app/Models/Layaway.php` (scope `completedNotDelivered`), `app/Policies/LayawayPolicy.php`.
- Tipos esperados: `MarkDeliveredAction`, campo `delivered_at`.
- Anti-`SHALLOW`: `MarkDeliveredAction::handle()` (validación de estado, idempotencia, auditoría, notificación) supera 4 líneas.
- Tests: solo `completed` permite marcar; idempotencia; el estado y el consumo del plan no cambian; el filtro devuelve la cola correcta; apartado ajeno da `404`.

---

### REQ-LAY-011 · Reprogramación de cuotas

**Historia de usuario**
> Como negocio, quiero mover la fecha de una cuota cuando pacto algo con el cliente, para reflejar el acuerdo real sin alterar el monto de la deuda.

**Contratos de datos**
- `RescheduleInstallmentAction(Installment $installment, CarbonImmutable $newDueDate, User $actor, string $reason)`.
- Ruta `PATCH /backoffice/cuotas/{installment}/reprogramar`.

**Reglas de negocio y precondiciones**
- RB-1 Se mueve **solo la fecha** (`due_date`); el `amount_cents` de una cuota es inmutable.
- RB-2 Solo cuotas no pagadas (`pending`, `partially_paid`, `overdue`).
- RB-3 La nueva fecha debe ser futura y **no anterior** a la `due_date` de la cuota previa ni **posterior** a la de la siguiente, para no romper la monotonía del cronograma.
- RB-4 Motivo obligatorio (10–500 caracteres) y auditoría obligatoria: es la única operación que altera un cronograma materializado.
- RB-5 Tras reprogramar se recalculan `installment.status`, `layaway.status` y `next_due_date`.
- RB-6 **No** genera notificación automática al cliente en v1; el acuerdo se comunica por el chat.
- RB-7 No existe reprogramación masiva del cronograma completo.
- RB-8 Las fechas se interpretan en la zona horaria de la organización.

**Happy path**
1. El cliente pide por chat mover la cuota del 25/09 al 30/09.
2. El negocio abre el cronograma, pulsa "Reprogramar" en esa cuota, elige el 30/09 y escribe el motivo.
3. La cuota sale de `overdue`, el apartado vuelve a `active` y `next_due_date` se actualiza.

**Sad paths y edge cases**
- SP-1 Fecha que cruza la cuota siguiente → `422` con el rango válido explícito.
- SP-2 Fecha en el pasado → `422`.
- SP-3 Cuota ya pagada → `422`.
- SP-4 Reprogramación de la última cuota más allá de cualquier límite → permitida sin tope superior.
- SP-5 Reprogramación concurrente de dos cuotas contiguas → el bloqueo del apartado serializa; la segunda se valida contra el estado actualizado.
- SP-6 Reprogramar todas las cuotas una a una para "refinanciar" → posible pero no soportado como flujo; queda constancia en auditoría.
- SP-7 Cuota de otra organización → `404`.

**Fuera de alcance inmediato**
- Refinanciación y regeneración del cronograma.
- Cambio del importe de una cuota.
- Reprogramación solicitada por el cliente.

**Gate de validación**
- Superficie esperada: `app/Actions/Layaway/RescheduleInstallmentAction.php`, `app/Http/Requests/Backoffice/RescheduleInstallmentRequest.php`, `app/Domain/Layaway/AmortizationSchedule.php`.
- Tipos esperados: `RescheduleInstallmentAction`, `InvalidRescheduleException`.
- Anti-`SHALLOW`: `RescheduleInstallmentAction::handle()` (validación de ventana, escritura, recálculo de estados, auditoría) y `AmortizationSchedule::validMoveWindow()` superan 4 líneas.
- Tests: ventana válida entre cuotas contiguas; cruce rechazado; fecha pasada rechazada; cuota pagada rechazada; última cuota sin tope; salida de `overdue` recalculada; cuota ajena da `404`; fechas en la zona de la organización.
