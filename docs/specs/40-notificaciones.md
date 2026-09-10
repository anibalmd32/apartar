# Módulo 40 — Notificaciones Web Push y email (`NOT`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md), [50-planes-y-suscripciones.md](50-planes-y-suscripciones.md)
> Requisitos: `REQ-NOT-001` … `REQ-NOT-005`
>
> **Cambio clave del pivote:** el destinatario de una notificación pasa a ser **polimórfico**. Una misma persona puede ser una `Account` global (con Google) o una `Customer` de un negocio concreto (sin cuenta), y el sistema debe alcanzarla en ambos casos sin duplicar el aviso.

## Contratos compartidos del módulo

```php
/** Catálogo cerrado de notificaciones del sistema. */
enum NotificationType: string
{
    // Ciclo del apartado (destinatario: Customer, resuelto a Account si está vinculada)
    case LayawayCreated = 'layaway_created';
    case DownPaymentReminder = 'down_payment_reminder';
    case LayawayExpired = 'layaway_expired';
    case LayawayActivated = 'layaway_activated';
    case InstallmentDueSoon = 'installment_due_soon';
    case InstallmentDueToday = 'installment_due_today';
    case InstallmentOverdue = 'installment_overdue';
    case ReceiptApproved = 'receipt_approved';
    case ReceiptRejected = 'receipt_rejected';
    case LayawayCompleted = 'layaway_completed';
    case LayawayCancelled = 'layaway_cancelled';
    case LayawayDelivered = 'layaway_delivered';
    case ChatMessage = 'chat_message';
    case ManualBroadcast = 'manual_broadcast';
    // Novedades de catálogo (destinatario: Account)
    case CatalogDigest = 'catalog_digest';
    // Suscripción (destinatario: User owner de la organización)
    case InvoiceIssued = 'invoice_issued';
    case InvoiceOverdue = 'invoice_overdue';
    case SubscriptionSuspended = 'subscription_suspended';
    case SupportAccessGranted = 'support_access_granted';

    /** @return list<'webpush'|'mail'|'database'> */
    public function channels(): array { /* … */ }
    public function isTransactional(): bool { /* … */ }
    /** Quién recibe: Customer, Account o User. */
    public function audience(): NotificationAudience { /* … */ }
}

enum NotificationAudience: string
{
    case Customer = 'customer';   // cliente final de un negocio
    case Account = 'account';     // consumidor global
    case TeamOwner = 'team_owner';
}

/**
 * Resuelve por qué canales y a qué destinatario efectivo llega un aviso
 * dirigido a una ficha de cliente.
 *
 * Si la ficha tiene una Account vinculada, las suscripciones push de esa
 * cuenta son las que se usan; si no, las de la propia ficha. El email sale
 * de la ficha, que es donde vive el dato comercial.
 */
final class NotifiableResolver
{
    /** @return list<PushSubscription> */
    public function pushSubscriptionsFor(Customer $customer): array { /* … */ }
    public function emailFor(Customer $customer): ?string { /* … */ }
    public function canReachByPush(Customer $customer): bool { /* … */ }
}
```

```ts
export interface PushSubscriptionState {
  readonly permission: 'default' | 'granted' | 'denied';
  readonly isSubscribed: boolean;
  readonly isSupported: boolean;
}
```

---

### REQ-NOT-001 · Suscripción a Web Push con destinatario polimórfico

**Historia de usuario**
> Como cliente, quiero recibir avisos en mi teléfono cuando una cuota esté por vencer, para no atrasarme sin darme cuenta.

**Contratos de datos**
- Entidad `PushSubscription` (`push_subscriptions`), **tabla de plataforma**: `notifiable_type`, `notifiable_id` (`Account` | `Customer`), `endpoint` (`UNIQUE`), `public_key`, `auth_token`, `content_encoding`, `last_used_at`.
- `laravel-notification-channels/webpush` con VAPID; Service Worker `public/sw.js`.
- Rutas `POST /mi-cuenta/push`, `DELETE /mi-cuenta/push`; hook `usePushSubscription()`.

**Reglas de negocio y precondiciones**
- RB-1 La suscripción se asocia **polimórficamente**: a la `Account` si la sesión es de cuenta, y a la `Customer` si es una sesión acotada (`REQ-ACC-005`). Es la consecuencia directa de que existan dos identidades.
- RB-2 `NotifiableResolver` es el **único** punto que decide a qué suscripciones enviar un aviso dirigido a una ficha: si la ficha tiene `account_id`, se usan las de la cuenta; si no, las suyas. Así **nunca se envía el mismo aviso dos veces** a la misma persona por tener las dos identidades.
- RB-3 Un dispositivo suscrito como `Account` recibe los avisos de **todos** los negocios de esa persona, cada uno identificando el negocio en el título. Uno suscrito como `Customer` recibe solo los de ese negocio.
- RB-4 La solicitud de permiso es **diferida y contextual**: nunca al cargar. Se ofrece tras crear el primer apartado y en el portal, con una explicación propia antes del diálogo del navegador.
- RB-5 Multi-dispositivo: N suscripciones por identidad; `endpoint` único globalmente.
- RB-6 El Service Worker es mínimo: recibe el push, muestra la notificación y abre la URL al pulsar. **No** implementa caché offline ni PWA instalable.
- RB-7 Sin soporte o con permiso denegado, la UI **no insiste**: informa de que los avisos llegarán por email si hay email, y si no lo hay, de que debe revisar el portal.
- RB-8 Cerrar sesión **no** elimina la suscripción (el dispositivo sigue siendo de esa persona); se elimina solo si lo pide o si el endpoint caduca (`REQ-NOT-005`).
- RB-9 Las claves VAPID se leen de `.env`; su ausencia deshabilita el canal limpiamente, sin errores en tiempo de request.
- RB-10 **Enviar push a clientes es función de plan** para los avisos que dispara el negocio (`web_push`: recordatorios de cobro y envíos manuales). Los avisos **transaccionales** del ciclo del apartado (aprobación, rechazo, activación) **no** son función de plan: informar a un cliente de que su pago fue acreditado es una obligación del servicio, no un extra que se vende.

**Happy path**
1. La persona crea su primer apartado y ve la tarjeta "¿quieres que te avisemos antes de cada vencimiento?".
2. Acepta; se dispara el diálogo del navegador, concede el permiso y el `endpoint` se registra contra su `Account`.
3. Recibe la notificación de bienvenida y, en adelante, los recordatorios de todos sus negocios.

**Sad paths y edge cases**
- SP-1 Permiso denegado → se registra el estado en el cliente para no volver a preguntar y se informa del canal alternativo.
- SP-2 Navegador sin soporte → `isSupported = false` y la UI omite el bloque.
- SP-3 `endpoint` ya registrado (reinstalación del SW) → se **actualiza** el registro existente, incluido su `notifiable`, en lugar de fallar por unicidad.
- SP-4 **Persona suscrita como `Customer` que luego conecta Google** → la suscripción se **reasigna** a la `Account` al vincularse (`REQ-ACC-004`), para que no reciba avisos duplicados desde ese momento.
- SP-5 Permiso revocado en el navegador → el envío falla con `410`/`404` y la suscripción se purga (`REQ-NOT-005`).
- SP-6 Sin claves VAPID → la ruta responde `503` con mensaje claro y el frontend oculta la funcionalidad.
- SP-7 Dispositivo compartido → riesgo asumido; el cierre de sesión no borra la suscripción (RB-8), y así se documenta.
- SP-8 Ficha con `account_id` y suscripciones propias antiguas → `NotifiableResolver` prioriza las de la cuenta y las propias se purgan al primer envío correcto por la cuenta.

**Fuera de alcance inmediato**
- PWA instalable y modo offline.
- Notificaciones en el navegador para el equipo del negocio o para la plataforma.
- Preferencias por tipo de notificación.

**Gate de validación**
- Superficie esperada: `app/Models/PushSubscription.php`, `app/Domain/Notification/NotifiableResolver.php`, `app/Http/Controllers/Portal/PushSubscriptionController.php`, `public/sw.js`, `resources/js/features/notifications/hooks/usePushSubscription.ts`, `config/webpush.php`.
- Tipos esperados: `PushSubscription`, `NotifiableResolver`, `NotificationAudience`, `PushSubscriptionState`, `NotificationType`.
- Anti-`SHALLOW`: `NotifiableResolver::pushSubscriptionsFor()` (resolución cuenta/ficha, deduplicación, priorización) y `usePushSubscription` (soporte, permiso, registro del SW, alta y baja) superan 4 líneas junto a sus tipos.
- Tests: se suscribe a `Account` con sesión de cuenta y a `Customer` con sesión acotada; **una persona con ambas identidades recibe el aviso una sola vez** (test explícito de RB-2); `endpoint` duplicado actualiza; vincular la cuenta reasigna la suscripción; sin VAPID `503`; permiso denegado no vuelve a pedirse; logout conserva la suscripción; los transaccionales llegan en plan Free y los del negocio no (RB-10).

---

### REQ-NOT-002 · Recordatorios automáticos del ciclo de cobro

**Historia de usuario**
> Como negocio, quiero que el sistema recuerde solo los vencimientos a mis clientes, para cobrar a tiempo sin llamar uno por uno.

**Contratos de datos**
- Comando programado diario `notifications:dispatch-reminders` → `DispatchDueRemindersAction`.
- Entidad `NotificationDispatch` (`notification_dispatches`) con `organization_id`: `notifiable_type/id`, `installment_id?`, `type`, `channel`, `sent_at`, `UNIQUE(installment_id, type, channel)`.
- Ajuste `organization_settings.reminder_offsets_days` = `[-3, 0, 1, 7, 15]`.
- **Requiere:** función de plan `web_push` para el canal push; el canal email está disponible en todos los planes.

**Reglas de negocio y precondiciones**
- RB-1 Offsets respecto a `due_date`: **−3 días**, **día 0**, **+1**, **+7** y **+15**. Configurables **por organización**.
- RB-2 Cada combinación (`installment_id`, `type`, `channel`) se envía **una sola vez**: la unicidad en BD es la garantía de idempotencia, no una comprobación en PHP.
- RB-3 Solo se notifican cuotas de apartados en `active` u `overdue` no pagadas.
- RB-4 Los offsets positivos se cuentan desde `due_date`, **no** desde el fin de la gracia: el aviso de +1 llega mientras el apartado aún está dentro de la gracia y actúa como recordatorio suave.
- RB-5 Los umbrales del portal (`REQ-LAY-023`) usan los **mismos** offsets de la misma organización, para que app y push nunca se contradigan.
- RB-6 También emite `down_payment_reminder` 12 h antes de que expire un apartado en `pending_down_payment` (`REQ-LAY-006` RB-5).
- RB-7 Canales: **email siempre** que haya email; **push solo si el plan lo incluye** (`web_push`). Si el plan no lo incluye y no hay email, el aviso **no sale**, y el negocio lo ve en su dashboard como consumo bloqueado por el plan.
- RB-8 Se envía a las **08:00 de la zona horaria de la organización**, no del servidor: un negocio y sus clientes comparten husos, y avisar de madrugada es contraproducente.
- RB-9 Todo mensaje incluye: **nombre del negocio**, producto, número de cuota, importe en ambas monedas, fecha de vencimiento y enlace directo a subir el comprobante. El nombre del negocio es imprescindible: la persona puede tener apartados en varios.
- RB-10 El comando recorre todas las organizaciones con `withoutTenantScope()`, fijando el tenant por lote (`REQ-ORG-002` RB-5).
- RB-11 **Se envían aunque la organización esté suspendida** (`REQ-SUB-007` RB-3): son parte de la operación del apartado.

**Happy path**
1. A las 08:00 de Caracas, el comando encuentra 14 cuotas de la Ferretería que vencen en 3 días.
2. Para cada una comprueba `notification_dispatches`, encola push (plan Pro) + email y registra el envío.
3. Los clientes reciben "Ferretería El Tornillo: tu cuota 3 de $30,00 vence el 25/09".

**Sad paths y edge cases**
- SP-1 El comando corre dos veces el mismo día → la unicidad impide duplicados.
- SP-2 El comando no corre un día → los offsets de ese día **se pierden** para las cuotas afectadas; los siguientes sí llegan. Decisión explícita para no inundar al cliente con avisos atrasados.
- SP-3 Cuota pagada entre el cálculo y el envío → el job revalida antes de notificar y aborta si ya está pagada.
- SP-4 Cliente sin push y sin email → el aviso no sale; queda visible en el portal (`REQ-LAY-023`) y el negocio lo ve como cliente no alcanzable.
- SP-5 Cliente con 8 cuotas vencidas en varios apartados **del mismo negocio** → **una** notificación agrupada por negocio, no una por cuota.
- SP-6 Cliente con cuotas vencidas en **dos negocios** → **dos** notificaciones, una por negocio. Agruparlas sería mezclar deudas de empresas distintas.
- SP-7 Cambio de `reminder_offsets_days` → aplica al día siguiente; no reenvía offsets pasados.
- SP-8 Apartado cancelado el mismo día → excluido por RB-3.
- SP-9 Plan sin `web_push` → solo email; si tampoco hay email, se cuenta como no alcanzable (RB-7).
- SP-10 Dos organizaciones en zonas distintas → cada una envía a las 08:00 de la suya.

**Fuera de alcance inmediato**
- Recordatorios por WhatsApp o SMS.
- Horario de envío configurable por el negocio.
- Preferencia de "no molestar" del cliente.

**Gate de validación**
- Superficie esperada: `app/Actions/Notification/DispatchDueRemindersAction.php`, `app/Console/Commands/DispatchReminders.php`, `app/Models/NotificationDispatch.php`, `app/Notifications/InstallmentReminderNotification.php`.
- Tipos esperados: `NotificationType`, `NotificationDispatch`, `DispatchDueRemindersAction`, `ReminderPlan`.
- Anti-`SHALLOW`: `DispatchDueRemindersAction::handle()` (cinco offsets, exclusiones, agrupación por negocio, comprobación de función de plan, registro idempotente, zona horaria por organización, cambio de tenant por lote) supera 4 líneas.
- Tests: un test por offset en el límite; doble ejecución no duplica; cuota pagada no notifica; **agrupa por negocio y no entre negocios** (test de SP-5 y SP-6); sin push ni email cuenta como no alcanzable; plan sin `web_push` solo envía email; envío a las 08:00 de cada zona; el nombre del negocio aparece en el mensaje; se envían con la organización suspendida.

---

### REQ-NOT-003 · Notificaciones transaccionales

**Historia de usuario**
> Como cliente, quiero que me avisen cuando aprueben o rechacen mi comprobante, para saber si mi pago quedó acreditado.

**Contratos de datos**
- Una clase de notificación por `NotificationType` transaccional, disparada por eventos de dominio, todas `ShouldQueue` en la cola `default`.

**Reglas de negocio y precondiciones**
- RB-1 Eventos que notifican **al cliente**: apartado creado, activado, comprobante aprobado, comprobante rechazado, apartado completado, cancelado, entregado y mensaje de chat sin leer (`REQ-CHT-005`).
- RB-2 Eventos que notifican **al `owner`** del negocio: factura emitida, factura vencida, suscripción suspendida y **acceso de soporte concedido** (`REQ-PLT-005` RB-4).
- RB-3 Canales por tipo según `NotificationType::channels()`; los transaccionales del cliente van por push **y** email, y **no** son función de plan (`REQ-NOT-001` RB-10).
- RB-4 Se envían **fuera de la transacción** de negocio (`afterCommit`), de forma que nunca se notifica algo que luego se revierte.
- RB-5 Un fallo de notificación **no** revierte la operación de negocio.
- RB-6 El contenido de la aprobación incluye el nombre del negocio, el importe acreditado en USD, el declarado con su moneda y tasa, las cuotas amortizadas y el saldo restante.
- RB-7 El contenido del rechazo incluye el motivo íntegro y el CTA para reportar de nuevo (`REQ-PAY-006` RB-3).
- RB-8 **Todo mensaje al cliente identifica el negocio** en el título o en la primera línea.
- RB-9 Los emails no incluyen adjuntos ni datos de otros clientes ni de otras organizaciones.
- RB-10 Textos en español desde archivos de traducción, con plantilla Markdown común. **El remitente es Apartar** ("Apartar · en nombre de Ferretería El Tornillo"): un dominio propio por negocio queda fuera de alcance y falsear el remitente dañaría la reputación de entrega.
- RB-11 Se envían con la organización suspendida (`REQ-SUB-007` RB-3).

**Happy path**
1. El negocio aprueba un comprobante; la transacción hace `commit`.
2. El listener encolado envía push + email: "Ferretería El Tornillo acreditó $60,00 · cuotas 3 y 4 · saldo pendiente $30,00".
3. El cliente abre el enlace y ve su cronograma actualizado.

**Sad paths y edge cases**
- SP-1 Rollback de la transacción → la notificación **no** se envía (RB-4), con test que fuerza el fallo.
- SP-2 Cliente sin push → solo email; sin email tampoco → solo el portal.
- SP-3 Email rebotado → se registra; sin gestión de bounces en v1.
- SP-4 Cola detenida → se acumulan y se envían al reanudar; los datos se serializan por identificador y se rehidratan.
- SP-5 Estado ya cambiado al procesarse → el mensaje se envía tal como se generó; se acepta la desincronización menor.
- SP-6 Notificación duplicada por reintento de la cola → aceptada: los transaccionales no llevan registro de idempotencia, a diferencia de los recordatorios (`REQ-NOT-002` RB-2).
- SP-7 Notificación al `owner` de una organización con varios `owner` → se envía a **todos** los `owner` activos.
- SP-8 `support_access_granted` cuyo email falla → el grant **no** se revierte; el aviso en el backoffice y la auditoría son los canales que no dependen del correo (`REQ-PLT-005` SP-6).

**Fuera de alcance inmediato**
- Centro de notificaciones con historial en el portal.
- Gestión de rebotes y bajas de email.
- Plantillas editables por el negocio.
- Remitente con dominio propio del negocio.

**Gate de validación**
- Superficie esperada: `app/Notifications/*.php`, `app/Listeners/SendLayawayNotifications.php`, `app/Listeners/SendSubscriptionNotifications.php`, `app/Domain/Notification/NotificationType.php`, `lang/es/notifications.php`.
- Tipos esperados: `NotificationType`, `NotificationAudience`, una clase por tipo transaccional.
- Anti-`SHALLOW`: `NotificationType::channels()` y `audience()` (mapas completos) junto al enum; cada notificación implementa `toWebPush()` y `toMail()` con composición > 4 líneas.
- Tests: rollback no notifica; fallo de notificación no revierte; contenido de aprobación con negocio, importe, tasa y cuotas; rechazo con motivo íntegro; **el nombre del negocio aparece en todo mensaje al cliente** (RB-8); notificación al `owner` llega a todos los `owner`; sin adjuntos ni datos ajenos; los transaccionales funcionan en plan Free.

---

### REQ-NOT-004 · Envío manual segmentado de Web Push

**Historia de usuario**
> Como negocio, quiero enviar un aviso puntual a un cliente o a todos los que están en mora, para reforzar mi cobranza.

**Contratos de datos**
- `SendManualPushAction(ManualPushData $data, User $actor)`; job `SendWebPush` en la cola `default`.
- Rutas `GET /backoffice/notificaciones`, `POST /backoffice/notificaciones`.
- **Requiere:** función de plan `web_push`.

```php
final readonly class ManualPushData
{
    public function __construct(
        public string $title,           // ≤ 60 caracteres
        public string $body,            // ≤ 180 caracteres
        public ?string $url,
        /** @var list<int>|null Selección explícita de fichas de cliente. */
        public ?array $customerIds,
        /** Alternativa: el filtro de cobranza activo. */
        public ?CollectionFilters $segment,
    ) {}
}
```

**Reglas de negocio y precondiciones**
- RB-1 **Es función de plan** (`web_push`). En Free el formulario se muestra deshabilitado con el motivo y el alcance potencial ("llegarías a 34 clientes").
- RB-2 Dos modos de destinatarios: selección explícita de fichas, o **segmento** derivado del filtro activo de cobranza, reutilizando `CollectionFilters` (`REQ-BOF-042` RB-1).
- RB-3 Exactamente uno de los dos modos; ambos o ninguno → `422`.
- RB-4 Antes de enviar, la UI muestra el **conteo exacto de destinatarios y de alcanzables** y exige confirmación explícita.
- RB-5 El envío va por cola, un job por destinatario, con reporte agregado de entregados, fallidos y **no alcanzables**.
- RB-6 Los destinatarios se resuelven con `NotifiableResolver` (`REQ-NOT-001` RB-2): si la ficha tiene cuenta vinculada, llega a los dispositivos de la cuenta. **El negocio nunca ve nada de esa cuenta**, solo si es alcanzable o no.
- RB-7 El envío manual **no** cae a email: es un canal de refuerzo, no un boletín. Sin push, el cliente cuenta como no alcanzable.
- RB-8 Título y cuerpo con límites de longitud, sin HTML, sin personalización por variables en v1.
- RB-9 Se audita con actor, texto, modo y número de destinatarios.
- RB-10 Rate limit: máximo **3 envíos masivos por hora y por organización**, para evitar spam accidental a su cartera.
- RB-11 **Solo alcanza a fichas de su propia organización**; el segmento y la selección explícita están acotados por el global scope.
- RB-12 Este requisito cubre el envío a **clientes**. El aviso a **suscriptores de catálogo** es distinto y vive en `REQ-ACC-006`: distinto destinatario, distinta función de plan y distinta mecánica de idempotencia.

**Happy path**
1. El operador filtra la cobranza por "en mora > 15 días" (41 clientes) y pulsa "Enviar aviso".
2. Escribe título y cuerpo, ve "se enviará a 41 clientes (35 alcanzables por push)" y confirma.
3. Se encolan 35 jobs; el reporte final muestra 33 entregados, 2 fallidos y 6 no alcanzables.

**Sad paths y edge cases**
- SP-1 Ningún destinatario alcanzable → `422` con la explicación, sin encolar nada.
- SP-2 Segmento que cambia entre el conteo y el envío → se recalcula al ejecutarse y el reporte anota la diferencia.
- SP-3 Suscripción caducada al enviar → `410` → se purga (`REQ-NOT-005`) y cuenta como fallida.
- SP-4 Cuarto envío masivo en la hora → `429`.
- SP-5 Texto que excede los límites → `422`.
- SP-6 Selección con una ficha inexistente o **de otra organización** → `422` / no existe bajo el scope; nada se envía.
- SP-7 URL de destino externa → permitida, con advertencia de que se abrirá fuera de la plataforma.
- SP-8 Plan Free → `403` por API y formulario deshabilitado en UI.
- SP-9 Organización suspendida → **bloqueado**: el envío manual es una acción de marketing, no parte de la operación protegida del apartado (a diferencia de `REQ-NOT-002` RB-11).

**Fuera de alcance inmediato**
- Personalización con datos del cliente.
- Programación del envío.
- Envío masivo por email.
- Segmentos guardados.

**Gate de validación**
- Superficie esperada: `app/Actions/Notification/SendManualPushAction.php`, `app/Jobs/SendWebPush.php`, `app/Http/Requests/Backoffice/SendManualPushRequest.php`, `resources/js/pages/backoffice/notifications/Compose.tsx`.
- Tipos esperados: `ManualPushData`, `PushDeliveryReport`, `SendManualPushAction`, `PlanFeatureUnavailableException`.
- Anti-`SHALLOW`: `SendManualPushAction::handle()` (resolución por los dos modos, comprobación de función de plan, conteo de alcanzables, encolado, auditoría) y `PushDeliveryReport` superan 4 líneas.
- Tests: los dos modos funcionan; ambos o ninguno da `422`; el segmento reutiliza `CollectionFilters` (equivalencia con la vista de cobranza); no alcanzables contabilizados; `410` purga; rate limit al cuarto; Free da `403`; **ficha de otra organización no se puede seleccionar ni alcanzar** (fuga); suspendida bloquea; el negocio no ve datos de la cuenta vinculada.

---

### REQ-NOT-005 · Idempotencia, saneamiento y observabilidad

**Historia de usuario**
> Como cliente, quiero que nadie me mande el mismo aviso dos veces; y como negocio, quiero que las suscripciones muertas se limpien solas.

**Contratos de datos**
- `NotificationDispatch` con `UNIQUE(installment_id, type, channel)`; `CatalogDigestDispatch` con `UNIQUE(catalog_id, account_id, digest_date)` (`REQ-ACC-006`).
- Job `PurgeStalePushSubscriptions` (semanal); campo `push_subscriptions.last_used_at`.

**Reglas de negocio y precondiciones**
- RB-1 Los recordatorios de cobro son **idempotentes** por (`installment_id`, `type`, `channel`), y los digests por (`catalog_id`, `account_id`, `digest_date`). En ambos casos la garantía es una restricción `UNIQUE` de la base de datos, **no** una comprobación previa en PHP, que sería susceptible de carrera.
- RB-2 El registro del envío se escribe **antes** de encolar; si el encolado falla, se revierte en la misma transacción para no perder el aviso.
- RB-3 Una respuesta `404` o `410` del servicio de push **elimina** la suscripción de inmediato.
- RB-4 `last_used_at` se actualiza en cada envío correcto; las suscripciones sin uso correcto en **180 días** se purgan semanalmente.
- RB-5 Los fallos se registran con el tipo de notificación y el motivo, **sin datos personales** en el log y sin identificar al cliente.
- RB-6 Los jobs de notificación tienen `tries = 3` con backoff exponencial y van a `failed_jobs` al agotarse; un fallo permanente nunca bloquea la cola.
- RB-7 El backoffice dispone de una vista mínima de los últimos envíos manuales y digests con su reporte, acotada a su organización.
- RB-8 **`failed_jobs` alimenta la pantalla de Salud de la plataforma** (`REQ-PLT-006` RB-1): un pico de fallos de notificación es un problema de plataforma, no de un negocio.
- RB-9 `NotificationDispatch` es del tenant (lleva `organization_id`); `CatalogDigestDispatch` y `push_subscriptions` son de plataforma, porque cruzan la frontera cuenta ↔ organización.

**Happy path**
1. El comando de recordatorios intenta enviar el offset −3 de una cuota ya notificada.
2. La inserción viola la unicidad; la acción lo interpreta como "ya enviado" y lo salta sin error.
3. La purga semanal elimina 4 suscripciones muertas.

**Sad paths y edge cases**
- SP-1 Dos ejecuciones concurrentes → la unicidad garantiza un solo envío; la segunda captura la violación y continúa.
- SP-2 Encolado que falla tras registrar → rollback del registro (RB-2), y el próximo ciclo lo reintenta.
- SP-3 Servicio de push que responde `429` → el job reintenta con backoff; **no** se purga la suscripción (solo `404`/`410` purgan).
- SP-4 Purga que eliminaría la única suscripción de una persona activa → se purga igual; puede volver a suscribirse y sigue recibiendo email.
- SP-5 `failed_jobs` creciendo por VAPID inválida → visible en Salud de plataforma (RB-8).
- SP-6 Notificación transaccional duplicada por reintento → aceptada (`REQ-NOT-003` SP-6).
- SP-7 Suscripción reasignada de `Customer` a `Account` (`REQ-NOT-001` SP-4) → el `endpoint` es el mismo, así que no se duplica ni se pierde `last_used_at`.
- SP-8 Registro de dispatch de una organización eliminada lógicamente → se conserva; no se borran registros de envío.

**Fuera de alcance inmediato**
- Alertas automáticas al equipo por tasa de fallo.
- Métricas de entrega y apertura.
- Reintento manual de un envío fallido desde la UI.

**Gate de validación**
- Superficie esperada: `app/Models/NotificationDispatch.php`, `app/Models/CatalogDigestDispatch.php`, `app/Jobs/PurgeStalePushSubscriptions.php`, `app/Jobs/SendWebPush.php`, `app/Actions/Notification/DispatchDueRemindersAction.php`.
- Tipos esperados: `NotificationDispatch`, `CatalogDigestDispatch`, `PurgeStalePushSubscriptions`, `PushDeliveryReport`.
- Anti-`SHALLOW`: `SendWebPush::handle()` (envío, interpretación de códigos, purga, `last_used_at`, reintentos) y `PurgeStalePushSubscriptions::handle()` superan 4 líneas.
- Tests: ejecución concurrente envía una sola vez (para recordatorios y para digests); fallo de encolado revierte el registro; `410` purga y `429` no; `last_used_at` actualizado; purga a los 180 días; `tries = 3`; el log no contiene datos personales; reasignación de suscripción no duplica.
