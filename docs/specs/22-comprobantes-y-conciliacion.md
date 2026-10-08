# Módulo 22 — Comprobantes, pagos y balances (`PAY`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md), [13-bimoneda-y-tasa-de-cambio.md](13-bimoneda-y-tasa-de-cambio.md), [20-apartado-y-cronograma.md](20-apartado-y-cronograma.md)
> Requisitos: `REQ-PAY-001` … `REQ-PAY-009`
>
> **Módulo crítico de integridad.** Toda mutación de saldo ocurre en una única acción transaccional; ninguna otra parte del sistema escribe `paid_cents` ni `remaining_cents`.
>
> **Cambio importante del pivote:** la unicidad de comprobantes pasa a ser **por organización** (`REQ-PAY-003` RB-4). Dos negocios distintos pueden legítimamente recibir la misma referencia bancaria, y bloquearlo globalmente habría impedido cobros reales. Además, el cliente ahora declara **a qué cuenta de cobro** pagó (`REQ-ORG-007`).

## Contratos compartidos del módulo

```php
enum PaymentMethod: string
{
    case PagoMovil = 'pago_movil';
    case Transferencia = 'transferencia';
    case Zelle = 'zelle';
    case BinancePay = 'binance_pay';
    case EfectivoUsd = 'efectivo_usd';
    case EfectivoVes = 'efectivo_ves';

    public function requiresReference(): bool { /* … */ }
    public function defaultCurrency(): Currency { /* … */ }
    /** Qué contiene el identifier de una cuenta de cobro de este método. */
    public function identifierLabel(): string { /* … */ }
}

enum ReceiptStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}

enum RejectionReasonCode: string
{
    case NotLegible = 'not_legible';
    case AmountMismatch = 'amount_mismatch';
    case ReferenceNotFound = 'reference_not_found';
    case Duplicated = 'duplicated';
    case WrongAccount = 'wrong_account';
    case LayawayCancelled = 'layaway_cancelled';
    case Other = 'other';

    public function label(): string { /* … */ }
}

/** Plan de aplicación FIFO de un importe sobre las cuotas de un apartado. */
final readonly class AllocationPlan
{
    /** @param list<array{installmentId:int, cents:int}> $allocations */
    public function __construct(
        public array $allocations,
        public int $unallocatedCents,
    ) {}

    public function allocatedCents(): int { /* … */ }
    public function isFullyAllocated(): bool { /* … */ }
}
```

---

### REQ-PAY-001 · Envío de comprobante por el cliente

**Historia de usuario**
> Como cliente, quiero reportar mi pago subiendo la captura de la transferencia e indicando a qué cuenta pagué, para que el negocio lo verifique y me descuente la cuota.

**Contratos de datos**
- Rutas `GET /mi-cuenta/apartados/{code}/comprobante` y `POST` de la misma.
- `StoreReceiptRequest` → `SubmitReceiptAction`.
- Entidad `Receipt` con `organization_id`, `payment_account_id?` y `status = pending_review` al crearse.

```php
final readonly class SubmitReceiptData
{
    public function __construct(
        public Layaway $layaway,
        public ?PaymentAccount $paymentAccount,   // a qué cuenta del negocio pagó
        public PaymentMethod $method,
        public ?string $bank,
        public ?string $reference,
        public CarbonImmutable $paidAt,
        public string $declaredAmount,
        public Currency $declaredCurrency,
        public ?string $note,
        public UploadedFile $file,
    ) {}
}
```

**Reglas de negocio y precondiciones**
- RB-1 Solo el titular del apartado, resuelto por `PortalContext` (`REQ-ACC-005` RB-3), puede subir comprobantes, y solo si `status.acceptsPayments()` es verdadero.
- RB-2 Campos obligatorios: método, fecha de pago, monto declarado, moneda y archivo. `bank` y `reference` son obligatorios salvo en métodos de efectivo (`PaymentMethod::requiresReference()`).
- RB-3 **`payment_account_id` identifica a qué cuenta del negocio dice haber pagado.** El formulario ofrece las cuentas activas de la organización (`REQ-ORG-007`) y preselecciona la única si hay una sola. Es información nueva del pivote y reduce mucho el trabajo de conciliación.
- RB-4 `paid_at` no puede ser futura ni anterior a la creación del apartado.
- RB-5 `declared_amount` > 0, máximo 2 decimales, persistido como string decimal **sin convertir todavía** a USD: la conversión ocurre al aprobar (`REQ-FX-006`).
- RB-6 El comprobante nace en `pending_review`; **no** afecta al balance ni a las cuotas hasta ser aprobado.
- RB-7 Un mismo apartado admite varios comprobantes en `pending_review` a la vez.
- RB-8 Al crearse se difunde a la bandeja del negocio (`REQ-PAY-004`) y se emite `receipt_uploaded` (`REQ-TLM-001`).
- RB-9 El formulario precarga el importe sugerido (cuota FIFO o saldo total) y su equivalente en bolívares a la tasa de esa organización, como ayuda, sin imponerlo.
- RB-10 **La subida de un comprobante de cliente nunca se rechaza por la cuota de almacenamiento del plan del negocio** (`REQ-SUB-008` RB-6): se acepta y se registra el exceso. Bloquearla rompería la cobranza y el perjudicado sería el cliente.
- RB-11 Funciona con la organización suspendida (`REQ-SUB-007` RB-3).
- RB-12 Rate limit de 10 comprobantes por hora y por ficha de cliente.

**Happy path**
1. El cliente entra al detalle del apartado y pulsa "Subir comprobante".
2. Elige la cuenta "Pago móvil · Banesco · 0414-1234567", el método, el banco, la referencia, la fecha de hoy, `850.00` en `VES`, adjunta la captura y ve la vista previa.
3. `SubmitReceiptAction` valida, calcula el `sha256`, comprueba duplicados **dentro de esa organización** (`REQ-PAY-003`), guarda el archivo en el disco privado bajo la ruta de la organización y crea el `Receipt` en `pending_review`.
4. La UI confirma "recibido, lo revisaremos" y el comprobante aparece en su historial.

**Sad paths y edge cases**
- SP-1 Archivo demasiado grande o de tipo no permitido → `422` con el límite explícito (`REQ-PAY-002`).
- SP-2 Referencia o archivo ya reportado **en esa organización** → `422` "este comprobante ya fue reportado" (`REQ-PAY-003`).
- SP-3 La misma referencia ya reportada en **otra** organización → **se acepta**: son negocios distintos (`REQ-PAY-003` RB-4).
- SP-4 Apartado en estado terminal → `403`.
- SP-5 `paid_at` futura → `422`.
- SP-6 Subida interrumpida → nada se persiste; el archivo se guarda dentro de la transacción y se elimina en el rollback.
- SP-7 Cuenta de cobro de otra organización enviada en el payload → `422` (`REQ-ORG-002` RB-7).
- SP-8 Negocio sin cuentas de cobro activas → el campo se omite y se permite subir sin él, indicándolo en la nota; se avisa al negocio (`REQ-ORG-007` SP-4).
- SP-9 Cuenta de cobro desactivada después de que el cliente pagó a ella → sigue seleccionable en el histórico y se puede referenciar; por eso las cuentas con comprobantes no se borran (`REQ-ORG-007` RB-6).
- SP-10 Monto declarado en `USD` con método `efectivo_ves` → permitido; el método sugiere la moneda, no la impone.
- SP-11 Doble envío → la unicidad del `sha256` por organización bloquea el segundo con el mismo adjunto.
- SP-12 Cuota de almacenamiento del negocio agotada → se acepta igual (RB-10).

**Fuera de alcance inmediato**
- OCR o lectura automática del comprobante.
- Verificación automática contra el banco.
- Reporte de pago sin adjunto.

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/SubmitReceiptAction.php`, `app/Http/Requests/Portal/StoreReceiptRequest.php`, `app/Http/Controllers/Portal/ReceiptController.php`, `app/Domain/Payment/PaymentMethod.php`, `resources/js/pages/portal/ReceiptCreate.tsx`, `resources/js/features/receipts/hooks/useReceiptForm.ts`.
- Tipos esperados: `Receipt`, `ReceiptStatus`, `PaymentMethod`, `SubmitReceiptData`, `DuplicateReceiptException`.
- Anti-`SHALLOW`: `SubmitReceiptAction::handle()` (hash, duplicados por organización, validación de la cuenta de cobro, almacenamiento, creación, broadcast, telemetría) y `PaymentMethod::requiresReference()`/`identifierLabel()` superan 4 líneas junto a sus tipos.
- Tests: crea en `pending_review` sin tocar el balance; exige referencia según método; la misma referencia en otra organización se acepta (test explícito de SP-3); cuenta de cobro ajena rechazada (fuga); `paid_at` futura rechazada; terminal rechazado; rollback elimina el archivo; **se acepta con la cuota de almacenamiento agotada** (test de RB-10); funciona con organización suspendida.

> **Cómo quedó, en `T-059`.** **El botón de la página de gracias del catálogo lleva al formulario del portal** (`/mi-cuenta/apartados/{code}/comprobante`): crear un apartado no abre sesión, así que sin ella `EnsurePortalSession` pide documento y código y `redirect()->intended()` vuelve al formulario. Un apartado que ya no cobra es `403` antes de validar (SP-4, `StoreReceiptRequest::authorize()`). La fecha de pago se acota en el **calendario del negocio**, del día de creación del apartado a hoy (RB-4), con los mismos bordes que recibe el formulario. El importe sugerido (RB-9) es `NextPayment` —el mismo del detalle del apartado—, en crudo en dólares y, con tasa, en bolívares a la de esa organización. El límite de RB-12 es el limitador `comprobantes`, **por ficha**. **Lo que no hace todavía**: emitir `receipt_uploaded` es de `T-084`. La difusión a la bandeja (RB-8) llegó con `T-060`: `ReceiptSubmitted` después del commit, y un fallo suyo no deshace la subida. **RB-10**: nada mide aún el almacenamiento (`T-066`), y el test afirma que la subida se acepta con el contador de `storage_mb` en el techo.

---

### REQ-PAY-002 · Almacenamiento privado y acceso firmado

**Historia de usuario**
> Como cliente, quiero que mi comprobante bancario no sea accesible públicamente ni por otros negocios, para que mis datos financieros no queden expuestos.

**Contratos de datos**
- Disco `receipts` (`config/filesystems.php`), raíz `storage/app/private/receipts/{organization_id}/…`.
- `SignedFileUrl::forReceipt(Receipt $r, int $ttlMinutes): string`.
- Ruta `GET /archivos/comprobantes/{receipt}` con `signed` + policy.
- Campos `file_path`, `file_hash`, `file_mime`, `file_size`.

**Reglas de negocio y precondiciones**
- RB-1 Los archivos **nunca** se sirven desde `public/` ni por URL directa del disco; solo por una ruta autorizada que hace `stream`.
- RB-2 **Triple control**: la URL debe estar **firmada** (caducable), el solicitante debe pasar la **policy** (el cliente titular, o un usuario de **esa** organización) y el `Receipt` debe pertenecer a la organización resuelta. Un usuario de otro negocio nunca accede, ni con una URL firmada válida.
- RB-3 TTL: **5 minutos** para el cliente, **15 minutos** para el backoffice.
- RB-4 La ruta física incluye el `organization_id`, de modo que el aislamiento es también físico y auditable.
- RB-5 Restricciones de subida: `jpg`, `jpeg`, `png`, `webp`, `pdf`; máximo `platform_settings.receipt_max_size_mb` (5 MB); validación del **MIME real** por contenido, no por extensión ni por el `Content-Type` del cliente.
- RB-6 Las imágenes se recomprimen en cola (calidad 80, máx. 2000 px de lado mayor), conservando el original hasta que la recompresión tenga éxito.
- RB-7 El nombre en disco es un UUID + extensión; el nombre original del cliente no se usa en el sistema de archivos.
- RB-8 Los archivos **no se borran nunca** desde la aplicación, ni al rechazar el comprobante (evidencia), ni al degradar el plan (`REQ-SUB-008` RB-1).
- RB-9 Respuesta con `Content-Disposition: inline` y `Cache-Control: private, no-store`.
- RB-10 El peso consume la cuota de almacenamiento del plan (`REQ-SUB-003` RB-2), pero **nunca bloquea la subida de un comprobante de cliente** (`REQ-PAY-001` RB-10).
- RB-11 El `PlatformAdmin` **no** accede a comprobantes sin un grant de soporte activo (`REQ-PLT-005`).

**Happy path**
1. El cliente sube una captura de 3 MB.
2. Se valida el MIME real, se guarda como `receipts/42/9f3c…-a1.jpg` y se encola la recompresión.
3. Al abrir el historial, la UI recibe una URL firmada de 5 minutos que sirve el archivo por `stream`.

**Sad paths y edge cases**
- SP-1 Archivo con extensión `.jpg` y contenido ejecutable → rechazado por MIME real.
- SP-2 PDF de más de 5 MB → `422`, con la sugerencia de subir una captura.
- SP-3 URL firmada manipulada o caducada → `403`.
- SP-4 URL firmada válida pero de otro cliente → `403` por la policy.
- SP-5 **URL firmada válida abierta por un usuario de otra organización** → `403`; la firma sola no autoriza (test explícito de RB-2).
- SP-6 Archivo ausente en disco → `404` con log de error; el registro del comprobante permanece.
- SP-7 Recompresión que falla → se conserva el original y se registra el fallo.
- SP-8 Zip bomb o imagen de dimensiones absurdas → límite de píxeles validado antes de procesar.
- SP-9 `PlatformAdmin` sin grant → `403` (RB-11).

**Fuera de alcance inmediato**
- Antivirus y escaneo de malware.
- Marca de agua.
- Borrado por solicitud de derecho al olvido.

**Gate de validación**
- Superficie esperada: `config/filesystems.php`, `app/Support/SignedFileUrl.php`, `app/Http/Controllers/ReceiptFileController.php`, `app/Policies/ReceiptPolicy.php`, `app/Jobs/CompressReceiptImage.php`, `app/Rules/RealMimeType.php`.
- Tipos esperados: `SignedFileUrl`, `RealMimeType`, campos de archivo en `Receipt`.
- Anti-`SHALLOW`: `SignedFileUrl::forReceipt()` (TTL por audiencia + firma) y `ReceiptFileController::__invoke()` (firma, policy, pertenencia al tenant, existencia, `stream` con cabeceras) superan 4 líneas.
- Tests: URL sin firma `403`; firma caducada `403`; firma válida de otro cliente `403`; **firma válida de otra organización `403`**; MIME falso rechazado; archivo ausente `404`; cabeceras `no-store`; la ruta física incluye el `organization_id`; `PlatformAdmin` sin grant `403`.

> **Cómo quedó, en `T-059`.** Disco `receipts` (`storage/app/private/receipts`, `serve => false`), y `file_path` relativo a él: **`{organization_id}/{uuid}.{ext}`**, con la organización leída **del apartado** y no del contexto de la petición (RB-4, RB-7). Lo sirven dos rutas, una por audiencia —portal con URL de 5 min y backoffice con 15—, por los motivos de [05 §7.6]. Con ellas, **una firma válida abierta desde otra organización da `404` y no `403`**: lo exige `REQ-ORG-002` RB-6 para todo recurso ajeno, y no confirma que exista; el `403` queda para la firma ausente, caducada o manipulada y para la policy (otro cliente del mismo negocio). **El tope de 5 MB es una constante** (`ReceiptFile::MAX_SIZE_MB`) mientras no exista quien lea `platform_settings` (`T-086`), y un test se pone rojo el día que llegue. **La recompresión (RB-6) solo toca JPEG y PNG**: el GD del contenedor no codifica WebP, y un PDF no se recomprime; mide los píxeles antes de abrir la imagen (SP-8, 40 Mpx) y escribe a un temporal que solo sustituye al original si sale bien (SP-7). `file_hash` sigue siendo el del archivo subido (`REQ-PAY-003` RB-7). **RB-11 (`PlatformAdmin` sin grant) se deniega por construcción**: no hay modelo, guard ni ruta de plataforma todavía, y su caso de prueba es de `T-086`.

---

### REQ-PAY-003 · Detección de comprobantes duplicados

**Historia de usuario**
> Como negocio, quiero que el sistema bloquee un comprobante que ya reporté, para no acreditar dos veces el mismo pago.

**Contratos de datos**
- Restricciones `UNIQUE(organization_id, file_hash)` y `UNIQUE(organization_id, bank, reference)` en `receipts`.
- `DuplicateReceiptException` con el criterio que disparó el bloqueo.

**Reglas de negocio y precondiciones**
- RB-1 Bloqueo **duro** en la validación: un duplicado nunca llega a la bandeja del negocio.
- RB-2 Criterios: (a) `sha256` del archivo ya existente, o (b) la pareja (`bank`, `reference`) ya existente.
- RB-3 El criterio (b) no aplica cuando `reference` es nula (efectivo).
- RB-4 **La unicidad es por organización, no global.** Es el cambio más importante del pivote en este módulo: dos negocios distintos pueden recibir la misma referencia bancaria —los bancos venezolanos reciclan y truncan referencias— y bloquearlo globalmente habría impedido cobros legítimos y habría filtrado, además, que otra organización ya usó esa referencia.
- RB-5 Aplica también frente a comprobantes **rechazados** de la misma organización: un rechazo no libera la referencia. Si el rechazo fue un error, la vía es aprobar el original o registrar un pago manual (`REQ-PAY-007`).
- RB-6 El mensaje al cliente es explícito y no revela datos de otros clientes: "este comprobante ya fue reportado".
- RB-7 El `sha256` se calcula sobre el archivo **subido tal cual**, antes de cualquier recompresión.

**Happy path**
1. El cliente intenta subir por segunda vez la misma captura al mismo negocio.
2. `SubmitReceiptAction` calcula el hash, encuentra la coincidencia dentro de la organización y lanza `DuplicateReceiptException`.
3. La UI muestra el aviso y enlaza al comprobante ya registrado en su historial.

**Sad paths y edge cases**
- SP-1 Misma referencia en dos organizaciones → **permitido** (RB-4), y ninguna de las dos se entera de la otra.
- SP-2 Misma referencia de otro cliente **de la misma organización** → bloqueado; el cliente debe reportar por el chat y el negocio registra un pago manual.
- SP-3 Captura recortada o reguardada (hash distinto, mismo pago) → el hash no la detecta; la detecta el criterio (b) si la referencia coincide.
- SP-4 Referencia repetida por el propio banco dentro de un negocio → caso de SP-2; se resuelve con pago manual y nota.
- SP-5 Carrera con dos subidas simultáneas del mismo archivo → la restricción `UNIQUE` de la BD es la garantía final; la acción la convierte en `DuplicateReceiptException`.
- SP-6 Comprobante que cubre pagos de dos apartados → no soportado: un comprobante pertenece a un apartado; el negocio registra pagos manuales en cada uno.

**Fuera de alcance inmediato**
- Comparación perceptual de imágenes.
- Un comprobante aplicado a varios apartados.
- Conciliación automática contra extractos bancarios.

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/SubmitReceiptAction.php`, `app/Rules/UniqueReceiptFingerprint.php`, migración de `receipts` con las dos restricciones compuestas.
- Tipos esperados: `DuplicateReceiptException`, `UniqueReceiptFingerprint`, `DuplicateCriterion`.
- Anti-`SHALLOW`: `UniqueReceiptFingerprint::validate()` (dos criterios, acotados por organización, caso de referencia nula) supera 4 líneas junto a su clase.
- Tests: mismo archivo en la misma organización bloqueado; **mismo archivo y misma referencia en otra organización permitidos** (test explícito de RB-4); referencia nula no bloquea; rechazado no libera la referencia; carrera concurrente produce un solo registro; el mensaje no filtra datos ajenos.

> **Cómo quedó, en `T-059`.** La comprobación vive en dos sitios con la misma regla: `UniqueReceiptFingerprint` en el formulario —para que el error salga junto a su campo— y `SubmitReceiptAction` bajo el bloqueo del apartado, que además convierte el choque de cualquiera de los dos únicos en `DuplicateReceiptException` con su `DuplicateCriterion` (SP-5): la carrera la gana la base. El error vuelve al campo `file` (hash) o `reference` (banco + referencia) con «Este comprobante ya fue reportado.», sin nada de otros clientes (RB-6).

---

### REQ-PAY-004 · Bandeja de conciliación con alerta en tiempo real

**Historia de usuario**
> Como negocio, quiero enterarme al instante de que entró un comprobante y revisarlo con todo el contexto, para acreditar los pagos sin demora.

**Contratos de datos**
- Ruta `GET /backoffice/comprobantes` con filtros por estado, método, banco, cuenta de cobro y rango de fechas.
- Canal privado `organization.{id}.receipts`; evento `ReceiptSubmitted` con payload mínimo.
- Tabla server-driven (`REQ-BOF-045`).

**Reglas de negocio y precondiciones**
- RB-1 El canal es **por organización** (`organization.{id}.receipts`) y su autorización comprueba que el usuario del guard `web` pertenece a **esa** organización. Un usuario de otro negocio no puede suscribirse.
- RB-2 El payload difundido es mínimo (`id`, `layawayCode`, `declaredAmount`, `submittedAt`) y **no** incluye datos personales del cliente.
- RB-3 La llegada de un comprobante incrementa un badge y lanza un toast; **sin polling**.
- RB-4 Orden por defecto: `pending_review` primero y, dentro de ellos, el más antiguo arriba (cola FIFO de trabajo).
- RB-5 Cada fila muestra código del apartado, cliente (nombre y documento), método, banco, referencia, **cuenta de cobro declarada**, monto con su moneda, fecha declarada, antigüedad y miniatura.
- RB-6 El detalle de revisión muestra en una pantalla: el archivo ampliable, el cronograma del apartado, el saldo, los pagos previos, la cuenta de cobro declarada y el importe que el sistema aplicaría con FIFO.
- RB-7 Los comprobantes con más de 48 h en `pending_review` se marcan como atrasados.
- RB-8 La bandeja se acota por defecto a apartados no cancelados, con filtro para verlos todos.
- RB-9 **La bandeja funciona con la organización suspendida** (`REQ-SUB-007` RB-4): aprobar comprobantes es la operación que no se puede bloquear por impago.

**Happy path**
1. Un usuario del negocio tiene la bandeja abierta; entra un comprobante y aparece el toast con el badge en 1.
2. Abre el detalle: ve la captura, la cuenta a la que dice haber pagado, el cronograma con la cuota 3 pendiente de $30 y el aviso "se aplicará a la cuota 3".
3. Aprueba (`REQ-PAY-005`) sin salir de la pantalla.

**Sad paths y edge cases**
- SP-1 WebSocket no disponible → la bandeja funciona con recarga manual; el badge se actualiza en cada navegación Inertia y `@tanstack/react-query` hace polling de respaldo cada 30 s solo del contador.
- SP-2 Dos usuarios abriendo el mismo comprobante → ambos lo ven; el primero que resuelve gana y el segundo recibe "ya fue procesado por…".
- SP-3 Comprobante cuyo apartado se cancela mientras está en la bandeja → se marca `rejected` con código `layaway_cancelled` y sale de la cola (`REQ-LAY-009` SP-3).
- SP-4 Miles de comprobantes históricos → paginación de servidor sin `count(*)` total.
- SP-5 Miniatura de un PDF → icono de PDF, sin renderizar la primera página.
- SP-6 Broadcast que falla → no revierte la creación; el comprobante aparecerá en la próxima carga.
- SP-7 **Usuario de otra organización intentando suscribirse al canal** → rechazado por la autorización (test explícito de RB-1).
- SP-8 Comprobante cuya cuenta de cobro fue desactivada → se muestra igual, marcada como inactiva.

**Fuera de alcance inmediato**
- Asignación de comprobantes a un usuario concreto.
- SLA y métricas de tiempo de revisión.
- Aprobación masiva.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/ReceiptInboxController.php`, `app/Events/ReceiptSubmitted.php`, `routes/channels.php`, `resources/js/pages/backoffice/receipts/Inbox.tsx`, `resources/js/features/receipts/hooks/useReceiptInbox.ts`.
- Tipos esperados: `ReceiptSubmitted`, `ReceiptInboxRow`, `ReceiptInboxFilters`.
- Anti-`SHALLOW`: `ReceiptInboxController::__invoke()` (filtros, orden FIFO, paginación de servidor, marcado de atrasados) y la autorización del canal en `channels.php` superan 4 líneas.
- Tests: el canal rechaza a un usuario de otra organización y a un cliente; orden FIFO; marca de atrasado a las 48 h; cancelación saca el comprobante de la cola; fallo de broadcast no revierte; payload sin datos personales; funciona con organización suspendida.

> **Cómo quedó, en `T-060`.**
> - **El tiempo real, partido en dos por donde corta la dependencia de `T-079`.** Aquí: el evento `ReceiptSubmitted` (emitido por `SubmitReceiptAction` **después del commit**, encolado en `high`, construido con escalares), el canal `organization.{orgId}.receipts` en `routes/channels.php`, el endpoint `/broadcasting/auth` enrutado (`withBroadcasting`, `web` + `outside-tenancy`: es uno para los dos guards, y cada canal comprueba la pertenencia contra su nombre) y sus tests, incluida la firma por HTTP con un broadcaster que firma de verdad. En `T-079`: el cliente de Echo, el **toast** y el incremento en vivo del badge.
> - **RB-3 («sin polling») y SP-1 («polling de respaldo cada 30 s») no se contradicen, se turnan**: el sondeo es el respaldo de cuando el socket no está, y sin cliente de Echo el socket no está nunca. Hasta `T-079` el badge vive del respaldo de SP-1 —la cifra en cada navegación (`teamMember.pendingReceipts`) y `GET /backoffice/comprobantes/pendientes` cada 30 s, solo el contador—; `T-079` lo apaga mientras el socket esté conectado.
> - **El payload lleva `declaredCurrency` además de los cuatro campos de RB-2**: «850,00» sin moneda no se puede leer —dólares o bolívares, cuarenta veces de diferencia— y una moneda no es un dato personal. El test de las claves es exacto, y el de los datos personales busca en el mensaje serializado el nombre, el documento, el teléfono, el correo, el banco, la referencia, la nota y la cuenta de cobro.
> - **RB-1, el cliente, con dos cerrojos**: `guards => ['web']` en el canal —sin usuario de `web`, el broadcaster responde `403` antes de llamar a la autorización— y un `instanceof User` dentro. Además del miembro de otra organización (SP-7), se rechaza al **desactivado** ([05 §7.7] dice «miembro activo») y se exige `OrganizationCapability::Receipts`, la misma capacidad que abre el archivo. La suspensión no cierra el canal (RB-9).
> - **El detalle de revisión (RB-6) es de `T-061`**, con aprobar: el archivo ampliable, el cronograma, el saldo, los pagos previos y lo que aplicaría FIFO son una pantalla, y la que lo pinta es la que aprueba. **Hecho en `T-061`**: cada fila lleva «Revisar» a `receipts.show`; el código del apartado sigue llevando a su vista.
> - **La miniatura (RB-5) es el archivo firmado** de `SignedFileUrl::forBackoffice()` en un `<img loading="lazy">`: no hay miniaturas generadas, y la imagen ya llega recomprimida a 2000 px como mucho (`REQ-PAY-002` RB-6). Un PDF es un enlace con icono (SP-5). La URL caduca a los 15 min, así que **la bandeja se recarga sola a los 14** con la misma vista: si no, una imagen perezosa más abajo, o el enlace al archivo, darían `403` a quien mira tarde.
> - **El orden por defecto es la cola de RB-4**: pendientes primero y FIFO entre ellos, el resto del más reciente al más antiguo, con el `id` como desempate para que el orden sea total. RB-4 dice «por defecto», así que se puede pedir otro de la lista blanca de `REQ-BOF-045` (`ReceiptInboxSort`): por llegada o por fecha de pago, en los dos sentidos. **No por importe**: vienen en dólares y en bolívares sin convertir. Las dos expresiones `CASE` del orden por defecto no usan índice; si con miles de comprobantes pesa, se parte en dos consultas.
> - **El rango de fechas es de llegada** (`created_at`), no la fecha declarada, y en días del calendario del negocio: el `AAAA-MM-DD` se lee directamente en su zona. El motivo es que la bandeja es una cola de lo que **llega**, y la llegada es un hecho del sistema; `paid_at` lo escribe el cliente y es lo que se está verificando. Para cuadrar con un extracto, el orden por fecha de pago ya lo da. Si el negocio pide filtrar por fecha de pago, es un filtro más, no un cambio de este. **Atrasado** es «más de 48 h desde que llegó»: las 48 h justas todavía no.
> - **«Llegó algo» es un pendiente más nuevo que el más nuevo que pintó la tabla**, no que suba la cifra: el sondeo devuelve `{pending, newest}` y la página lleva `newestPending`. Con la diferencia de totales, uno que sale de la cola y otro que entra en la misma media hora no se veían. Como la cola es FIFO y lo nuevo va al final, el aviso lleva a lo más reciente (`sort=-submitted`) en vez de recargar la misma página.
> - **El canal rechaza también** al miembro de una organización **cerrada** —el endpoint es `outside-tenancy` y no pasa por `EnsureOrganizationIsActive`— y un nombre de canal sin el prefijo `private-` (que llega a la autorización sin usuario) es `403`, no un `TypeError`.
> - **Aviso que queda abierto**: el sondeo de 30 s renueva la sesión del backoffice, así que una pestaña visible y desatendida no caduca por inactividad (react-query pausa el sondeo con la pestaña oculta). Ninguna regla pide hoy caducidad por inactividad; si llega, el contador tendría que ir por una ruta que no toque la sesión. Con Echo (`T-079`) el sondeo se apaga mientras haya socket.
> - **RB-8**: por defecto fuera los comprobantes de apartados cancelados; `cancelled=1` los enseña. SP-3 lo hace `LayawayReceipts::rejectPendingOnCancellation()` (`T-054`), y la bandeja lo prueba de punta a punta: cancelar por HTTP y ver el comprobante salir de la cola y del contador.
> - **SP-2** (dos usuarios sobre el mismo comprobante, «ya fue procesado por…») es de la aprobación: `T-061`.

---

### REQ-PAY-005 · Aprobación del comprobante y amortización FIFO

**Historia de usuario**
> Como negocio, quiero aprobar un comprobante y que el sistema descuente automáticamente las cuotas, para que el balance de mi cliente quede correcto sin cálculos a mano.

**Contratos de datos**
- `ApproveReceiptAction(Receipt $receipt, ApproveReceiptData $data, User $actor): Payment`.
- `PaymentApplier::apply(...)` — colaborador compartido con el pago manual (`REQ-PAY-007` RB-2).
- `FifoAllocator::plan(Layaway $layaway, Money $amount): AllocationPlan`.
- Ruta `POST /backoffice/comprobantes/{receipt}/aprobar`.

```php
final readonly class ApproveReceiptData
{
    public function __construct(
        public string $settledAmount,
        public Currency $settledCurrency,
        /** Tasa a aplicar; por defecto la vigente de la organización. Editarla fuerza rate_source = manual. */
        public ?string $rateOverride,
        public ?string $note,
    ) {}
}
```

**Reglas de negocio y precondiciones**
- RB-1 La aprobación es **una sola transacción** que: crea el `Payment` con el snapshot de tasa (`REQ-FX-006`), genera las `PaymentAllocation`, actualiza `installments`, recalcula el balance materializado, transiciona el estado del apartado (ajustando el consumo del plan) y marca el `Receipt` como `approved`.
- RB-2 Solo comprobantes en `pending_review` de apartados que `acceptsPayments()`, y solo por un usuario **de esa** organización.
- RB-3 El negocio puede ajustar el monto realmente recibido; se persisten `declared_*` y el conciliado por separado.
- RB-4 Aplicación **FIFO**: cubre la cuota pendiente más antigua y el excedente cae en la siguiente, en cascada.
- RB-5 Si el apartado está en `pending_down_payment`, la aprobación lo **activa**: genera el cronograma con `activatedAt = now()` en la zona de la organización (`REQ-LAY-004`) y aplica el excedente sobre la inicial a las primeras cuotas.
- RB-6 El importe se aplica hasta el saldo; el excedente queda en `AllocationPlan.unallocatedCents` y **no** genera crédito.
- RB-7 Si tras aplicar `remaining_cents <= organization_settings.settlement_tolerance_cents`, el apartado pasa a `completed` y libera cupo del plan.
- RB-8 Si tras aplicar no quedan cuotas vencidas, un `overdue` vuelve a `active`.
- RB-9 La fila del apartado se bloquea con `lockForUpdate` durante toda la transacción.
- RB-10 Aprobar exige tasa disponible cuando la moneda es VES; se usa la **fuente configurada por esa organización** (`REQ-FX-003`). Sin tasa → `422`.
- RB-11 Se audita con el detalle de la asignación y se notifica al cliente con el desglose.
- RB-12 **Funciona con la organización suspendida** (`REQ-SUB-007` RB-4).

**Happy path**
1. Apartado `active` con cuotas 3, 4 y 5 pendientes de $30; el cliente reportó Bs. 2.550 (≈ $60 a 42,50).
2. El negocio confirma el monto y la tasa vigente y aprueba.
3. La transacción crea el `Payment` de 6000 centavos, asigna 3000 a la cuota 3 y 3000 a la 4 (ambas `paid`), deja la 5 pendiente, actualiza el balance, fija `next_due_date` y marca el comprobante `approved`.
4. El cliente recibe "acreditamos $60,00: cuotas 3 y 4".

**Sad paths y edge cases**
- SP-1 Comprobante ya procesado → `422` "ya fue procesado".
- SP-2 Apartado terminal → `422`.
- SP-3 Dos usuarios aprobando a la vez → el bloqueo serializa; el segundo recibe `422` con el nombre de quien lo procesó.
- SP-4 Importe conciliado 0 o negativo → `422`; no se crean pagos vacíos.
- SP-5 Importe que excede el saldo → se asigna hasta el saldo, `unallocatedCents > 0`, y la UI **exige** confirmación explícita del excedente.
- SP-6 Sin tasa disponible con moneda VES → `422` con la instrucción de fijar la tasa manual en Ajustes.
- SP-7 Tasa editada a mano → `rate_source = manual` forzado y auditoría obligatoria.
- SP-8 Fallo a mitad de la transacción → rollback total: el comprobante sigue `pending_review` y el balance no cambia.
- SP-9 Financiado 0 (inicial = total) → al aprobar la inicial pasa directamente a `completed` sin generar cuotas.
- SP-10 Fallo de la notificación → no revierte (listener encolado, `afterCommit`).
- SP-11 **Comprobante de otra organización** por manipulación de la URL → `404` por el binding filtrado.
- SP-12 Aprobación que completa el apartado cuando el plan ya está por encima del techo → el cupo se libera igual; el consumo nunca queda negativo.

> **Tres reglas que la spec no escribía, decididas en `T-058` (`PaymentApplier`).**
> - **Un pago menor que la inicial sobre un `pending_down_payment` se rechaza** (`PaymentNotApplicableException::belowDownPayment`, `422`). RB-5 dice que se activa y se aplica «el excedente sobre la inicial», y activar con menos contaría como aplicada una inicial que no se cobró: `REQ-PAY-008` RB-3 deriva la inicial aplicada de `activated_at`, y la primera invariante lo reportaría como descuadre. Si un negocio cobra la inicial en dos partes, la registra cuando la tiene entera.
> - **Un pago en USD no necesita tasa** (RB-10 la exige solo con VES): guarda `rate_applied = 1` y, si no hay ninguna tasa, la **fuente configurada** del negocio con `rate_published_at` nula (`REQ-FX-006` RB-4). Con tasa vigente, su fuente y su fecha.
> - **La tasa editada a mano** (SP-7, `REQ-FX-006` SP-3) llega como `ManualRate` y se congela con `rate_source = manual` y `rate_published_at` nula: no la publicó nadie. La tasa manual **configurada** por el negocio (`REQ-FX-003`) es otra cosa: llega como `RateSnapshot` con la fecha en que se fijó, y esa sí se guarda. `[05 §5]` corregido.
>
> Y la transición de RB-7 y RB-8 no la hace `PaymentApplier`: la decide `LayawayBalanceRecalculator` (`T-057`) al recalcular, y la escribe la máquina. Ver la nota de `REQ-PAY-008`.

**Fuera de alcance inmediato**
- Anulación o reverso de una aprobación.
- Crédito a favor del cliente por excedentes.
- Aprobación masiva.

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/ApproveReceiptAction.php`, `app/Domain/Payment/PaymentApplier.php`, `app/Domain/Payment/FifoAllocator.php`, `app/Domain/Payment/AllocationPlan.php`, `app/Models/Payment.php`, `app/Models/PaymentAllocation.php`.
- Tipos esperados: `ApproveReceiptData`, `AllocationPlan`, `FifoAllocator`, `PaymentApplier`, `Payment`, `PaymentAllocation`, `MissingExchangeRateException`, `ReceiptAlreadyProcessedException`.
- Anti-`SHALLOW`: `PaymentApplier::apply()` concentra la aplicación completa (creación, FIFO, balance, transición, cupo del plan) y es el archivo que resuelve el `SHALLOW` compartido de `REQ-PAY-005` y `REQ-PAY-007`; `FifoAllocator::plan()` acompaña a `AllocationPlan`.
- Tests: FIFO en cascada; excedente sin asignar exige confirmación; `pending_down_payment` activa y genera cronograma; `overdue` vuelve a `active`; tolerancia completa y libera cupo; doble aprobación falla; rollback total; snapshot de tasa con la fuente de la organización; comprobante ajeno da `404`; invariante `sum(allocations) === payment.amount − unallocated`; funciona con organización suspendida.

> **Cómo quedó, en `T-061`.**
> - **`ApproveReceiptAction::handle()` devuelve `AppliedPayment`**, no `Payment`: lleva el pago y además el reparto, la inicial y los dos estados, que es lo que necesitan la confirmación de la pantalla y el aviso al cliente (`T-083`). El pago es `$applied->payment`.
> - **SP-5 lo exige también el servidor, y por su cifra.** `ApproveReceiptData` tiene un quinto campo, `confirmedExcessCents`: los centavos de sobrante que se vieron y se confirmaron. Si al repartir sobra otra cifra —se cambió el importe tras marcar la casilla, o entró otro pago entre la vista previa y el envío— lanza `UnconfirmedExcessException` (`422`) y la transacción entera se deshace. La primera versión era un booleano, y la revisión cazó que dejaba aprobar $640 de sobrante tras confirmar $10; la pantalla desmarca la casilla en cuanto el sobrante cambia.
> - **El pago congela lo conciliado, no lo declarado.** `payments.declared_amount` y `declared_currency` guardan lo que el negocio confirma haber recibido (RB-3), en su moneda: es el par que reproduce `amount_cents_usd` con `rate_applied` (`REQ-FX-006` RB-4, RB-5). Lo que declaró el cliente sigue en el comprobante. Con lo declarado —lo que decía la nota de `T-058`—, un negocio que corregía Bs. 2.550 a Bs. 2.500 dejaba un pago que no multiplicaba, y uno aprobado en otra moneda salía con «tasa 1» junto a bolívares.
> - **Una tasa escrita a mano solo vale si hay algo que convertir**: con lo conciliado en dólares se ignora (`ApproveReceiptData::manualRate()`), y el pago guarda la fuente vigente y no `manual` (`REQ-FX-006` RB-4). La pantalla la borra al pasar a dólares.
> - **El orden de bloqueo es apartado → comprobante** (RB-9), el mismo de la subida y de la cancelación; y que siga en revisión se mira bajo el bloqueo (SP-1, SP-3): `ReceiptAlreadyProcessedException` dice quién lo aprobó o lo rechazó, o que lo rechazó el sistema al cancelar.
> - **La tasa** es `ExchangeRateService::current()` de la organización —su fuente configurada (RB-10)—, o `ManualRate` si el negocio la escribió (SP-7), que queda en la auditoría como `rate_override`. La auditoría de `receipt.approved` lleva el pago, lo conciliado, la tasa y su fuente, la inicial, el reparto por número de cuota, el sobrante y el cambio de estado (RB-11).
> - **La vista previa es la aplicación real, deshecha**: `PaymentApplier::preview()` ([05 §5]) aplica dentro de una transacción que siempre hace *rollback* y devuelve `ApprovalPreview`. Los eventos de transición son `ShouldDispatchAfterCommit`, así que se descartan; el modelo que se pasa no se toca. Así «se aplicará a la cuota 3» y lo que escribe la aprobación no pueden divergir. La pantalla la pide al editar importe, moneda o tasa (`GET …/vista-previa`).
> - **Rutas**: `GET /backoffice/comprobantes/{receipt}` (la revisión, RB-6 de `REQ-PAY-004`), `GET …/vista-previa` y `POST …/aprobar`, las tres en `during-suspension` (RB-12) y con `{receipt}` numérico. Cada negativa vuelve a su campo: sobrante sin confirmar a `confirm_excess`, sin tasa a `rate`, el resto a `amount`.
> - **La vista previa cuesta lo que aprobar**: bloquea el apartado y sus cuotas y, si completaría el apartado, el contador de apartados activos de la organización (`LimitEnforcer`), hasta el *rollback*. Son milisegundos, pero cada visita y cada edición del formulario la ejecutan. Una aprobación que gana la carrera mientras una vista previa espera el bloqueo hace chocar el pago de prueba con el de verdad: se responde «ya fue aprobado por…», no un `500`; un *deadlock* se informa y pide reintentar. `preview()` se llama sin transacción abierta.
> - **Riesgo que queda abierto, de producto**: lo conciliado y la tasa manual no tienen techo respecto a lo declarado ni a la tasa vigente. Un miembro del equipo podría aprobar un comprobante pequeño con una tasa absurda y saldar el apartado: se le exige confirmar el sobrante por su cifra y queda auditado con `rate_override`, pero no se impide. Restringir las desviaciones grandes al `owner` es una decisión de [30]; no tiene tarea todavía.
> - **El aviso al cliente (RB-11, SP-10) es de `T-083`.**

---

### REQ-PAY-006 · Rechazo de comprobante y reintento

**Historia de usuario**
> Como negocio, quiero rechazar un comprobante explicando por qué, para que el cliente corrija y vuelva a reportar sin llamadas de ida y vuelta.

**Contratos de datos**
- `RejectReceiptAction(Receipt $receipt, RejectionReasonCode $code, string $reason, User $actor)`.
- Campos `rejection_reason_code`, `rejection_reason`, `reviewed_by`, `reviewed_at`, `replaces_receipt_id`.
- Ruta `POST /backoffice/comprobantes/{receipt}/rechazar`.

**Reglas de negocio y precondiciones**
- RB-1 Motivo obligatorio: un `RejectionReasonCode` del catálogo **más** un texto libre de 10 a 500 caracteres.
- RB-2 El rechazo **no** borra el archivo ni el registro (evidencia).
- RB-3 El cliente recibe el motivo **íntegro** y un CTA para reportar de nuevo. A diferencia del motivo de cancelación de un apartado, este sí se expone: el cliente necesita saber qué corregir (`REQ-LAY-022` RB-3).
- RB-4 El cliente puede subir un comprobante nuevo vinculado (`replaces_receipt_id`); la cadena es visible en ambos lados.
- RB-5 El rechazo **no libera** la referencia bancaria dentro de esa organización (`REQ-PAY-003` RB-5).
- RB-6 Un rechazo sobre un apartado en `pending_down_payment` con la ventana ya vencida concede la prórroga de 24 h (`REQ-LAY-006` RB-3).
- RB-7 Se audita con actor, código y texto.
- RB-8 Un comprobante rechazado no se puede aprobar después: la vía es un pago manual (`REQ-PAY-007`) o un comprobante nuevo.
- RB-9 El código `wrong_account` existe precisamente para el caso nuevo del pivote: el cliente pagó a una cuenta que no es del negocio o que ya no está activa.

**Happy path**
1. El negocio ve una captura ilegible y rechaza con código `not_legible` y el texto "la imagen está cortada, no se ve la referencia".
2. El cliente recibe la notificación, entra al portal y sube una foto nítida, encadenada al rechazado.
3. El negocio la aprueba con normalidad.

**Sad paths y edge cases**
- SP-1 Rechazo de un comprobante ya procesado → `422`.
- SP-2 Motivo demasiado corto → `422`.
- SP-3 Rechazo por error del negocio → no hay "deshacer"; se resuelve con pago manual y nota, o pidiendo al cliente que reporte de nuevo (que fallará por duplicado y forzará el pago manual, `REQ-PAY-003` SP-4).
- SP-4 Cadena larga de reintentos → permitida; la UI ~~colapsa la cadena y muestra el último estado~~ enseña cada eslabón con su estado y enlaza el anterior y el siguiente. *(Corregido en `T-062`: ver la nota de abajo.)*
- SP-5 Rechazo concurrente con una aprobación → el bloqueo serializa; la segunda recibe `422`.
- SP-6 Rechazo automático por cancelación del apartado → usa `layaway_cancelled` con texto estándar y **no** cuenta para la prórroga.
- SP-7 Comprobante de otra organización → `404`.

**Fuera de alcance inmediato**
- Deshacer un rechazo.
- Plantillas de motivos editables por el negocio.
- Diálogo estructurado de aclaración (se usa el chat).

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/RejectReceiptAction.php`, `app/Domain/Payment/RejectionReasonCode.php`, `app/Http/Requests/Backoffice/RejectReceiptRequest.php`, `app/Notifications/ReceiptRejectedNotification.php`.
- Tipos esperados: `RejectionReasonCode`, `RejectReceiptAction`, `ReceiptAlreadyProcessedException`.
- Anti-`SHALLOW`: `RejectReceiptAction::handle()` (estado, motivo, prórroga condicional, auditoría, notificación) supera 4 líneas; `RejectionReasonCode::label()` junto al enum.
- Tests: persiste código y texto; archivo conservado; notificación con motivo íntegro; prórroga solo si la ventana venció; doble proceso falla; cadena vinculada; rechazado no se puede aprobar; comprobante ajeno da `404`.

> **Cómo quedó, en `T-062`.**
> - **`RejectReceiptAction::handle()` devuelve la `DownPaymentExtension`** (la prórroga de RB-6, o por qué no la hubo), y se comporta como la aprobación alrededor: la misma policy (`ReceiptPolicy::review`), el mismo orden de bloqueo —apartado y después comprobante— y `ReceiptAlreadyProcessedException` bajo el bloqueo (SP-1, SP-5, RB-8).
> - **El código `layaway_cancelled` no lo elige nadie** (SP-6): `RejectionReasonCode::chosenByStaff()` es la lista del formulario, y la acción lo rechaza aunque llegue a mano.
> - **El reintento se encadena solo** (RB-4): si el último comprobante del apartado está rechazado **y nada lo ha contestado después** —un pago registrado a mano tras el rechazo también lo contesta—, el nuevo lo sustituye (`replaces_receipt_id`), mirado bajo el bloqueo del apartado en `SubmitReceiptAction` (`LayawayReceipts::rejectedToReplace()`). No hace falta que el cliente diga a cuál sustituye —y así no puede señalar uno ajeno—. La alerta «rechazado sin reintento» de `REQ-LAY-023` usa la misma regla, así que un pago manual también la apaga. El formulario del portal dice a cuál sustituye y por qué, y la revisión enseña la cadena **en los dos sentidos**: «sustituye a…» y «lo sustituyó…».
> - **SP-4 no colapsa la cadena, y se corrige arriba.** Cada eslabón es un intento de pago distinto —con la misma referencia el segundo ni llega (RB-5)—, y cada rechazo trae un motivo que el cliente necesita leer (RB-3): colapsarla a «el último estado» escondería justo eso. El historial del portal la enseña entera, y la revisión enlaza el anterior y el siguiente.
> - **El mismo pago no se puede reportar otra vez** (RB-5, `REQ-PAY-003` RB-5): el formulario del reintento lo avisa —«si es el mismo pago, con la misma referencia, escríbele al negocio para que lo registre»—, porque si no el cliente lo descubre con el duplicado. Es la vía de SP-3.
> - **El aviso al negocio dice qué pasó con el plazo de la inicial** (RB-6): prorrogado 24 h, ya prorrogado una vez —y entonces vencerá si no llega—, o todavía dentro de plazo. El borde se alinea con la expiración: un rechazo **en el segundo exacto** en que vence está todavía dentro (`ExtendDownPaymentWindowAction` con `>=`, igual que `ExpireLayawaysAction` con `expires_at < now`); antes ese segundo daba una prórroga a una ventana que la expiración aún tenía por abierta.
> - **El aviso al cliente con el motivo íntegro** (RB-3, la notificación del gate) **es de `T-083`**, como todos los avisos de cobro; el motivo ya lo ve en su historial (`REQ-LAY-022` RB-3). `ReceiptRejectedNotification` no se escribió: no hay registro de envíos ni canal todavía, y `RejectReceiptAction` no emite ningún evento del que colgarla.

---

### REQ-PAY-007 · Registro de pago manual por el negocio

**Historia de usuario**
> Como negocio, quiero registrar un pago que recibí en efectivo o en mano, para que el balance de mi cliente refleje la realidad aunque no haya comprobante subido.

**Contratos de datos**
- `RegisterManualPaymentAction(Layaway $layaway, ManualPaymentData $data, User $actor): Payment`.
- `payments.receipt_id = null` + `registered_by` obligatorio.
- Ruta `POST /backoffice/apartados/{layaway}/pagos`.

```php
final readonly class ManualPaymentData
{
    public function __construct(
        public PaymentMethod $method,
        public ?PaymentAccount $paymentAccount,
        public string $amount,
        public Currency $currency,
        public ?string $rateOverride,
        public CarbonImmutable $paidAt,
        /** Obligatoria: es la única evidencia de un pago sin comprobante. */
        public string $note,
        public string $idempotencyKey,
    ) {}
}
```

**Reglas de negocio y precondiciones**
- RB-1 Nota **obligatoria** (10–500 caracteres).
- RB-2 Comparte íntegramente la mecánica de `REQ-PAY-005` reutilizando **el mismo `PaymentApplier`**: snapshot de tasa, FIFO, recálculo de balance, transición de estado y ajuste del cupo del plan. **No se duplica la lógica**, y un test de equivalencia lo verifica.
- RB-3 Solo apartados que `acceptsPayments()`, y solo por un usuario de esa organización.
- RB-4 Puede usarse para pagar la cuota inicial, lo que activa el apartado y genera el cronograma.
- RB-5 Se audita siempre y aparece en el historial del cliente etiquetado "registrado por el negocio" (`REQ-LAY-022` RB-7).
- RB-6 No requiere adjunto; si el negocio tiene uno, lo describe en la nota.
- RB-7 Notifica al cliente igual que una aprobación.
- RB-8 **Funciona con la organización suspendida** (`REQ-SUB-007` RB-4).

**Happy path**
1. El cliente paga $30 en efectivo en el local.
2. El negocio abre el apartado, pulsa "Registrar pago", elige `efectivo_usd`, monto 30, fecha de hoy y nota "pago en efectivo recibido en tienda por Ana".
3. Se crea el `Payment` sin `receipt_id`, se amortiza la cuota FIFO y se notifica al cliente.

**Sad paths y edge cases**
- SP-1 Nota vacía o corta → `422`.
- SP-2 Monto 0 o negativo → `422`.
- SP-3 Moneda VES sin tasa → `422`, igual que la aprobación.
- SP-4 Apartado terminal → `422`.
- SP-5 Doble clic → `idempotencyKey` verificada antes de crear.
- SP-6 Fecha de pago futura → `422`.
- SP-7 Excedente sobre el saldo → mismo tratamiento que `REQ-PAY-005` SP-5.
- SP-8 Apartado de otra organización → `404`.

**Fuera de alcance inmediato**
- Adjuntar archivo a un pago manual.
- Caja diaria y cierre de caja.
- Anulación de un pago manual.

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/RegisterManualPaymentAction.php`, `app/Domain/Payment/PaymentApplier.php`, `app/Http/Requests/Backoffice/StoreManualPaymentRequest.php`.
- Tipos esperados: `ManualPaymentData`, `PaymentApplier`, `Payment` con `receipt_id` nulo y `registered_by`.
- Anti-`SHALLOW`: cubierto por `PaymentApplier::apply()` (`REQ-PAY-005`).
- Tests: crea pago sin `receipt_id` con `registered_by`; nota obligatoria; activa el apartado desde `pending_down_payment`; `idempotencyKey` evita el duplicado; aparece etiquetado en el historial del cliente; **produce el mismo resultado que la aprobación para el mismo importe** (test de equivalencia de RB-2); apartado ajeno da `404`; funciona con organización suspendida.

> **Cómo quedó, en `T-062`.**
> - **El pago guarda cómo se pagó**: `payments` gana `payment_method`, `payment_account_id` y `paid_on` (`2026_10_08_000101`), nulas, inmutables, y rellenadas desde su comprobante en los pagos que ya existían. `ManualPaymentData` pedía el método, la cuenta y la fecha y no tenían dónde guardarse: se habrían validado y perdido, y un pago sin comprobante no tiene otra evidencia (RB-6). Viajan como `PaymentOrigin` hasta `PaymentApplier::apply()` —el único que escribe pagos, entero en el `INSERT`—: del comprobante al aprobar y del formulario al registrar a mano. Así los dos caminos dejan **la misma fila**, que es lo que compara el test de equivalencia (RB-2) salvo el comprobante, quién registró, la clave y la nota. Corregido en [05 §4].
> - **`RegisterManualPaymentAction::handle()` devuelve `Payment`**, como dice el contrato: un doble clic (SP-5) devuelve el pago que ya existía con esa clave —antes de abrir la transacción, y también si dos envíos se cruzan y el único de la tabla elige uno—, y ahí no hay una aplicación nueva que devolver.
> - **La cuenta de cobro** se comprueba contra el negocio del apartado (`REQ-ORG-002` RB-7): el formulario la busca por el global scope —la ajena no existe y vuelve como `422` en su campo— y la acción lanza `CrossTenantRelationException` si llega una de otro negocio con el modelo en la mano. **La fecha** se acota en el calendario del negocio, del día de creación del apartado a hoy (SP-6), con los mismos bordes que el comprobante.
> - **SP-7, como la aprobación**: el sobrante se confirma por su cifra (`confirmedExcessCents`), con la misma vista previa (`PaymentApplier::preview()` con el negocio como quien registra). Y una tasa manual con dólares se ignora.
> - **Una cuenta de cobro con pagos tampoco se borra** (`REQ-ORG-007` RB-6): `RecordedReceipts::existFor()` pregunta también a `payments`, y la clave es `restrict`.
> - **La clave de idempotencia identifica un pago, no un formulario** (SP-5). Si la clave ya registró **otro** pago —otro apartado, otro importe u otra moneda—, no es un doble clic: `IdempotencyKeyReusedException` (`422` en el importe) en vez de devolver un pago que el negocio tomaría por este. Y el formulario estrena clave tras cada registro: Inertia conserva el estado del componente al volver a la misma página, y con la clave vieja el segundo pago real de la visita se habría dado por registrado sin escribirse. El «ya registrado» se mira también **bajo el bloqueo del apartado**: un envío cruzado que espera al ganador encuentra su pago antes de preguntar si el apartado todavía cobra.
> - **La auditoría** (RB-5) guarda el importe, cómo se pagó, el reparto, el sobrante, el cambio de estado, la nota y la tasa manual si la hubo. La cuenta va como `payment_account`, **sin** `_id`: `AuditProperties` redacta toda clave que contenga `account_id` —la cuenta global del cliente, `REQ-ACC-007`— y esta es la cuenta de cobro del propio negocio, que se perdía como `[redactado]`.
> - **El aviso al cliente (RB-7) es de `T-083`.**

---

### REQ-PAY-008 · Balance materializado y reconciliación

**Historia de usuario**
> Como negocio, quiero que los saldos que veo sean instantáneos y verificables, para confiar en mis reportes de cobranza sin recalcular nada a mano.

**Contratos de datos**
- Campos materializados en `layaways`: `paid_cents`, `remaining_cents`, `next_due_date`, `status`.
- `LayawayBalanceRecalculator::recalculate(Layaway $layaway): void`, invocado dentro de la transacción de cada pago.
- Comando `php artisan layaways:reconcile [--fix] [--organization=]` + job programado diario.

**Reglas de negocio y precondiciones**
- RB-1 El balance es **materializado**: las lecturas leen columnas, no agregan pagos.
- RB-2 Se recalcula **dentro de la misma transacción** de cada pago; nunca de forma diferida.
- RB-3 Invariantes verificables:
  - `paid_cents === sum(payments.amount_cents_usd) − sum(unallocated)` del apartado;
  - `paid_cents === sum(installments.paid_cents) + inicial aplicada`;
  - `remaining_cents === total_cents − paid_cents`, nunca negativo;
  - `next_due_date === min(installments.due_date WHERE status != paid)` o `null`.
- RB-4 `layaways:reconcile` recorre los apartados no terminales **de todas las organizaciones** (con `withoutTenantScope()` y fijando el tenant por lote), compara lo materializado con lo derivado y **reporta**; con `--fix` corrige y registra la corrección en auditoría.
- RB-5 El comando acepta `--organization=` para acotar a una sola, lo que lo hace usable durante un acceso de soporte (`REQ-PLT-005`).
- RB-6 Idempotente y seguro en producción; corre a diario a las 05:30 en modo reporte, y las discrepancias alimentan la pantalla de Salud de la plataforma (`REQ-PLT-006` RB-2), que es la señal más importante de ese panel.
- RB-7 **Ninguna otra clase escribe estos campos**: solo `LayawayBalanceRecalculator`, regla verificable por revisión y por test de arquitectura.

> **De dónde sale cada término de RB-3, decidido en `T-057`.** Ni el sobrante ni la inicial aplicada tenían columna.
> - **Sobrante** = `payments.unallocated_cents`, **nueva** ([05 §4]): lo que `FifoAllocator` dijo que no cabía, escrito en el `INSERT` por `PaymentApplier`. Derivado, la primera invariante se habría cumplido sola.
> - **Inicial aplicada** = la inicial total de `Layaway::terms()` si `activated_at` no es nulo, `0` si lo es. Sin columna: la inicial no es una cuota, solo se aplica activando, y activar solo ocurre al aprobarla (`REQ-LAY-004` RB-1).
> - **Lo que se escribe** es la segunda invariante —`paid_cents = sum(installments.paid_cents) + inicial aplicada`—, no la primera: el recalculador corre **dentro** de la activación, antes de que se reparta el excedente, y ahí las cuotas y la inicial ya cuentan la historia entera (y con financiado cero la cuentan como completa, SP-3). La primera es la comprobación cruzada: `diff()` la mide y `BalanceDiscrepancy` la reporta aparte de las columnas, porque recalcular no la arregla.
> - **`next_due_date`** se calcula con `paid_cents < amount_cents` y no con `status != paid`: es la misma condición —`InstallmentStatus::resolve()` da `paid` exactamente cuando la cuota está cubierta— sin depender de que el estado de la cuota se haya escrito ya.
> - **`status`**: lo **decide** el recalculador —completar dentro de `settlement_tolerance_cents`, entrar y salir de la mora siguiendo a las cuotas (`REQ-LAY-007` RB-2, RB-6)— y lo **escribe** `LayawayStateMachine` (`REQ-LAY-005` RB-2). Por eso RB-7 se comprueba en dos tests: `LayawayBalanceWritesTest` (los tres primeros, solo el recalculador) y `LayawayStatusWritesTest` (el estado, solo la máquina). La única otra escritura del balance es la del `INSERT` —sin pagos: `0`, el total y sin vencimiento—, y no se admite por lista sino por regla: la guarda de `Layaway` rechaza crear un apartado con cualquier otro saldo.
> - SP-2 se «reporta» con `Log::warning('layaway_balance.overpaid')`, que la suite convierte en fallo (`FailsOnCounterDrift`), y con `BalanceDiscrepancy::$overpaidCents`.

**Happy path**
1. Se aprueba un pago; la transacción actualiza cuotas y llama al recalculador.
2. `paid_cents` sube 6000, `remaining_cents` baja 6000, `next_due_date` avanza a la cuota 5.
3. El job diario compara y no encuentra discrepancias.

**Sad paths y edge cases**
- SP-1 Discrepancia por un bug ya corregido → `--fix` la repara con rastro; los pagos (fuente de verdad) no se tocan.
- SP-2 `remaining_cents` negativo por un excedente mal conciliado → se acota a 0 y se reporta la anomalía.
- SP-3 Apartado sin cuotas (financiado 0) → `remaining_cents = 0` y `next_due_date = null` tras aprobar la inicial.
- SP-4 Reconciliación sobre decenas de miles de apartados de miles de organizaciones → `chunkById` con una consulta agregada por lote, sin `N+1`, y el tenant fijado y limpiado por lote.
- SP-5 Ejecución concurrente con una aprobación → el recalculador bloquea la fila; el comando salta las bloqueadas y las reintenta en la pasada siguiente.
- SP-6 Apartado terminal con discrepancia histórica → se reporta pero **no** se corrige sin `--fix` explícito.
- SP-7 Organización suspendida → sus apartados se reconcilian igual: es integridad de datos, no una operación de negocio.

**Fuera de alcance inmediato**
- Libro mayor contable y partida doble.
- Reexpresión histórica de balances.

**Gate de validación**
- Superficie esperada: `app/Domain/Layaway/LayawayBalanceRecalculator.php`, `app/Console/Commands/ReconcileLayaways.php`, `app/Jobs/ReconcileLayaways.php`.
- Tipos esperados: `LayawayBalanceRecalculator`, `ReconciliationReport`, `BalanceDiscrepancy`.
- Anti-`SHALLOW`: `LayawayBalanceRecalculator::recalculate()` (cuatro invariantes + transición) y `ReconcileLayaways::handle()` (recorrido por lotes multi-tenant, comparación, reporte, `--fix`) superan 4 líneas.
- Tests: las cuatro invariantes tras 10 pagos aleatorios; `remaining_cents` nunca negativo; `next_due_date` correcto tras pagar la cuota intermedia; `reconcile` detecta y corrige una discrepancia inyectada; `--organization` acota; sin `N+1`; el tenant se limpia entre lotes (fuga); terminal no se corrige sin `--fix`; solo el recalculador escribe los cuatro campos (test de arquitectura).

> **Cómo quedó, en `T-064`.**
> - **Recorre los apartados de todos los estados, no solo los vivos.** RB-4 dice «no terminales» y SP-6 pide informar de un terminal con un descuadre histórico; las dos cosas no caben a la vez. Se informa de todos, y `--fix` corrige todos: sin `--fix` no se corrige ninguno, terminal o no, que es lo que SP-6 protege. Un saldo mal guardado en un apartado cancelado sigue siendo dinero de un cliente mal contado.
> - **El tenant se fija por organización**, con `runFor` desde el job (`RunsForEveryOrganization`), y se suelta al pasar a la siguiente; dentro, los apartados van por lotes de 200 (`chunkById`). No hace falta `withoutTenantScope()`, que `tenancy:audit` solo admite en `app/Console/Commands` y la plataforma: se enumeran organizaciones, que no son del tenant. Las suspendidas también (SP-7).
> - **Sin `N+1`** (SP-4): `LayawayBalanceRecalculator::diffMany()` es `diff()` por lotes —las cuotas de todos en una consulta, lo pagado agrupado en otra— sobre la misma comparación (`discrepancyOf()`), así que la reconciliación no puede medir distinto que `diff()`.
> - **Además del saldo, lo pagado de cada cuota contra lo que le reparten los pagos.** `ledgerMismatch()` —los pagos y las cuotas no cuentan lo mismo— no se arregla recalculando, porque el saldo se deriva de las cuotas. La decisión que pedía la nota de `T-057`: en un apartado que cobra (`active`, `overdue`), `--fix` **reconstruye `installments.paid_cents` desde `payment_allocations`**, que se escriben en la misma transacción que el pago y son su reparto (los pagos no se tocan, SP-1), con su estado y, si queda cubierta, el `paid_at` del pago más reciente que la cubre —no la hora de la reconciliación—, y recalcula después. **Un terminal no se reconstruye**: sus cuotas y su estado no se tocan (`REQ-LAY-007` SP-4), así que unas cuotas descuadradas en un cancelado, expirado o completado quedan `unresolved`. También queda `unresolved` lo que después de corregir sigue sin cuadrar con los pagos o pagado de más: no hay nada derivable que escribir, y lo mira una persona. El comando termina con error.
> - **El informe lee cada lote dentro de una transacción**: con `REPEATABLE READ`, todas sus lecturas ven la misma foto, y una aprobación que confirmara entre la consulta de las cuotas y la de los pagos no aparece como un descuadre que no existe —en la pantalla de Salud sería la señal más grave—.
> - **Un fallo al corregir una fila no para la pasada**: queda `failed`, va al log de errores y el resto sigue.
> - **Y el faltante de cada producto** (`stock_shortfall <= unidades apartadas`, `T-044`), también de los archivados: `--fix` lo acota a las apartadas, por el *query builder* como la reserva y la liberación, sin mover `updated_at` ni la marca de quién lo editó.
> - **SP-5**: `--fix` toma la fila con `FOR UPDATE SKIP LOCKED`. Si otra operación la tiene, queda `skipped` y la corrige la pasada siguiente; esperar habría parado la pasada detrás de una aprobación lenta. Con su test contra otra conexión de verdad.
> - **La corrección se audita** con un evento nuevo, `reconciliation.corrected`, sin actor y con el proceso (añadido a la lista cerrada de `REQ-BOF-005` RB-1 en [30], con su porqué).
> - **RB-6**: el job corre a diario a las 05:30 de la plataforma (`APP_TIMEZONE`), en modo informe y en la cola `default` —[90] no lo nombraba—. **Una pasada a la vez, también entre el job y el comando**: un candado de caché en `handle()`, no un middleware de la cola, porque el comando la ejecuta sin pasar por ella. Solo una pasada **de informe sobre toda la plataforma** deja la señal para la pantalla de Salud (`ReconciliationReport::last()`: saldos descuadrados, faltantes aparte, por revisar y cuándo; `REQ-PLT-006`, `T-086`): una con `--fix` contaría lo que acaba de corregir, y una con `--organization` habla de un solo negocio.
> - **El comando** termina con éxito solo si no queda nada por mirar: sin descuadres, o todos corregidos. `--organization` acepta solo dígitos —`is_numeric` habría leído «1.5» como la organización 1—.
> - **Una corrección que cambia el estado** —un apartado que debía estar en mora, o completado— **pasa por la máquina de estados** como cualquier otra, con sus eventos: el cliente recibirá el aviso que corresponde a ese estado (`T-083`), porque es el estado en que de verdad está.
> - **Y un bug que la reconciliación destapó, fuera de ella**: `recalculate()` decía devolver el modelo con el saldo nuevo, y no lo hacía si quien llamaba ya había leído `paid_cents`. `setRawAttributes(..., sync: true)` vuelve a escribir encima lo que la caché de los casts de clase guardaba. El mismo gesto estaba en once Actions. Ahora todas usan `ModelState::adopt()`, y `ModelStateAdoptionTest` prohíbe la forma rota en `app/`.

---

### REQ-PAY-009 · Reasignación manual de un pago

**Historia de usuario**
> Como negocio, quiero mover la aplicación de un pago a la cuota correcta, para corregir los casos donde el FIFO automático no refleja lo pactado con mi cliente.

**Contratos de datos**
- `ReallocatePaymentAction(Payment $payment, list<array{installmentId:int, cents:int}> $allocations, User $actor, string $reason)`.
- Ruta `PATCH /backoffice/pagos/{payment}/reasignar`.

**Reglas de negocio y precondiciones**
- RB-1 Se reasigna **la aplicación** del pago, nunca su importe, su moneda ni su tasa (`REQ-FX-006` RB-3).
- RB-2 Invariante dura: `sum(nuevas) === sum(anteriores)`. Si no coincide → `422`.
- RB-3 Solo cuotas del **mismo apartado** del pago, y por tanto de la misma organización.
- RB-4 Tras reasignar se recalculan las cuotas afectadas, el balance materializado, el estado del apartado y el cupo del plan (puede volver a `overdue` o pasar a `completed`).
- RB-5 Motivo obligatorio (10–500 caracteres) y auditoría con el antes y el después.
- RB-6 Transaccional, con bloqueo del apartado.
- RB-7 No se puede reasignar un pago de un apartado `cancelled` o `expired`.
- RB-8 La UI muestra el reparto actual, permite editarlo cuota por cuota y valida la suma en cliente antes de enviar.

**Happy path**
1. Un pago de $60 se aplicó por FIFO a las cuotas 3 y 4, pero el cliente había pactado abonar la 5.
2. El negocio abre el pago, mueve $30 de la cuota 4 a la 5, escribe el motivo y guarda.
3. La cuota 4 vuelve a `pending`, la 5 queda `paid`, el balance total no cambia y el estado se recalcula.

**Sad paths y edge cases**
- SP-1 Suma distinta de la original → `422` con la diferencia explícita.
- SP-2 Cuota de otro apartado o de otra organización → `422` / `404`.
- SP-3 Asignación que deja `paid_cents > amount_cents` en una cuota → `422`; no se permite sobrepagar una cuota.
- SP-4 Reasignación que devuelve el apartado a `overdue` → permitida; el estado y el cupo se recalculan.
- SP-5 Reasignación concurrente con una aprobación → el bloqueo serializa.
- SP-6 ~~Reasignación que saca un apartado de `completed` → permitida, con advertencia previa en la UI, y el cupo del plan se vuelve a consumir. Si el plan ya está en el techo, **se permite igual**: el apartado ya existía y bloquearlo dejaría el balance mal (`REQ-SUB-008` RB-3).~~ Reasignación en un apartado `completed` → permitida, y **sigue `completed`**: la reasignación no mueve el saldo, así que no lo saca de ahí ni toca el cupo. *(Corregido en `T-063`: ver la nota de abajo.)*
- SP-7 Excedente no asignado en el pago original → se conserva; no se puede asignar por encima del importe del pago.

**Fuera de alcance inmediato**
- Mover un pago a otro apartado o a otro cliente.
- Anulación del pago.
- División de un pago en dos registros.

**Gate de validación**
- Superficie esperada: `app/Actions/Payment/ReallocatePaymentAction.php`, `app/Http/Requests/Backoffice/ReallocatePaymentRequest.php`, `app/Domain/Layaway/LayawayBalanceRecalculator.php`, `resources/js/features/receipts/components/AllocationEditor.tsx`.
- Tipos esperados: `ReallocatePaymentAction`, `AllocationPlan`, `InvalidReallocationException`.
- Anti-`SHALLOW`: `ReallocatePaymentAction::handle()` (validación de suma y pertenencia, reescritura, recálculo, cupo, auditoría) supera 4 líneas.
- Tests: suma distinta rechazada; cuota ajena rechazada; sobrepago de cuota rechazado; balance total invariante; vuelta a `overdue` recalculada; ~~salir de `completed` con el plan en el techo se permite (test de SP-6)~~ un `completed` sigue `completed` y el cupo no se toca (SP-6 corregido); auditoría con antes y después; terminal rechazado.

> **Cómo quedó, en `T-063`.**
> - **SP-6 no se podía alcanzar, y se corrige arriba.** Una reasignación conserva la suma de lo repartido (RB-2), no deja sobrepagar una cuota (SP-3) y no toca el sobrante (SP-7): `sum(installments.paid_cents)` —y con ella `paid_cents` y `remaining_cents`— es la misma antes y después. Completar depende solo del saldo (`REQ-LAY-005` RB-5, `REQ-PAY-005` RB-7), y `completed` es terminal en la matriz (`REQ-LAY-005` RB-1). Abrir la celda `completed → active` para cumplir SP-6 habría inventado un estado que el saldo no respalda. El único reparto posible en un `completed` es dentro de la tolerancia —unos centavos de una cuota sin cubrir—, y el test lo hace: sigue `completed` y los contadores del cupo no cambian. Si algún día un cambio de tolerancia mueve el saldo, eso es otra operación y se nombra.
> - **`ReallocatePaymentAction::handle()` devuelve el `AllocationPlan` nuevo** (el contrato no decía qué): es lo que el aviso al negocio necesita para decir a qué cuotas fue. Bloquea **apartado → cuotas → asignaciones del pago**, el orden del pago, así que una aprobación concurrente se serializa (SP-5).
> - **Se borra el reparto del pago y se escribe el nuevo**, como ya decía `PaymentAllocation` (una asignación no se edita): la invariante se comprueba sobre dos conjuntos completos. Las cuotas que cambian se recalculan con `InstallmentStatus::resolve()` y el «hoy» del negocio, como al pagar, y el apartado con `LayawayBalanceRecalculator` (RB-4, SP-4).
> - **Lo que una cuota admite de este pago** es su importe menos lo que le pagaron **los demás** pagos —no menos lo pagado—: si no, mover dentro de una cuota que este mismo pago ya cubre se leería como sobrepago.
> - **Dos negativas más** que la spec no nombraba: un reparto idéntico al actual (no hay nada que cambiar ni que auditar) y un pago que solo pagó la inicial (no tiene nada repartido entre cuotas).
> - **SP-2**: el pago de otro negocio es `404` (la ruta lo resuelve por el tenant); una cuota de otro apartado —del mismo negocio o de otro— en el reparto de un pago propio vuelve al formulario con su motivo. Las dos con su test.
> - **La UI** es el editor de la vista del apartado (`AllocationEditor`): una fila por cuota con lo que admite, la suma y la diferencia en vivo, en centavos enteros y sin coma flotante, y el motivo. Un apartado cancelado o expirado no lo trae (RB-7), ni uno cuyos pagos solo pagaron la inicial.
> - **Los «`422`» de SP-1, SP-2 y SP-3 vuelven al formulario** con el error en `allocations` (una redirección con los errores en sesión), como el resto de los formularios del backoffice; `InvalidReallocationException::STATUS` es el código para quien la llame de otra forma.
> - **Se recalculan todas las cuotas**, no solo las que el reparto toca, como al pagar: una cuota que pasó la gracia y la pasada de las 06:00 aún no marcó dejaría que el apartado volviera a `active` para que la pasada lo devolviera a `overdue` horas después (`REQ-PAY-005` RB-8).
> - **Una cuota que queda cubierta toma el `paid_at` del pago más reciente que la cubre**, no la hora de la reasignación: cuándo quedó pagada es cuándo llegó el dinero.
> - **Con la organización suspendida se reasigna**, como se cancela, se reprograma o se registra un pago: es la gestión de la cobranza que `REQ-SUB-007` RB-4 preserva.
> - **La auditoría guarda el motivo entero**: el tope de texto de `AuditProperties` pasa de 400 a 500 caracteres, el de `StaffReason`. Con 400, todo motivo —también el de una cancelación o una reprogramación— perdía el final. Un reparto de más de 20 cuotas se acota en la auditoría, como prevé `REQ-BOF-005` SP-2.
