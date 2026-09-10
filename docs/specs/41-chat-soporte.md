# Módulo 41 — Chat cliente ↔ negocio (`CHT`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Decisión `D-3` de [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md), [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md)
> Requisitos: `REQ-CHT-001` … `REQ-CHT-005`
>
> **Enrutamiento del pivote (`D-3`):** el hilo es `(organization_id, customer_id)` — **uno permanente por cliente y por negocio**. El contexto del negocio se deriva del catálogo desde el que la persona escribe. Una persona con apartados en dos negocios tiene dos hilos aislados, y **nunca se cruza un mensaje entre organizaciones**.

## Contratos compartidos del módulo

```php
enum MessageSenderType: string
{
    case Customer = 'customer';   // el cliente final
    case Team = 'user';           // un miembro del equipo del negocio
}

/** Hilo único y permanente por cliente dentro de una organización. */
final class Conversation extends Model
{
    use BelongsToOrganization;

    // organization_id, customer_id, last_message_at, customer_unread_count, admin_unread_count

    public function markReadBy(MessageSenderType $reader): void { /* … */ }
}
```

```ts
export interface ChatMessage {
  readonly id: number;
  readonly sender: 'customer' | 'team';
  readonly senderName: string;
  readonly body: string;
  readonly sentAt: string;
  readonly readAt: string | null;
}

export interface ChatThread {
  readonly conversationId: number;
  readonly business: { readonly name: string; readonly slug: string; readonly logoUrl: string | null };
  readonly messages: readonly ChatMessage[];
  readonly hasMore: boolean;
  readonly unreadCount: number;
}
```

---

### REQ-CHT-001 · Widget de chat y enrutamiento por catálogo

**Historia de usuario**
> Como cliente, quiero escribirle al negocio desde su catálogo o desde mi portal, para resolver una duda sobre un producto o sobre mi apartado sin buscar otro canal.

**Contratos de datos**
- `<ChatWidget />` presente en el catálogo (`/c/{slug}`) y en el portal.
- Rutas `GET /mi-cuenta/chat`, `GET /mi-cuenta/chat/{organization:slug}`, `POST /c/{slug}/chat`, `POST /mi-cuenta/chat/{organization:slug}`.
- `SendMessageAction(Organization $org, Customer $customer, MessageSenderType $sender, int $senderId, string $body, string $idempotencyKey)`.

**Reglas de negocio y precondiciones**
- RB-1 **El destinatario se deriva del contexto, no lo elige la persona.** En un catálogo, la organización es la del catálogo (`REQ-CAT-021` RB-3); en el portal, es la del bloque de negocio desde el que abre el chat. Nunca hay ambigüedad ni un selector de "a quién escribo".
- RB-2 El hilo es **único y permanente por `(organization_id, customer_id)`**, creado implícitamente al primer mensaje con `firstOrCreate` seguro ante concurrencia (`UNIQUE(organization_id, customer_id)`).
- RB-3 **Escribir exige tener ficha de cliente en ese negocio.** No basta con tener una `Account`: la conversación es con un negocio concreto y se ancla a la ficha comercial. Un visitante sin ficha ve el widget con el CTA "aparta o identifícate para escribirle a este negocio".
- RB-4 Consecuencia de RB-3: un **suscriptor que no es cliente no puede escribir** (`REQ-ACC-007` fuera de alcance). Es deliberado: abrir un canal de mensajería a cualquiera que pulse "seguir" convertiría el chat en un buzón de spam para el negocio.
- RB-5 Mensajes de **texto plano**, máximo 2000 caracteres, sin adjuntos ni formato.
- RB-6 El widget carga los últimos 30 mensajes y pagina hacia atrás con scroll infinito.
- RB-7 El envío es optimista en la UI (aparece de inmediato como "enviando") y se confirma o se marca como fallido con reintento.
- RB-8 Al abrir el hilo se marcan como leídos los mensajes del equipo y `customer_unread_count` pasa a 0.
- RB-9 En **sesión de cuenta**, el portal lista **todos** los hilos de la persona, uno por negocio, con el logo de cada uno. En **sesión acotada**, solo el de ese negocio (`REQ-ACC-005` RB-1).
- RB-10 Historial completo persistido. En plan Free, el negocio **ve solo los últimos 30 días** en su bandeja (`REQ-CHT-002` RB-8); los mensajes **no se borran**, solo dejan de mostrarse.
- RB-11 **Funciona con la organización suspendida** (`REQ-SUB-007` RB-3): el cliente debe poder comunicarse con el negocio al que le debe dinero.
- RB-12 Idempotencia por `idempotencyKey` local del cliente.

**Happy path**
1. La cliente con apartado en la Ferretería abre el widget desde su portal.
2. Ve su historial con ese negocio y escribe "¿puedo pagar la cuota el lunes?"; el mensaje aparece de inmediato.
3. Un miembro del equipo responde y el mensaje llega en tiempo real (`REQ-CHT-003`).

**Sad paths y edge cases**
- SP-1 Visitante sin ficha en ese negocio → CTA de apartar o identificarse; no puede escribir.
- SP-2 Persona con `Account` que sigue el catálogo pero nunca apartó → tampoco puede escribir (RB-4), y se le explica que el canal se abre al apartar.
- SP-3 Mensaje vacío o de solo espacios → bloqueado en cliente y en servidor (`422`).
- SP-4 Más de 2000 caracteres → `422`; el contador de la UI lo previene.
- SP-5 Envío sin conexión → queda en estado fallido con botón de reintento; **no** se pierde el texto.
- SP-6 Doble envío → `idempotencyKey` evita el duplicado.
- SP-7 Sesión caducada al enviar → `401`; la UI conserva el texto y redirige al acceso preservando el borrador en `localStorage`.
- SP-8 Rate limit: 20 mensajes por minuto por ficha → `429` con aviso amable.
- SP-9 Ficha fusionada (`REQ-IDN-006`) → el hilo fusionado conserva todos los mensajes en orden cronológico.
- SP-10 **Intento de enviar un mensaje a una organización donde la persona no tiene ficha** manipulando la petición → `403`; la organización nunca sale del payload sin validarse contra `PortalContext`.
- SP-11 Persona con fichas en 5 negocios → 5 hilos independientes en su portal; escribir en uno no toca los demás.

**Fuera de alcance inmediato**
- Adjuntos, imágenes y emojis.
- Edición y borrado de mensajes.
- Chat pre-venta para quien no es cliente.
- Respuestas automáticas o bot.
- Chat con la plataforma (soporte de Apartar) — se documenta como necesidad futura.

**Gate de validación**
- Superficie esperada: `app/Actions/Chat/SendMessageAction.php`, `app/Http/Controllers/Portal/ChatController.php`, `app/Http/Controllers/Catalog/ChatController.php`, `app/Models/Conversation.php`, `app/Models/Message.php`, `resources/js/features/chat/components/ChatWidget.tsx`, `resources/js/features/chat/hooks/useConversation.ts`.
- Tipos esperados: `Conversation`, `Message`, `MessageSenderType`, `ChatMessage`, `ChatThread`, `SendMessageAction`, `NoCustomerRecordException`.
- Anti-`SHALLOW`: `SendMessageAction::handle()` (resolución de la organización desde el contexto, validación de ficha, creación implícita del hilo, contadores, broadcast, notificación diferida) y `useConversation` (carga paginada, envío optimista, reintento, marcado de leído) superan 4 líneas.
- Tests: **la organización se deriva del catálogo y no del payload** (test explícito de RB-1); suscriptor sin ficha no puede escribir (RB-4); hilo creado una sola vez con concurrencia; mensaje vacío y largo rechazados; `idempotencyKey` evita duplicados; rate limit; abrir marca leídos; **enviar a una organización sin ficha da `403`** (fuga); sesión de cuenta lista N hilos y acotada solo uno; funciona con organización suspendida.

---

### REQ-CHT-002 · Bandeja del negocio

**Historia de usuario**
> Como negocio, quiero ver todos los hilos de mis clientes en un lugar con los pendientes primero, para responder sin dejar a nadie esperando.

**Contratos de datos**
- Rutas `GET /backoffice/chat` (lista) y `GET /backoffice/chat/{conversation}` (hilo).
- Props `{ threads: ConversationRow[], active: ChatThread | null }`.
- `ConversationListQuery`.

**Reglas de negocio y precondiciones**
- RB-1 La bandeja está **acotada a la organización**: solo sus hilos, con su propio global scope. Un negocio nunca ve un hilo de otro, ni siquiera de la misma persona.
- RB-2 Todos los miembros (`owner` y `member`) ven **todos** los hilos de su organización; sin asignación de agente ni SLA.
- RB-3 Orden por defecto: hilos con mensajes sin leer primero, luego por `last_message_at` descendente.
- RB-4 Cada fila muestra nombre y documento del cliente, extracto del último mensaje, hora y badge de no leídos.
- RB-5 El panel del hilo activo muestra un **encabezado con contexto del cliente**: apartados activos, saldo pendiente, cuotas vencidas, teléfono y enlace a su perfil (`REQ-BOF-041`). Responder sin ese contexto sería inútil para cobranza, y es la diferencia entre un chat y una herramienta de cobro.
- RB-6 El contexto mostrado es **solo de esta organización**: nunca los apartados que la persona tenga en otro negocio (`REQ-ACC-007` RB-4).
- RB-7 Al abrir un hilo se marcan como leídos los mensajes del cliente y `admin_unread_count` pasa a 0.
- RB-8 **Retención de historial visible por plan**: en Free se muestran los **últimos 30 días**; en Pro y Business, todo. Los mensajes nunca se borran (`REQ-CHT-001` RB-10): al subir de plan reaparecen íntegros.
- RB-9 La respuesta del equipo usa la misma `SendMessageAction` con `sender = team` y el `id` del usuario; el cliente ve el nombre del miembro y el del negocio.
- RB-10 Búsqueda de hilos por nombre, documento, teléfono o contenido de mensaje (`LIKE`, sin fulltext en v1), acotada a la organización.
- RB-11 **Disponible con la organización suspendida** (`REQ-SUB-007` RB-4): atender a un cliente que le debe dinero no se bloquea.

**Happy path**
1. Un miembro abre `/backoffice/chat` y ve 3 hilos con no leídos arriba.
2. Abre el primero: ve el contexto ("2 apartados · $270 pendientes · 1 cuota vencida · 0414-1234567") y el mensaje del cliente.
3. Responde; el mensaje llega en tiempo real y el contador pasa a 0.

**Sad paths y edge cases**
- SP-1 Dos miembros respondiendo el mismo hilo a la vez → ambos mensajes se envían y se muestran en orden; no hay bloqueo (es un canal conversacional).
- SP-2 Hilo de un cliente sin apartados activos → el encabezado lo indica, con su historial.
- SP-3 Hilo de una ficha absorbida por una fusión → no aparece; sus mensajes están en el hilo de la superviviente.
- SP-4 Búsqueda con muchos resultados → paginación de servidor.
- SP-5 Miembro desactivado con el chat abierto → la sesión se corta en su siguiente petición (`REQ-BOF-001` RB-4).
- SP-6 Cliente que borra su navegador y vuelve por documento + código → mismo hilo, historial intacto.
- SP-7 Plan Free con un hilo de 6 meses → se muestran los últimos 30 días con un aviso de que el resto se ve con Pro; **nada se ha borrado** (RB-8).
- SP-8 **Hilo de otra organización por manipulación de la URL** → `404`.
- SP-9 Cliente que también escribe a otro negocio → invisible aquí (RB-6).

**Fuera de alcance inmediato**
- Asignación de hilos, SLA y métricas de respuesta.
- Respuestas rápidas o plantillas.
- Notas internas dentro del hilo.
- Cierre o archivado de hilos.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/ChatController.php`, `app/Queries/ConversationListQuery.php`, `resources/js/pages/backoffice/chat/Index.tsx`, `resources/js/features/chat/components/ThreadContextHeader.tsx`.
- Tipos esperados: `ConversationRow`, `ConversationListQuery`, `ChatThread`.
- Anti-`SHALLOW`: `ConversationListQuery::forOrganization()` (orden por no leídos y último mensaje, extracto, búsqueda por cuatro criterios, retención por plan, sin `N+1`) supera 4 líneas.
- Tests: orden con no leídos primero; contexto del cliente coincide con su perfil y **no incluye datos de otros negocios** (test de RB-6); abrir marca leídos; búsqueda por los cuatro criterios; sin `N+1`; **plan Free limita la vista a 30 días sin borrar mensajes y Pro los muestra todos** (test de RB-8); hilo ajeno da `404`; funciona con organización suspendida.

---

### REQ-CHT-003 · Transporte en tiempo real y degradación

**Historia de usuario**
> Como cliente, quiero ver la respuesta del negocio sin recargar la página, para tener una conversación fluida.

**Contratos de datos**
- **Laravel Reverb** como servicio Docker propio, expuesto por Traefik en `wss://ws.apartar.store` (`REQ-INF-005`).
- Canales privados `organization.{orgId}.conversation.{id}` y `organization.{orgId}.chat` (badge del negocio).
- Evento `MessageSent` con el `ChatMessage` serializado.
- `laravel-echo` + `pusher-js`; fallback con `@tanstack/react-query`.

**Reglas de negocio y precondiciones**
- RB-1 **El nombre del canal incluye la organización**, y su autorización comprueba dos cosas: que el `orgId` del canal coincide con la organización del hilo, y que el solicitante es el **cliente titular** (guard `customer` o `account` con ficha vinculada) o un **miembro activo de esa organización** (guard `web`). Cualquier otro caso se rechaza.
- RB-2 Incluir el `organization_id` en el nombre del canal no es decorativo: hace que un error en la autorización sea detectable y que un canal nunca pueda ser ambiguo entre tenants.
- RB-3 El evento se difunde **después del commit** (`afterCommit`).
- RB-4 Fallback obligatorio: si el WebSocket no conecta en 5 s o se cae, el hook pasa a polling cada **10 s** con `@tanstack/react-query`, y lo indica discretamente ("modo sin conexión en vivo").
- RB-5 Al reconectar, el cliente pide los mensajes posteriores al último `id` conocido y **deduplica** por `id`.
- RB-6 El payload difundido lleva el mensaje y el nombre del remitente; **nunca** datos financieros del cliente ni `account_id`.
- RB-7 `@tanstack/react-query` se usa **solo** para este fallback, el badge, la tasa y la paginación del feed (`REQ-QAX-002` RB-4); el hilo inicial llega por page props de Inertia.
- RB-8 Si Reverb no está configurado, el chat funciona íntegramente en modo polling, sin errores en consola.
- RB-9 Un solo nodo de Reverb sirve a **todas** las organizaciones; el aislamiento es por autorización de canal, no por infraestructura separada.

**Happy path**
1. La cliente abre el widget; Echo se suscribe a `organization.7.conversation.42`.
2. El negocio responde; el evento llega por WebSocket y el mensaje se añade al hilo.
3. El contador de no leídos se actualiza sin recargar.

**Sad paths y edge cases**
- SP-1 WebSocket bloqueado por la red del cliente → fallback a polling en 5 s; chat funcional.
- SP-2 Reverb caído → todos caen a polling; el sistema sigue operativo y se refleja en Salud de plataforma (`REQ-PLT-006` RB-1).
- SP-3 Reconexión tras 10 minutos sin red → se piden los mensajes faltantes por `id` y se deduplican.
- SP-4 Mensaje recibido por WebSocket **y** por polling → deduplicación por `id`.
- SP-5 **Intento de suscripción a un canal de otra organización** → rechazado por la autorización (test explícito de RB-1).
- SP-6 **Miembro de la organización A intentando el canal de un hilo de la organización B** → rechazado.
- SP-7 Cliente con dos pestañas → ambas reciben el evento; el marcado de leído en una se refleja en la otra en su siguiente refresco.
- SP-8 Broadcast que falla → el mensaje ya está persistido; aparecerá en el siguiente polling o recarga.
- SP-9 Cliente con sesión de cuenta suscrito a los canales de sus 3 negocios → tres suscripciones simultáneas, cada una autorizada por separado.

**Fuera de alcance inmediato**
- Presencia ("en línea ahora").
- Confirmación de entrega a nivel de transporte.
- Escalado horizontal de Reverb.

**Gate de validación**
- Superficie esperada: `app/Events/MessageSent.php`, `routes/channels.php`, `config/reverb.php`, `resources/js/lib/echo.ts`, `resources/js/features/chat/hooks/useRealtimeMessages.ts`.
- Tipos esperados: `MessageSent`, `ChatMessage`, `useRealtimeMessages`.
- Anti-`SHALLOW`: la autorización de `organization.{orgId}.conversation.{id}` en `channels.php` (coincidencia de organización + tres guards) y `useRealtimeMessages` (suscripción, temporizador de fallback, reconexión con deduplicación) superan 4 líneas.
- Tests: autorización acepta al titular y al miembro de esa organización y **rechaza a un miembro de otra** (test de RB-1); broadcast `afterCommit` (rollback no difunde); fallback tras 5 s; deduplicación por `id`; sin configuración de Reverb funciona en polling; payload sin datos financieros ni `account_id`.

---

### REQ-CHT-004 · Lectura, no leídos e indicador de escritura

**Historia de usuario**
> Como cliente, quiero saber si leyeron mi mensaje y ver cuándo están escribiendo, para no quedarme con la duda de si me van a contestar.

**Contratos de datos**
- Campo `messages.read_at`; contadores `conversations.customer_unread_count` y `admin_unread_count`.
- Evento efímero `UserTyping` (no persistido) en el canal de la conversación.
- Comando `chat:recount`.

**Reglas de negocio y precondiciones**
- RB-1 `read_at` se fija al **abrir el hilo** la contraparte, no al recibir el evento.
- RB-2 Los contadores se mantienen **materializados** en `conversations` y se actualizan en la misma transacción del envío y del marcado, con operaciones atómicas de BD (`increment`/`decrement`); las listas no cuentan mensajes.
- RB-3 El indicador "escribiendo…" se emite como evento efímero con `throttle` de 2 s y expira a los 4 s sin nuevos eventos; nunca se persiste.
- RB-4 El cliente ve "leído" cuando **cualquier** miembro del equipo abrió el hilo; no hay lectura por agente individual.
- RB-5 El badge global del backoffice suma los **hilos** con `admin_unread_count > 0`, no los mensajes.
- RB-6 El badge del portal, en sesión de cuenta, suma los hilos con no leídos **de todos sus negocios**, y el detalle se desglosa por negocio.
- RB-7 Marcar leído es idempotente.
- RB-8 El indicador de escritura no se emite ni se muestra en modo polling (`REQ-CHT-003` RB-4).
- RB-9 `chat:recount` recalcula los contadores desde `messages` para todas las organizaciones, es idempotente, y su resultado alimenta Salud de plataforma si detecta derivas.

**Happy path**
1. La cliente envía un mensaje; `admin_unread_count` pasa a 1 y el badge del negocio se incrementa.
2. Un miembro abre el hilo: `read_at` se fija, el contador va a 0 y la cliente ve "leído".
3. Mientras el miembro escribe, la cliente ve "escribiendo…".

**Sad paths y edge cases**
- SP-1 Contador desincronizado por un fallo → `chat:recount` lo recalcula.
- SP-2 Evento de escritura sin WebSocket → simplemente no se muestra (RB-8).
- SP-3 Miembro que abre y cierra el hilo sin leer → se marca leído igual; se acepta como convención.
- SP-4 Dos miembros abriendo el hilo a la vez → el marcado es idempotente y el contador queda en 0.
- SP-5 Mensajes marcados como leídos mientras llegan nuevos → los nuevos incrementan de nuevo; no hay carrera porque la actualización es atómica.
- SP-6 Indicador "colgado" por desconexión abrupta → expira a los 4 s.
- SP-7 Badge del portal con hilos en 3 negocios → suma correcta y desglose por negocio (RB-6).
- SP-8 Plan Free con historial visible limitado a 30 días → el contador de no leídos cuenta **todos** los no leídos, incluidos los de mensajes fuera de la ventana visible: ocultar un mensaje no es marcarlo leído.

**Fuera de alcance inmediato**
- Lectura por miembro individual.
- Estados de entrega de tres niveles.
- Reacciones a mensajes.

**Gate de validación**
- Superficie esperada: `app/Models/Conversation.php` (`markReadBy`), `app/Events/UserTyping.php`, `app/Console/Commands/RecountConversations.php`, `resources/js/features/chat/hooks/useTypingIndicator.ts`.
- Tipos esperados: `UserTyping`, `MessageSenderType`, contadores en `Conversation`.
- Anti-`SHALLOW`: `Conversation::markReadBy()` (marcado, contador atómico, idempotencia) y `RecountConversations::handle()` (recorrido multi-tenant) superan 4 líneas.
- Tests: contadores correctos tras 10 mensajes en ambas direcciones; marcado idempotente; `chat:recount` corrige una deriva inyectada en varias organizaciones; `throttle` del indicador; expiración a los 4 s; el badge cuenta hilos y no mensajes; el badge del portal suma varios negocios; **el límite de 30 días de Free no altera el conteo de no leídos** (test de SP-8).

---

### REQ-CHT-005 · Notificación de mensaje no leído

**Historia de usuario**
> Como cliente, quiero que me llegue un aviso al teléfono si el negocio me responde y no tengo la página abierta, para no perder la respuesta.

**Contratos de datos**
- Job diferido `NotifyUnreadMessage` con `delay(2 minutes)`.
- `NotificationType::ChatMessage`.

**Reglas de negocio y precondiciones**
- RB-1 Cuando un miembro del equipo envía un mensaje, se encola un job **diferido 2 minutos**.
- RB-2 Al ejecutarse, el job **revalida**: si el mensaje ya tiene `read_at`, **no** notifica. Así quien está mirando la pantalla no recibe un push redundante.
- RB-3 Canal: **Web Push** a las suscripciones que resuelva `NotifiableResolver` (`REQ-NOT-001` RB-2). Sin email: convertir una conversación en una cadena de correos es peor experiencia.
- RB-4 Agrupación: si el equipo envía 5 mensajes seguidos, se notifica **una sola vez** por ventana de 2 minutos.
- RB-5 Los mensajes del **cliente** no generan push al equipo: su canal es el badge en tiempo real y la bandeja (`REQ-CHT-002`).
- RB-6 El push incluye **el nombre del negocio** y un extracto del mensaje (máx. 100 caracteres), y abre el hilo de ese negocio.
- RB-7 El extracto es literalmente el texto del miembro, sin datos añadidos por el sistema.
- RB-8 **No es función de plan**: responder a un cliente y que le llegue es parte del servicio, igual que los transaccionales (`REQ-NOT-001` RB-10).

**Happy path**
1. El negocio responde a las 15:00; se encola el job para las 15:02.
2. La cliente no abre la página; a las 15:02 el job comprueba que `read_at` es nulo y envía el push.
3. Ella pulsa la notificación y el widget del chat se abre en el hilo de ese negocio.

**Sad paths y edge cases**
- SP-1 La cliente lee el mensaje a las 15:01 → el job no notifica.
- SP-2 Cinco mensajes del equipo entre 15:00 y 15:01 → una sola notificación.
- SP-3 Cliente sin suscripción push → no se notifica por ningún canal; verá el badge al volver.
- SP-4 Suscripción caducada → `410` y purga (`REQ-NOT-005` RB-3).
- SP-5 Cola detenida más de 2 minutos → el push llega tarde; el job revalida y, si ya se leyó, no envía.
- SP-6 Pestaña abierta en segundo plano (no leído porque no está en foco) → se notifica, porque `read_at` sigue nulo; comportamiento deseado.
- SP-7 Cliente con hilos en dos negocios que recibe respuesta de ambos → dos notificaciones, cada una con su negocio (RB-6).
- SP-8 Organización suspendida → **se envía** (`REQ-SUB-007` RB-3).

**Fuera de alcance inmediato**
- Resumen por email de conversaciones no leídas.
- Notificación al equipo por mensajes de cliente sin atender.
- Ventana de agrupación configurable.

**Gate de validación**
- Superficie esperada: `app/Jobs/NotifyUnreadMessage.php`, `app/Notifications/ChatMessageNotification.php`, `app/Actions/Chat/SendMessageAction.php` (encolado diferido).
- Tipos esperados: `NotifyUnreadMessage`, `ChatMessageNotification`, `NotificationType::ChatMessage`.
- Anti-`SHALLOW`: `NotifyUnreadMessage::handle()` (revalidación de lectura, agrupación por ventana, resolución del destinatario, purga ante `410`) supera 4 líneas.
- Tests: mensaje leído antes de los 2 min no notifica; cinco mensajes producen una notificación; sin suscripción no envía email; `410` purga; mensajes de cliente no notifican al equipo; **el nombre del negocio aparece en el push** y dos negocios producen dos avisos; extracto acotado a 100 caracteres; funciona en plan Free y con organización suspendida.
