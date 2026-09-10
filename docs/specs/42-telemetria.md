# Módulo 42 — Telemetría e interacciones (`TLM`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md), [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md)
> Requisitos: `REQ-TLM-001` … `REQ-TLM-005`
>
> **Objetivo de esta iteración:** capturar y modelar bien los datos. El motor de recomendación sigue fuera de alcance; este módulo garantiza que, cuando se construya, los datos existan y sean utilizables.
>
> **Efecto del pivote:** cada evento gana tres dimensiones —**organización**, **catálogo** y **cuenta**— y aparecen eventos nuevos de suscripción y feed. `organization_id` es **nullable**, porque los eventos del feed y de la cuenta no pertenecen a ninguna organización, lo que hace de esta la única tabla del sistema con tenancy opcional.

## Contratos compartidos del módulo

```php
/** Catálogo cerrado de eventos. Añadir uno exige modificar la spec. */
enum TelemetryEventName: string
{
    // Catálogo (llevan organization_id y catalog_id)
    case CatalogViewed = 'catalog_viewed';
    case ProductViewed = 'product_viewed';
    case ProductListFiltered = 'product_list_filtered';
    case SearchPerformed = 'search_performed';
    case LayawayFormStarted = 'layaway_form_started';
    case LayawayCreated = 'layaway_created';
    case ReceiptUploaded = 'receipt_uploaded';
    case CurrencyToggled = 'currency_toggled';
    case DwellTimeRecorded = 'dwell_time_recorded';
    case CtaClicked = 'cta_clicked';
    // Cuenta y feed (sin organization_id)
    case AccountCreated = 'account_created';
    case CatalogSubscribed = 'catalog_subscribed';
    case CatalogUnsubscribed = 'catalog_unsubscribed';
    case FeedViewed = 'feed_viewed';
    case FeedProductClicked = 'feed_product_clicked';

    /** Esquema de validación del payload de este evento. */
    public function payloadRules(): array { /* … */ }
    /** true si es esencial y no requiere consentimiento de cookies. */
    public function isEssential(): bool { /* … */ }
    /** true si el evento pertenece a una organización. */
    public function isTenantScoped(): bool { /* … */ }
}
```

```ts
export interface TelemetryEvent {
  readonly name: TelemetryEventName;
  readonly occurredAt: string;          // ISO 8601
  readonly catalogSlug?: string;        // el servidor deriva organización y catálogo de aquí
  readonly subjectType?: 'product' | 'catalog' | 'layaway';
  readonly subjectId?: number;
  readonly payload?: Readonly<Record<string, string | number | boolean | null>>;
}
```

---

### REQ-TLM-001 · Catálogo cerrado de eventos y contrato del almacén

**Historia de usuario**
> Como responsable del producto, quiero que los eventos que registramos estén definidos y sean consistentes, para que los datos sirvan de verdad cuando construyamos las recomendaciones.

**Contratos de datos**
- Entidad `TelemetryEvent` (`telemetry_events`): `organization_id?`, `catalog_id?`, `event_name`, `visitor_id`, `account_id?`, `customer_id?`, `subject_type?`, `subject_id?`, `payload JSON`, `session_id`, `ip_hash`, `user_agent`, `occurred_at`.
- Índices `(event_name, occurred_at)`, `(organization_id, occurred_at)`, `(catalog_id, occurred_at)`, `(account_id)`, `(visitor_id)`.

**Reglas de negocio y precondiciones**
- RB-1 El catálogo de `event_name` es **cerrado**: un evento no listado se **descarta** en la ingesta, con contador en log. Es lo que evita que el almacén se llene de basura.
- RB-2 Cada evento declara el esquema de su `payload`; un payload inválido se **sanea** (se descartan las claves desconocidas) en lugar de rechazar el evento completo.
- RB-3 **Dimensiones del pivote.** Los eventos de catálogo llevan `organization_id` y `catalog_id`; los de cuenta y feed no llevan organización. **El cliente envía el `catalogSlug`, no los ids**: el servidor deriva la organización y el catálogo, de modo que un cliente no puede inyectar una organización ajena en un evento.
- RB-4 Payloads por evento:
  | Evento | `subject` | `payload` | Ámbito |
  |---|---|---|---|
  | `catalog_viewed` | `catalog` | `source` (`link` \| `storefront` \| `feed`) | organización |
  | `product_viewed` | `product` | `source` (`catalog` \| `search` \| `feed` \| `direct`) | organización |
  | `product_list_filtered` | — | `category`, `tags[]`, `sort`, `results` | organización |
  | `search_performed` | — | `term`, `results` | organización |
  | `layaway_form_started` | `product` | `source` | organización |
  | `layaway_created` | `layaway` | `product_id`, `quantity`, `source` | organización |
  | `receipt_uploaded` | `layaway` | `method` | organización |
  | `currency_toggled` | — | `to` | organización |
  | `dwell_time_recorded` | `product?` | `path`, `seconds` | organización |
  | `cta_clicked` | — | `cta`, `path` | organización |
  | `account_created` | — | `provider` | plataforma |
  | `catalog_subscribed` | `catalog` | `source` | plataforma **y** organización |
  | `catalog_unsubscribed` | `catalog` | — | plataforma **y** organización |
  | `feed_viewed` | — | `subscriptions_count`, `items` | plataforma |
  | `feed_product_clicked` | `product` | `position` | plataforma **y** organización |
- RB-5 `occurred_at` lo declara el cliente pero se **acota** en el servidor: no puede ser futuro ni tener más de 24 h.
- RB-6 El `payload` **nunca** contiene datos personales (nombre, documento, email, teléfono) ni importes de deuda; la vinculación a la persona es por `customer_id` o `account_id`.
- RB-7 La tabla es **append-only**, con dos excepciones documentadas: la vinculación retroactiva (`REQ-TLM-004`) y la purga por retención (`REQ-TLM-005`).
- RB-8 `account_id` **nunca** se expone a una organización (`REQ-ACC-007` RB-5): la analítica que ve el negocio se agrega por evento y por catálogo, no por persona identificable de la plataforma.

**Happy path**
1. Un visitante abre una ficha desde un catálogo.
2. El cliente emite `product_viewed` con `catalogSlug`, `subject = product:12` y `payload.source = 'catalog'`.
3. La ingesta valida el nombre, deriva `organization_id` y `catalog_id` del slug, sanea el payload y persiste la fila.

**Sad paths y edge cases**
- SP-1 Evento con nombre desconocido → descartado silenciosamente y contado en log; la respuesta al cliente sigue siendo `204` (no se filtra el catálogo interno).
- SP-2 Payload con claves extra → se descartan y el evento se guarda.
- SP-3 Tipo inválido en una clave (`results: "muchos"`) → esa clave se descarta y el evento se guarda sin ella.
- SP-4 `occurred_at` futuro o de hace 3 días → descartado.
- SP-5 `subject_id` inexistente → se guarda igual: `subject` es polimórfico **sin FK**, precisamente para que archivar un producto no borre su historia.
- SP-6 Payload de más de 4 KB → se trunca con marca de truncado.
- SP-7 **`catalogSlug` de un catálogo inexistente o inaccesible** → el evento se guarda **sin** `organization_id` ni `catalog_id`, o se descarta si el evento los exige por su ámbito. Nunca se adivina una organización.
- SP-8 Evento de catálogo emitido sin `catalogSlug` → descartado: sin él no hay forma legítima de atribuirlo.

**Fuera de alcance inmediato**
- Motor de recomendación.
- Panel de analítica avanzada para el negocio (más allá de `REQ-TLM-005` RB-8).
- Embudos de conversión y cohortes.
- Exportación de eventos.

**Gate de validación**
- Superficie esperada: `app/Domain/Telemetry/TelemetryEventName.php`, `app/Models/TelemetryEvent.php`, `app/Domain/Telemetry/EventSanitizer.php`, migración de `telemetry_events`.
- Tipos esperados: `TelemetryEventName`, `TelemetryEvent`, `EventSanitizer`, `SanitizedEvent`.
- Anti-`SHALLOW`: `TelemetryEventName::payloadRules()`, `isEssential()` e `isTenantScoped()` (mapas de los 15 eventos) junto al enum; `EventSanitizer::sanitize()` (nombre, payload, ventana temporal, derivación de organización desde el slug, truncado) junto a su clase.
- Tests: un test por evento validando su payload declarado; nombre desconocido descartado; claves extra eliminadas; `occurred_at` fuera de ventana descartado; **el cliente no puede inyectar un `organization_id`** (test explícito de RB-3); slug inaccesible no atribuye organización; payload truncado; sin datos personales.

---

### REQ-TLM-002 · Buffer del cliente e ingesta por lotes

**Historia de usuario**
> Como visitante, quiero que el catálogo vaya rápido, sin que la medición de mi comportamiento haga lenta la navegación.

**Contratos de datos**
- Hook `useTelemetry()` con `track(name, options)`.
- Buffer en memoria con respaldo en `sessionStorage`.
- Ruta `POST /api/telemetry` (lote de hasta 50 eventos), respuesta `204`.

**Reglas de negocio y precondiciones**
- RB-1 Los eventos se acumulan y se envían **por lotes**: al alcanzar 10, cada 15 segundos, o al abandonar la página.
- RB-2 El envío al abandonar usa `navigator.sendBeacon()`; si no está disponible, `fetch` con `keepalive`.
- RB-3 El endpoint **no procesa**: valida el tamaño del lote, despacha un job a Redis y responde `204`. Ninguna escritura sincrónica en BD.
- RB-4 El endpoint es **público** (visitantes anónimos incluidos), con rate limit de **60 peticiones por minuto** por IP. Es público porque el catálogo lo es, pero por eso mismo **no confía en nada de lo que recibe** (`REQ-TLM-001` RB-3).
- RB-5 El buffer se respalda en `sessionStorage` para que una navegación dura no pierda eventos; se limpia al confirmarse el envío.
- RB-6 La telemetría **nunca** rompe la aplicación: todo `track()` va envuelto en `try/catch` y un fallo de red se descarta tras un reintento.
- RB-7 Ningún evento se emite antes del consentimiento de cookies, salvo los esenciales (`REQ-TLM-005` RB-3).
- RB-8 Cuerpo máximo de 64 KB; un lote mayor se rechaza con `413`.
- RB-9 Un mismo lote puede contener eventos **de varias organizaciones** (por ejemplo, clics en el feed sobre productos de negocios distintos). El job los separa por su `catalogSlug` y **fija el tenant por evento**, nunca uno para todo el lote (`REQ-ORG-002` RB-5).

**Happy path**
1. El visitante navega por 4 productos de un catálogo; se acumulan 6 eventos.
2. A los 15 s se envía el lote; el endpoint responde `204` en milisegundos y encola el job.
3. Cierra la pestaña; `sendBeacon` envía los 2 restantes.

**Sad paths y edge cases**
- SP-1 `sessionStorage` bloqueado → el buffer vive en memoria; se aceptan pérdidas en navegación dura.
- SP-2 Endpoint no disponible → un reintento y descarte; la navegación no se afecta.
- SP-3 `sendBeacon` no soportado → `fetch` con `keepalive`; si tampoco, se pierden los últimos eventos.
- SP-4 Rate limit excedido → `429`; el cliente deja de intentar durante 60 s.
- SP-5 Lote de 200 eventos acumulados → se parte en lotes de 50.
- SP-6 Bloqueador que impide la petición → sin efecto sobre la navegación.
- SP-7 Redis caído → el endpoint responde `204` igual y el job se pierde: la telemetría es **best-effort** y nunca degrada la experiencia.
- SP-8 **Lote con eventos de tres organizaciones distintas** (navegación por el feed) → cada evento se atribuye a la suya; ninguno hereda el tenant del anterior (test de RB-9).
- SP-9 Petición con `organization_id` inyectado en el cuerpo → el campo se ignora; solo se acepta `catalogSlug`.

**Fuera de alcance inmediato**
- Tracking desde el servidor.
- Integración con Google Analytics u otro proveedor externo.
- Muestreo probabilístico.

**Gate de validación**
- Superficie esperada: `resources/js/features/telemetry/hooks/useTelemetry.ts`, `resources/js/features/telemetry/lib/buffer.ts`, `app/Http/Controllers/Api/TelemetryController.php`, `routes/api.php`.
- Tipos esperados: `TelemetryEvent`, `useTelemetry`, `TelemetryBuffer`, `IngestBatchData`.
- Anti-`SHALLOW`: `TelemetryBuffer` (acumulación, tres disparadores, respaldo y limpieza) y `TelemetryController::store()` (validación de tamaño, despacho del job, `204`) superan 4 líneas.
- Tests: el buffer dispara a los 10 eventos, a los 15 s y al abandonar; `sendBeacon` al descargar; el endpoint no escribe en BD (job encolado y 0 inserciones); rate limit a la 61.ª; lote > 50 se parte; `sessionStorage` bloqueado no rompe; fallo de red no propaga; **lote multi-organización se atribuye correctamente** (RB-9); `organization_id` del cuerpo ignorado.

---

### REQ-TLM-003 · Persistencia asíncrona y medición de permanencia

**Historia de usuario**
> Como responsable del producto, quiero saber cuánto tiempo pasa la gente en cada producto, para entender qué genera interés real.

**Contratos de datos**
- Job `ProcessTelemetryBatch` en la cola `telemetry` (prioridad más baja).
- Hook `useDwellTime(path, subject)`; evento `dwell_time_recorded` con `payload.seconds`.

**Reglas de negocio y precondiciones**
- RB-1 El job inserta en **bulk** (`insert` masivo, no `create` por fila) tras sanear cada evento y derivar su organización.
- RB-2 La cola `telemetry` tiene la prioridad más baja del worker (`REQ-INF-003`): nunca compite con notificaciones, cobranza ni exportaciones.
- RB-3 Medición de permanencia: heartbeat de **15 s** mientras la página está visible, pausado con `visibilitychange`, y un único evento al abandonar con el total acumulado.
- RB-4 Solo se registra permanencia ≥ 5 s (evita ruido de rebotes) y se acota a 3600 s (pestaña olvidada).
- RB-5 El tiempo se mide **solo con la página visible**: una pestaña en segundo plano no acumula.
- RB-6 El job es tolerante a fallos parciales: un evento inválido no aborta el lote (se descarta y se cuenta).
- RB-7 Sin scroll-depth ni mapas de calor en v1.
- RB-8 La navegación entre páginas de Inertia sin recarga cierra la medición de la anterior y abre una nueva.
- RB-9 El job **fija y limpia el tenant por evento** (`REQ-TLM-002` RB-9), y los eventos sin organización se insertan con `organization_id` nulo.

**Happy path**
1. El visitante pasa 47 segundos en una ficha, 12 de ellos con la pestaña en segundo plano.
2. Al salir se emite `dwell_time_recorded` con `seconds: 35`.
3. El job lo inserta junto con el resto del lote en una sola sentencia.

**Sad paths y edge cases**
- SP-1 Permanencia de 2 s → no se emite.
- SP-2 Pestaña abierta 5 horas → se acota a 3600 s.
- SP-3 Cierre abrupto del navegador → se pierde el evento de permanencia; los ya enviados se conservan.
- SP-4 Un evento corrupto en un lote de 50 → se descarta ese y se insertan 49.
- SP-5 Cola con retraso de horas → los eventos se insertan con su `occurred_at` original, no con el de inserción.
- SP-6 Lote duplicado por reintento del job → se aceptan duplicados: la telemetría no exige *exactly-once*, y el análisis futuro deberá deduplicar por (`visitor_id`, `event_name`, `occurred_at`).
- SP-7 Navegación Inertia dentro del mismo catálogo → una medición por página (RB-8).
- SP-8 Evento de una organización eliminada lógicamente → se inserta igual; el histórico no se pierde por el estado de la organización.

**Fuera de alcance inmediato**
- Scroll-depth, mapas de calor y grabación de sesión.
- Deduplicación exacta en la ingesta.
- Agregados precalculados en tiempo real.

**Gate de validación**
- Superficie esperada: `app/Jobs/ProcessTelemetryBatch.php`, `resources/js/features/telemetry/hooks/useDwellTime.ts`, `app/Domain/Telemetry/EventSanitizer.php`.
- Tipos esperados: `ProcessTelemetryBatch`, `useDwellTime`, `SanitizedEvent`.
- Anti-`SHALLOW`: `ProcessTelemetryBatch::handle()` (saneamiento por evento, derivación de organización, tolerancia a fallos parciales, inserción masiva, tenant por evento) y `useDwellTime` (heartbeat, visibilidad, acotado, emisión al abandonar) superan 4 líneas.
- Tests: inserción en bulk (una sentencia); evento corrupto no aborta el lote; permanencia < 5 s no emite; acotado a 3600 s; segundo plano no acumula; navegación Inertia cierra y abre; `occurred_at` original preservado; **el tenant se limpia entre eventos del mismo lote** (fuga).

---

### REQ-TLM-004 · Identidad del visitante y vinculación retroactiva

**Historia de usuario**
> Como responsable del producto, quiero relacionar lo que alguien miró antes de identificarse con lo que hizo después, para entender el recorrido completo hasta el apartado.

**Contratos de datos**
- Cookie `visitor_id` (UUIDv7, first-party, 1 año, `SameSite=Lax`).
- Campos `telemetry_events.visitor_id`, `account_id`, `customer_id`.
- Jobs `LinkVisitorEventsToCustomer` y `LinkVisitorEventsToAccount`.

**Reglas de negocio y precondiciones**
- RB-1 El `visitor_id` se genera en el **servidor** en la primera visita y se envía como cookie first-party; el cliente lo lee y lo adjunta a cada evento.
- RB-2 El `visitor_id` **sobrevive** al inicio y al cierre de sesión de cualquiera de los cuatro guards: identifica el dispositivo, no a la persona.
- RB-3 `session_id` es un identificador de sesión de navegación (renovado a los 30 min de inactividad), independiente de la sesión de Laravel.
- RB-4 **Dos vinculaciones distintas**, y esta es la consecuencia directa de las dos identidades:
  - al **apartar** o abrir sesión acotada, se rellena `customer_id` en los eventos previos de ese `visitor_id` **que pertenezcan a esa organización**;
  - al **crear o iniciar sesión de cuenta**, se rellena `account_id` en los eventos previos de ese `visitor_id`, **de cualquier organización**.
- RB-5 La primera vinculación está **acotada por organización**: los eventos del visitante en el catálogo de otro negocio **no** se atribuyen a la ficha de este. Sin esa acotación, una organización podría inferir la actividad de su cliente en otro negocio (`REQ-ACC-007` RB-4).
- RB-6 Ambas vinculaciones se limitan a los **últimos 90 días**, para acotar el coste de la actualización.
- RB-7 Es la única excepción a la regla append-only junto con la purga (`REQ-TLM-001` RB-7), y se hace por lotes con `chunkById`.
- RB-8 Un `customer_id` puede tener varios `visitor_id` (varios dispositivos), y un `visitor_id` puede acabar vinculado a fichas de **varias** organizaciones y a una `Account`.

**Happy path**
1. Un visitante nuevo recibe su `visitor_id` y navega 12 productos de un catálogo de forma anónima.
2. Aparta uno; se crea su ficha y el job rellena `customer_id` en esos 12 eventos **de esa organización**.
3. Días después entra con Google; el job rellena `account_id` en todos sus eventos de los últimos 90 días, incluidos los de otros catálogos.

**Sad paths y edge cases**
- SP-1 Cookies bloqueadas → el `visitor_id` se regenera en cada petición; los eventos quedan atomizados y sin recorrido. Se acepta.
- SP-2 **Dos personas en el mismo navegador** → la atribución sigue la sesión activa en el momento de cada evento; el histórico anónimo previo se vincula a la primera que se identifique. Es una imprecisión conocida y documentada.
- SP-3 Eventos de más de 90 días → no se vinculan.
- SP-4 Job de vinculación que falla → los eventos quedan sin `customer_id`; el recorrido anónimo sigue analizable por `visitor_id`.
- SP-5 Cliente que borra cookies y vuelve → nuevo `visitor_id`; los dos recorridos no se unen.
- SP-6 Fusión de fichas (`REQ-IDN-006`) → los eventos se trasladan a la superviviente.
- SP-7 **Visitante que navegó catálogos de tres negocios y luego aparta en uno** → solo los eventos de ese negocio reciben `customer_id`; los otros dos quedan sin él (test explícito de RB-5).
- SP-8 Vinculación de `account_id` sobre eventos de una organización → el negocio **no** ve ese campo (`REQ-TLM-001` RB-8).

**Fuera de alcance inmediato**
- Identity graph entre dispositivos.
- Fingerprinting o identificación sin cookies.
- Atribución de campañas (UTM).

**Gate de validación**
- Superficie esperada: `app/Http/Middleware/AssignVisitorId.php`, `app/Jobs/LinkVisitorEventsToCustomer.php`, `app/Jobs/LinkVisitorEventsToAccount.php`, `resources/js/features/telemetry/lib/visitor.ts`.
- Tipos esperados: `AssignVisitorId`, `LinkVisitorEventsToCustomer`, `LinkVisitorEventsToAccount`.
- Anti-`SHALLOW`: `AssignVisitorId::handle()` (generación, atributos de cookie, persistencia entre sesiones) y los dos jobs de vinculación (ventana de 90 días, acotación por organización en uno y no en el otro, lotes) superan 4 líneas.
- Tests: cookie creada con los atributos exactos; sobrevive a login y logout de los cuatro guards; **la vinculación a `customer_id` está acotada por organización y la de `account_id` no** (test de RB-4 y RB-5); eventos de más de 90 días no se vinculan; fusión traslada eventos; cookies bloqueadas no rompen la navegación.

---

### REQ-TLM-005 · Privacidad, consentimiento, retención y analítica del negocio

**Historia de usuario**
> Como visitante, quiero que se me pida permiso antes de medir mi comportamiento y que mis datos no se guarden para siempre. Y como negocio, quiero ver qué tan bien funciona mi catálogo.

**Contratos de datos**
- Banner `<CookieConsent />`; cookie `cookie_consent` (1 año) con valores `essential` \| `all`.
- Campo `telemetry_events.ip_hash`; entidad `TelemetryMonthlyAggregate`.
- Comando programado `telemetry:purge`; ajuste `platform_settings.telemetry_retention_days_default` y retención efectiva por plan.
- Ruta `GET /backoffice/analitica`; `CatalogAnalyticsQuery`.

**Reglas de negocio y precondiciones**
- RB-1 La IP **no** se almacena en claro: solo `sha256(ip + sal derivada)`. El hash permite detectar abuso sin identificar a la persona.
- RB-2 El banner se muestra en la primera visita y bloquea la emisión de eventos **no esenciales** hasta que se acepta. No bloquea la navegación.
- RB-3 Eventos **esenciales** (se registran siempre, por interés legítimo al ser parte de la operación del servicio): `layaway_created`, `receipt_uploaded`, `account_created`, `catalog_subscribed` y `catalog_unsubscribed`. Los tres últimos se añaden con el pivote: son **acciones deliberadas de la persona**, no observación pasiva de su comportamiento, y sin registrarlas el sistema no podría funcionar.
- RB-4 Rechazar el consentimiento es tan fácil como aceptarlo: dos botones equivalentes, sin patrón oscuro.
- RB-5 La preferencia se puede cambiar desde el pie de página.
- RB-6 **La retención depende del plan de la organización**: 30 días en Free, 180 en Pro, 365 en Business. Los eventos **sin organización** (feed y cuenta) usan el default de plataforma.
- RB-7 Antes de borrar, el comando **preserva agregados mensuales** en `telemetry_monthly_aggregates` (`organization_id?`, `event_name`, `year_month`, `count`), de modo que la serie histórica no se pierde nunca aunque el detalle sí.
- RB-8 **Analítica del negocio** (`full_analytics` en Pro y Business; en Free solo conteos básicos): visitas por catálogo, productos más vistos, búsquedas sin resultados, embudo de `catalog_viewed` → `product_viewed` → `layaway_form_started` → `layaway_created`, y evolución de suscriptores. Todo **agregado**: nunca una lista de personas ni de sus recorridos.
- RB-9 La analítica del negocio **jamás** incluye `account_id`, ni actividad de sus clientes en otras organizaciones, ni datos de suscriptores más allá de lo permitido en `REQ-ACC-007`.
- RB-10 Purga diaria por lotes acotados (máximo 100.000 filas por ejecución), idempotente.
- RB-11 El `user_agent` se guarda en crudo, se documenta como dato de bajo riesgo y se excluye de toda exportación y de la analítica.
- RB-12 La telemetría **no se comparte con terceros** ni sale del sistema.

**Happy path**
1. El visitante entra por primera vez a un catálogo y ve el banner con "Aceptar todo" y "Solo lo esencial".
2. Acepta todo; el buffer completo se habilita.
3. El negocio (plan Pro) ve en su analítica que su catálogo tuvo 340 visitas, con un embudo de 340 → 180 → 42 → 12.
4. A los 180 días, la purga borra el detalle tras acumular los agregados del mes.

**Sad paths y edge cases**
- SP-1 Visitante que elige "solo lo esencial" → solo se emiten los cinco esenciales si llega a apartar o a suscribirse.
- SP-2 Visitante que ignora el banner → se comporta como "solo lo esencial" hasta que decida; el banner permanece discreto.
- SP-3 Cambio de "todo" a "esencial" → los eventos ya registrados **no** se borran (se documenta en la política); solo dejan de emitirse nuevos.
- SP-4 Purga interrumpida → idempotente, continúa en la ejecución siguiente.
- SP-5 Tabla con decenas de millones de filas → borrado por lotes acotado; se recomienda partición por fecha como extensión documentada.
- SP-6 **Bajada de plan que reduce la retención de 365 a 30 días** → la purga siguiente borra el detalle antiguo. Esto **sí** es una pérdida de datos por degradación, y es la única excepción a `REQ-SUB-008` RB-1: se avisa explícitamente en la previsualización del cambio de plan (`REQ-SUB-005` RB-5), y los agregados mensuales se conservan siempre.
- SP-7 Solicitud de un cliente de borrar sus datos → no hay flujo automatizado en v1; operación manual documentada.
- SP-8 Plan Free consultando la analítica completa → ve los conteos básicos y la propuesta de subir de plan.
- SP-9 Analítica de un catálogo despublicado → sigue disponible: sirve para evaluar la campaña a posteriori.

**Fuera de alcance inmediato**
- Portal de consentimiento granular por finalidad.
- Derecho al olvido y portabilidad automatizados.
- Partición de la tabla por fecha.
- Anonimización retroactiva.
- Analítica en tiempo real.

**Gate de validación**
- Superficie esperada: `app/Support/IpHasher.php`, `app/Console/Commands/PurgeTelemetry.php`, `app/Models/TelemetryMonthlyAggregate.php`, `app/Queries/CatalogAnalyticsQuery.php`, `app/Http/Controllers/Backoffice/AnalyticsController.php`, `resources/js/features/telemetry/components/CookieConsent.tsx`, `resources/js/features/telemetry/hooks/useConsent.ts`.
- Tipos esperados: `IpHasher`, `PurgeTelemetry`, `TelemetryMonthlyAggregate`, `CatalogAnalyticsQuery`, `ConsentLevel`.
- Anti-`SHALLOW`: `PurgeTelemetry::handle()` (retención por plan, agregación previa, borrado por lotes, idempotencia) y `CatalogAnalyticsQuery::forOrganization()` (embudo, agregados, acotación al tenant) superan 4 líneas junto a sus tipos.
- Tests: la IP nunca se persiste en claro (assert sobre la fila); no esenciales bloqueados sin consentimiento y **los cinco esenciales sí se registran**; purga con retención distinta por plan (test de RB-6); agregados preservados antes del borrado; cambio de consentimiento no borra lo existente; límite de 100.000 filas; **la analítica no expone `account_id` ni actividad en otras organizaciones** (test de RB-9); Free ve solo conteos; el aviso de pérdida de retención aparece al bajar de plan (SP-6).
