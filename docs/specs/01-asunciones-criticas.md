# PASO 1 — Asunciones Críticas a Validar (Spec Clarifier)

> **Estado:** ⏳ esperando retroalimentación. La especificación (PASO 2) **no se compila** hasta que este documento quede resuelto.
> **Fecha:** 2026-09-10 · **Autor:** Staff Engineer / Arquitecto SDD

## Cómo responder

Cada ítem tiene un **default propuesto** (mi recomendación como arquitecto). Puedes responder de forma masiva:

- **"OK a todo"** → se adoptan todos los defaults y paso directo a compilar la spec.
- **"OK salvo ASU-FX-03, ASU-PAY-05 → <tu decisión>"** → se adoptan los defaults excepto los que corrijas.
- Los ítems marcados `[BLOQUEANTE]` cambian el modelo de datos o la topología de infraestructura; si prefieres, revisa **solo esos 12** y deja el resto en default.

Leyenda: `[BLOQUEANTE]` altera esquema/arquitectura · `[NEGOCIO]` decisión de reglas de negocio · `[TÉCNICO]` decisión de implementación reversible.

---

## Bloque 0 — Decisiones bloqueantes (resumen ejecutivo)

Si solo puedes revisar una parte, revisa estas:

| # | Decisión | Default propuesto |
|---|----------|-------------------|
| ASU-IDN-01 | Clave natural del cliente | Cédula (`document_number`) normalizada y única |
| ASU-IDN-02 | Separación `users` (backoffice) vs `customers` (storefront) | Dos tablas, dos guards independientes |
| ASU-FX-04 | Moneda de la deuda y de la amortización | Deuda **siempre en USD**; el pago en Bs se convierte con la tasa del día del pago (snapshot inmutable) |
| ASU-FX-06 | Representación monetaria en BD | Enteros de centavos (`BIGINT`), nunca `FLOAT` |
| ASU-LAY-01 | Control de inventario / reserva de stock | Sí: `stock` por producto + reserva efectiva al activar el apartado |
| ASU-LAY-03 | Snapshot de condiciones comerciales | Sí: el apartado congela precio, inicial, nº de cuotas y periodicidad |
| ASU-LAY-05 | Generación del cronograma | Cuotas materializadas en tabla `installments` al activar el apartado |
| ASU-PAY-02 | Storage de comprobantes | Disco privado local (volumen Docker) tras un driver configurable S3-ready |
| ASU-CHT-01 | Transporte del chat | **Laravel Reverb** (WebSocket self-hosted) como servicio Docker detrás de Traefik |
| ASU-TLM-01 | Almacén de telemetría | MySQL `telemetry_events` (columna JSON) con ingesta diferida por cola |
| ASU-INF-01 | Traefik | Instancia **externa compartida** en red `traefik_proxy`; el compose del proyecto solo publica `labels` |
| ASU-QAX-05 | Idioma de las specs vs. del código | Narrativa en español; **identificadores, tipos y campos en inglés** + `requirements.md` espejo en inglés para `spec align` |

---

## Bloque A — Identidad, clientes y autenticación (`IDN`)

**ASU-IDN-01 · Clave natural e identidad del cliente** `[BLOQUEANTE]`
- Duda: el apartado no exige registro, pero el portal exige identidad persistente. ¿Qué campo identifica unívocamente a una persona?
- Default: `customers.document_number` (cédula) normalizada a `V12345678` / `E12345678`, **único**. Un segundo apartado con la misma cédula reutiliza el registro existente y actualiza teléfono/email si cambian (con log de auditoría).
- Impacto si cambia: si la clave fuese el email, dos apartados de la misma persona con emails distintos generarían balances separados.

**ASU-IDN-02 · Dos dominios de autenticación** `[BLOQUEANTE]`
- Duda: ¿administradores y clientes viven en la misma tabla?
- Default: tabla `users` (backoffice, guard `web`) y tabla `customers` (storefront, guard `customer`) totalmente separadas. Sin herencia ni relación polimórfica.
- Impacto: define middlewares, policies y el árbol de rutas completo.

**ASU-IDN-03 · Vinculación de la cuenta Google (OAuth)** `[NEGOCIO]`
- Duda: el cliente puede apartar anónimo y después autenticarse con Google usando un email distinto al que declaró.
- Default: tabla `customer_identities` (`provider`, `provider_id`, `email`) N:1 con `customers`. La conciliación se hace **por cédula durante el apartado**; si el email de Google no corresponde a ninguna cédula conocida, se crea un cliente nuevo y el backoffice dispone de una acción manual "fusionar clientes".
- Alternativa descartada: fusión automática por email (riesgo de colisión de identidad).

**ASU-IDN-04 · Acceso al portal sin OAuth ("token seguro")** `[BLOQUEANTE]`
- Duda: mecanismo exacto del acceso sin contraseña.
- Default: **magic link** (URL firmada de Laravel) enviado al email declarado, TTL **30 minutos**, **un solo uso**; al canjearse abre sesión `customer` con recuerdo de **30 días**. La solicitud del link exige cédula + email coincidentes con el registro, con rate limit de 3 intentos / 15 min por IP+cédula.
- Nota: el email nunca incluye datos del balance, solo el enlace.

**ASU-IDN-05 · Contraseñas para clientes** `[TÉCNICO]`
- Default: **no existen**. El storefront es passwordless (Google OAuth + magic link). `customers` no tiene columna `password`.

**ASU-IDN-06 · Verificación de teléfono (OTP/SMS/WhatsApp)** `[NEGOCIO]`
- Default: **fuera de alcance** en esta iteración. El teléfono se valida solo por formato (`+58` + operadora móvil venezolana o fijo).

**ASU-IDN-07 · Validación de cédula** `[TÉCNICO]`
- Default: regla `V|E|J|G` + 6 a 9 dígitos, sin validación contra registro externo (CNE/SENIAT). Se almacena normalizada (sin puntos ni guiones) y se muestra formateada.

**ASU-IDN-08 · Consentimiento y datos personales** `[NEGOCIO]`
- Default: checkbox obligatorio de aceptación de términos y política de privacidad en el formulario de apartado, con `terms_accepted_at` e `ip_address` persistidos como evidencia. Banner de cookies **sí** (condiciona el Bloque `TLM`), sin bloquear la navegación.

---

## Bloque B — Catálogo, productos y catálogos curados (`CAT`)

**ASU-CAT-01 · Categorías vs. etiquetas** `[BLOQUEANTE]`
- Duda: el storefront filtra por "categorías **y** etiquetas", pero el CRUD del backoffice solo menciona etiquetas.
- Default: **ambas entidades existen**. `categories` (plana, **no** jerárquica, 1:N — un producto pertenece a **una** categoría, obligatoria) y `tags` (N:M, opcionales, múltiples). El CRUD de producto gestiona las dos.
- Impacto si cambia: si la categoría debe ser un árbol jerárquico, cambian el esquema, el filtro y la navegación.

**ASU-CAT-02 · Imágenes de producto** `[BLOQUEANTE]`
- Duda: no se mencionan explícitamente, pero un storefront orientado a conversión las requiere.
- Default: galería obligatoria — `product_images` (1..N, con `position` y una principal), conversiones WebP en 3 tamaños (thumb 400 / card 800 / zoom 1600) generadas **en cola**. Librería: **`spatie/laravel-medialibrary`**.
- Alternativa: subida simple sin conversiones (más rápido de construir, peor rendimiento móvil).

**ASU-CAT-03 · Ficha técnica dinámica: modelo de datos** `[BLOQUEANTE]`
- Default: tabla dedicada `product_specifications` (`product_id`, `group` nullable, `label`, `value`, `position`) en lugar de una columna JSON. Permite orden estable, agrupación visual ("Dimensiones", "Motor") y futura búsqueda por atributo.
- Alternativa descartada: `JSON` en `products` (más simple, imposible filtrar/ordenar sin coste).

**ASU-CAT-04 · Ciclo de vida y visibilidad del producto** `[NEGOCIO]`
- Default: estados `draft` / `published` / `archived` + `published_at`. `SoftDeletes` en `products` (un producto con apartados históricos nunca se borra en duro). Slug único autogenerado y editable.

**ASU-CAT-05 · Precio de comparación y descuentos** `[NEGOCIO]`
- Default: campo opcional `compare_at_price_cents` (precio tachado, solo presentación). **Sin** motor de cupones ni promociones en esta iteración.

**ASU-CAT-06 · Búsqueda de productos** `[TÉCNICO]`
- Default: índice `FULLTEXT` de MySQL 8 sobre `name` + `description` + `sku`, con fallback `LIKE`. **Sin** Meilisearch/Scout en v1 (queda documentado como punto de extensión).

**ASU-CAT-07 · Catálogos curados: visibilidad del enlace** `[NEGOCIO]`
- Duda: el requerimiento pide a la vez "sección pública para explorar catálogos" y "enlace único compartible".
- Default: `catalogs` con `slug` único, `is_listed` (aparece o no en la sección pública), `published_at` y `expires_at` **nullable**. Un catálogo no listado sigue siendo accesible por URL directa (unlisted, no secreto). Sin contraseña de acceso.

**ASU-CAT-08 · Relación catálogo ↔ producto** `[TÉCNICO]`
- Default: N:M (`catalog_product`) con `position` para el orden curado manual. Un producto puede vivir en varios catálogos simultáneamente.

**ASU-CAT-09 · Paginación y filtros del storefront** `[TÉCNICO]`
- Default: paginación por cursor con botón "Cargar más" (mobile-first), 12 productos por lote, y estado de filtros en la URL para que sea compartible (`?category=&tags[]=&sort=`). Los filtros viajan por Inertia partial reloads, no por `react-query`.

---

## Bloque C — Bimoneda y tasa de cambio (`FX`)

**ASU-FX-01 · Proveedor de la tasa** `[BLOQUEANTE]`
- Duda: no se especificó el endpoint concreto.
- Default: interfaz `ExchangeRateProvider` con implementaciones intercambiables por config (`services.exchange_rate.driver`). Driver primario: **API pública tipo pyDolarVenezuela** (expone BCV y paralelo en una sola respuesta); driver secundario `ManualRateProvider` (tasa introducida a mano en el backoffice) como red de seguridad.
- **Necesito de ti:** confirmar la URL/proveedor que la operación considera "oficial", o autorizar que arranquemos con pyDolarVe + driver manual.

**ASU-FX-02 · Selección BCV vs. paralelo** `[NEGOCIO]`
- Default: ajuste **global** (`settings.exchange_rate_source` ∈ `bcv|parallel|manual`), editable desde el backoffice. **No** configurable por producto.

**ASU-FX-03 · Frescura y caché** `[TÉCNICO]`
- Default: job programado cada **30 minutos** que consulta y **persiste** la tasa en `exchange_rates` (histórico inmutable: `source`, `rate`, `fetched_at`); Redis cachea la tasa vigente con TTL **35 min**. El frontend nunca llama a la API externa: recibe la tasa por Inertia props y la refresca con `@tanstack/react-query` contra un endpoint interno cada 5 min.

**ASU-FX-04 · Moneda de la deuda y de la amortización** `[BLOQUEANTE]`
- Default: **la deuda es en USD**. Un pago declarado en Bs se convierte a USD con la tasa **vigente el día del pago**, y el registro de pago guarda un snapshot inmutable: `amount_cents_usd`, `declared_currency`, `declared_amount`, `rate_applied`, `rate_source`, `rate_fetched_at`. Cambios posteriores de la tasa **no** recalculan pagos ya conciliados.
- Impacto: es la regla que hace auditable la cobranza; si se decidiera fijar la tasa al momento del apartado, cambia por completo el módulo `PAY`.

**ASU-FX-05 · Tolerancia de redondeo en la última cuota** `[NEGOCIO]`
- Default: el cronograma se calcula en centavos USD y el residuo de la división se suma a la **última** cuota. Un saldo residual ≤ **$0.50** se considera **liquidado** y el apartado pasa a `completed` (evita deudas fantasma de céntimos por redondeo cambiario).

**ASU-FX-06 · Representación monetaria** `[BLOQUEANTE]`
- Default: **`BIGINT` de centavos USD** en todas las columnas monetarias (`*_cents`), con un Value Object `Money` inmutable en PHP y un tipo `Money` (branded number) en TypeScript. La tasa se guarda como `DECIMAL(18,8)`. Prohibido `float`/`double` en cualquier capa.

**ASU-FX-07 · Degradación cuando la API cae** `[NEGOCIO]`
- Default: **nunca se bloquea la navegación ni el apartado**. Se usa la última tasa persistida, la UI muestra el sello "Tasa BCV del DD/MM HH:mm" y, si tiene más de 24 h, un aviso discreto. Si **no existe ninguna** tasa histórica, la UI oculta el monto en Bs y muestra solo USD.

**ASU-FX-08 · Persistencia del toggle de moneda** `[TÉCNICO]`
- Default: preferencia por visitante en `localStorage` + cookie de 1 año para consistencia en el primer render; default global configurable desde el backoffice (arranca en "USD destacado").

---

## Bloque D — Apartado, cronograma y amortización (`LAY`)

**ASU-LAY-01 · Inventario y reserva** `[BLOQUEANTE]`
- Duda: el formulario pide cantidad, pero el CRUD no define stock.
- Default: `products.stock` entero. El stock se **reserva** al activar el apartado (no al enviar el formulario) y se libera si el apartado expira o se cancela. Si el stock llega a 0 el producto se muestra "Agotado" pero permanece visible. Concurrencia resuelta con `SELECT ... FOR UPDATE` dentro de la transacción de creación.
- Alternativa: sin control de stock (más simple, permite sobreventa).

**ASU-LAY-02 · Alcance del apartado** `[NEGOCIO]`
- Default: **1 apartado = 1 producto × N unidades**. Sin carrito multi-producto en esta iteración (cada producto tiene su propio plan de cuotas; mezclarlos complica la amortización).

**ASU-LAY-03 · Snapshot de condiciones comerciales** `[BLOQUEANTE]`
- Default: al crear el apartado se congelan `unit_price_cents`, `down_payment_cents`, `installments_count`, `frequency_days` y el nombre/SKU del producto. Cambiar el precio en el backoffice **no** afecta apartados vigentes.

**ASU-LAY-04 · Cantidad mayor que 1** `[NEGOCIO]`
- Default: precio, cuota inicial y monto total se multiplican por la cantidad; el **número** de cuotas y la frecuencia **no** cambian.

**ASU-LAY-05 · Cronograma materializado** `[BLOQUEANTE]`
- Default: al **activar** el apartado se generan N filas en `installments` (`number`, `due_date`, `amount_cents`, `paid_cents`, `status`). Inmutables salvo acción administrativa explícita de reprogramación (registrada en auditoría). Fecha de la cuota 1 = fecha de aprobación de la inicial + `frequency_days`.

**ASU-LAY-06 · Máquina de estados del apartado** `[NEGOCIO]`
- Default: `pending_down_payment` → `active` → (`overdue` ⇄ `active`) → `completed` | `cancelled` | `expired`.
  - `pending_down_payment`: creado, esperando comprobante de la inicial. Expira a las **72 h** sin comprobante (job) → `expired` y libera stock.
  - `active`: inicial aprobada, cronograma vivo.
  - `overdue`: existe al menos una cuota vencida pasado el período de gracia.
  - `completed`: saldo pendiente ≤ tolerancia (ASU-FX-05).
- Default adicional: el admin **sí** puede registrar un pago manual (efectivo/presencial) sin comprobante subido, con nota obligatoria.
- **Necesito de ti:** confirmar las 72 h de expiración.

**ASU-LAY-07 · Período de gracia y mora** `[NEGOCIO]`
- Default: **3 días** calendario de gracia tras el vencimiento antes de marcar `overdue`. **Sin** intereses, penalizaciones ni recargos por atraso en esta iteración (solo estado + notificaciones). Valor configurable globalmente.

**ASU-LAY-08 · Pagos parciales y aplicación del abono** `[NEGOCIO]`
- Default: se **permiten** abonos parciales. Todo pago aprobado se aplica **FIFO** a la cuota pendiente más antigua y el excedente cae en la siguiente, en cascada. El admin puede reasignar manualmente a una cuota concreta.

**ASU-LAY-09 · Liquidación anticipada** `[NEGOCIO]`
- Default: permitida en cualquier momento por el saldo pendiente total, **sin descuento** por prepago.

**ASU-LAY-10 · Cancelación e incumplimiento** `[NEGOCIO]`
- Default: solo cancelación **manual** por un administrador, con motivo obligatorio. **No** hay cálculo automático de reembolso ni de penalización: se deja constancia del monto abonado y la resolución se maneja fuera del sistema. Sin cancelación automática por mora prolongada (solo alerta en el backoffice a partir de 30 días de mora).

**ASU-LAY-11 · Entrega del producto** `[NEGOCIO]`
- Default: campo `delivered_at` + acción "Marcar como entregado", habilitada solo cuando el apartado está `completed`. Sin logística, sin dirección de envío, sin tracking (se asume retiro en tienda).

**ASU-LAY-12 · Frecuencias soportadas** `[TÉCNICO]`
- Default: `frequency_days` entero libre con presets en UI: 7 (semanal), 15 (quincenal, **default**), 30 (mensual). Las fechas se calculan sumando días calendario; si el vencimiento cae en domingo o feriado **no** se desplaza.

---

## Bloque E — Comprobantes y conciliación (`PAY`)

**ASU-PAY-01 · Datos declarados en el comprobante** `[NEGOCIO]`
- Default: `payment_method`, `bank` (nullable), `reference` (nullable solo para efectivo), `paid_at` (fecha declarada por el cliente), `declared_amount`, `declared_currency` (`USD|VES`), `note` y el archivo adjunto.
- Catálogo de métodos propuesto: `pago_movil`, `transferencia`, `zelle`, `binance_pay`, `efectivo_usd`, `efectivo_ves`.
- **Necesito de ti:** confirmar este catálogo de métodos.

**ASU-PAY-02 · Almacenamiento de archivos** `[BLOQUEANTE]`
- Default: disco **privado** `receipts` sobre volumen Docker persistente, servido exclusivamente por rutas firmadas con autorización por policy. Driver configurable (`FILESYSTEM_DISK`) para migrar a S3/R2 sin cambiar código. El backup del volumen queda fuera del alcance del código.

**ASU-PAY-03 · Restricciones del archivo** `[TÉCNICO]`
- Default: `jpg`, `jpeg`, `png`, `webp`, `pdf`; máximo **5 MB**; validación de MIME real (no por extensión); las imágenes se recomprimen en cola; se calcula y persiste el `sha256` del archivo.

**ASU-PAY-04 · Detección de duplicados** `[NEGOCIO]`
- Default: bloqueo **duro** en validación cuando coinciden (`bank`, `reference`) o cuando el `sha256` del archivo ya existe, con mensaje explícito al cliente ("este comprobante ya fue reportado"). Los duplicados **no** llegan a la bandeja del admin.

**ASU-PAY-05 · Monto conciliado distinto del declarado** `[NEGOCIO]`
- Default: al aprobar, el admin **puede ajustar** el monto realmente recibido y la tasa aplicada; se registran ambos (`declared_*` vs `settled_*`) y el ajuste queda auditado. La aprobación es transaccional: crea el `payment`, amortiza cuotas FIFO y recalcula el estado del apartado en una sola transacción de BD.

**ASU-PAY-06 · Rechazo y reintento** `[NEGOCIO]`
- Default: motivo obligatorio (catálogo + texto libre), notificación al cliente por push + email, y el cliente **puede** subir un comprobante nuevo vinculado al rechazado (`replaces_receipt_id`). Un comprobante rechazado no se borra nunca.

**ASU-PAY-07 · Estados del comprobante** `[TÉCNICO]`
- Default: `pending_review` → `approved` | `rejected`. Sin estado intermedio "en revisión por X"; el bloqueo entre admins se limita a una comprobación de estado al resolver (el segundo admin recibe "ya fue procesado por …").

**ASU-PAY-08 · Alertas en tiempo real de la bandeja** `[TÉCNICO]`
- Default: mismo transporte que el chat (Reverb) — evento broadcast a un canal privado `backoffice.receipts` que incrementa el badge y lanza un toast. Sin polling.

**ASU-PAY-09 · Balance: calculado o materializado** `[TÉCNICO]`
- Default: **materializado** en `layaways` (`total_cents`, `paid_cents`, `remaining_cents`, `next_due_date`, `status`), recalculado dentro de la transacción de cada pago, más un comando `layaways:reconcile` idempotente que verifica consistencia contra la suma de pagos (ejecutable en cron y en tests).

---

## Bloque F — Backoffice, usuarios y exportaciones (`BOF`)

**ASU-BOF-01 · Granularidad de permisos** `[NEGOCIO]`
- Default: sin RBAC, pero con un flag `users.is_super_admin`: **solo** un superadmin puede crear/desactivar administradores y editar ajustes globales (tasa, gracia, plantillas). El resto de acciones son comunes a todos los admins. Se documenta el punto de extensión para `spatie/laravel-permission` sin instalarlo.

**ASU-BOF-02 · Alta de administradores** `[TÉCNICO]`
- Default: el primer superadmin nace por **seeder**, con credenciales tomadas de `.env` y cambio obligatorio en el primer login. Los siguientes admins se crean por invitación (email + link de establecimiento de contraseña, TTL 48 h). Desactivación lógica (`disabled_at`), nunca borrado.

**ASU-BOF-03 · 2FA y hardening del backoffice** `[TÉCNICO]`
- Default: 2FA **fuera de alcance**. Sí incluido: rate limiting de login, bloqueo temporal tras 5 intentos fallidos y ausencia de enlaces al backoffice desde el storefront.

**ASU-BOF-04 · Auditoría** `[NEGOCIO]`
- Default: log de auditoría **sí**, con `spatie/laravel-activitylog`, limitado a eventos sensibles: aprobación/rechazo de comprobantes, cambios de precio, cancelación/reprogramación de apartados, fusión de clientes, cambios de ajustes globales y altas/bajas de admins.

**ASU-BOF-05 · Exportación a Excel** `[TÉCNICO]`
- Default: `maatwebsite/excel` en modo `FromQuery` + chunking, ejecutado en cola. El resultado se guarda en disco privado, se notifica al admin (in-app + email) con **URL firmada de 24 h**, y un job de limpieza purga los archivos a los 7 días. Tabla `export_jobs` para el historial y el estado (`queued|processing|done|failed`).
- Formato: `.xlsx` únicamente. Los filtros activos de la tabla viajan al job serializados.

**ASU-BOF-06 · Filtros operativos de cobranza** `[NEGOCIO]`
- Default: presets nombrados — "Vencidos hoy", "Vencen en 7 días", "En mora >15 días", "Corte quincenal (1–15 / 16–fin)" — más rango de fechas libre y filtro por estado de apartado. Los presets se traducen a scopes de Eloquent reutilizados por la vista **y** por la exportación (una sola fuente de verdad de la query).

**ASU-BOF-07 · Tablas del backoffice** `[TÉCNICO]`
- Default: `@tanstack/react-table` en modo **manual** (paginación, ordenamiento y filtrado resueltos en el servidor vía Inertia partial reloads con `only:`), no client-side. Estado de la tabla sincronizado con la query string.

**ASU-BOF-08 · Dashboard inicial** `[NEGOCIO]`
- Default: incluido y mínimo — comprobantes por revisar, cobrado del mes (USD), cuotas que vencen esta semana, clientes en mora y tasa vigente. Sin gráficas complejas en v1.

---

## Bloque G — Notificaciones (`NOT`)

**ASU-NOT-01 · Web Push: implementación** `[TÉCNICO]`
- Default: `laravel-notification-channels/webpush` con VAPID, Service Worker propio mínimo y tabla `push_subscriptions` por cliente (multi-dispositivo). Solicitud de permiso **diferida** (no al cargar: tras crear el primer apartado, con explicación previa).
- No se construye una PWA instalable completa (sin manifest de instalación, sin modo offline).

**ASU-NOT-02 · Canales activos** `[NEGOCIO]`
- Default: **Web Push + email**. WhatsApp y SMS **fuera de alcance** (documentados como extensión). Email transaccional vía SMTP configurable (`.env`), siempre encolado.

**ASU-NOT-03 · Calendario automático de cobranza** `[NEGOCIO]`
- Default: recordatorio **3 días antes** del vencimiento, el **día** del vencimiento, y a los **+1 / +7 / +15** días de mora. Un comando programado diario, idempotente (tabla `notification_dispatches` evita duplicados por cuota y tipo).
- **Necesito de ti:** confirmar esta cadencia.

**ASU-NOT-04 · Push manual segmentado** `[NEGOCIO]`
- Default: el admin selecciona destinatarios por (a) selección explícita de clientes o (b) el filtro activo del directorio (segmento). Título + cuerpo + URL de destino opcional. Envío en cola con reporte de entregados/fallidos y purga automática de suscripciones caducadas (`410 Gone`).

**ASU-NOT-05 · Idioma y plantillas** `[TÉCNICO]`
- Default: español (`es_VE`) único; plantillas Blade + Markdown mail, con los textos en archivos de traducción para habilitar i18n futura sin refactor.

---

## Bloque H — Chat cliente ↔ soporte (`CHT`)

**ASU-CHT-01 · Transporte** `[BLOQUEANTE]`
- Default: **Laravel Reverb** como servicio propio en `docker-compose`, expuesto por Traefik (`wss://ws.apartar.store`), con `laravel-echo` en el cliente. Fallback automático a polling cada 10 s (`@tanstack/react-query`) si el WebSocket no conecta.
- Alternativas: Pusher gestionado (menos infra, coste recurrente y dependencia externa) o long-polling puro (más simple, peor UX y más carga).

**ASU-CHT-02 · Modelo de conversación** `[NEGOCIO]`
- Default: **un hilo único y permanente por cliente** (`conversations` 1:1 con `customers`), no un hilo por apartado ni por ticket. Todos los admins ven todos los hilos; **sin** asignación de agente ni SLA.

**ASU-CHT-03 · Requisito de identidad para chatear** `[NEGOCIO]`
- Default: el chat requiere sesión de cliente (OAuth o magic link). Un visitante anónimo ve el widget con un CTA "identifícate para escribirnos". Sin chat pre-venta anónimo en v1.

**ASU-CHT-04 · Capacidades del mensaje** `[TÉCNICO]`
- Default: texto plano (máx. 2000 caracteres), marca de leído (`read_at`) e indicador "escribiendo…". **Sin** adjuntos, sin selector de emojis, sin edición ni borrado. Historial completo persistido sin purga.

**ASU-CHT-05 · Notificación de mensaje offline** `[NEGOCIO]`
- Default: si el cliente no está conectado, un mensaje del admin dispara Web Push tras **2 minutos** sin lectura (job diferido). Los mensajes de cliente sin leer alimentan el badge del backoffice.

---

## Bloque I — Telemetría (`TLM`)

**ASU-TLM-01 · Almacén** `[BLOQUEANTE]`
- Default: tabla MySQL `telemetry_events` (`event_name`, `visitor_id`, `customer_id` nullable, `subject_type/subject_id` nullable, `payload` JSON, `occurred_at`, `session_id`, `ip_hash`, `user_agent`), con índices por `event_name+occurred_at` y `customer_id`. Ingesta: el frontend acumula eventos en un buffer y hace `POST` por lotes; el endpoint solo despacha un job a Redis que inserta en bulk. Nunca escritura sincrónica en el request de navegación.
- Alternativa descartada por ahora: ClickHouse u otro almacén analítico dedicado (sobredimensionado para el volumen inicial; se documenta el camino de migración).

**ASU-TLM-02 · Catálogo de eventos** `[NEGOCIO]`
- Default cerrado en v1: `product_viewed`, `product_list_filtered`, `search_performed`, `catalog_viewed`, `layaway_form_started`, `layaway_created`, `receipt_uploaded`, `currency_toggled`, `dwell_time_recorded`, `cta_clicked`. Añadir eventos nuevos exige entrada en la spec (evita un almacén de eventos basura).

**ASU-TLM-03 · Tiempo de permanencia** `[TÉCNICO]`
- Default: medición por página con `visibilitychange` + heartbeat de **15 s**, enviada como un único evento `dwell_time_recorded` al abandonar. Sin scroll-depth en v1.

**ASU-TLM-04 · Identidad del visitante y privacidad** `[NEGOCIO]`
- Default: cookie propia `visitor_id` (UUIDv7, 1 año, first-party). La IP **no** se almacena en claro: solo `sha256(ip + salt)`. Al identificarse el cliente, los eventos previos del `visitor_id` se vinculan retroactivamente por `customer_id` (job). La telemetría no esencial solo se recoge tras aceptar el banner de cookies.

**ASU-TLM-05 · Retención y uso futuro** `[TÉCNICO]`
- Default: purga automática a los **365 días** por comando programado, preservando agregados mensuales. **Sin** motor de recomendación en esta iteración: solo se garantiza que los datos queden bien modelados para construirlo después.

---

## Bloque J — Infraestructura y entorno (`INF`)

**ASU-INF-01 · Topología de Traefik** `[BLOQUEANTE]`
- Default: Traefik corre como **stack externo compartido** en la red Docker `traefik_proxy`; el `docker-compose.yml` del proyecto **no** define el servicio Traefik, solo declara la red externa y las `labels` de routing/TLS. Certificados Let's Encrypt con **HTTP-01 challenge**.
- Alternativa: incluir Traefik en el compose del proyecto (autocontenido, pero colisiona con los puertos 80/443 si el servidor aloja otros proyectos).
- **Necesito de ti:** confirmar si el servidor de producción ya tiene un Traefik operativo y el nombre exacto de su red.

**ASU-INF-02 · Servicios del compose** `[TÉCNICO]`
- Default: `app` (PHP-FPM 8.3), `nginx`, `mysql:8.0`, `redis:7`, `queue` (worker + `schedule:work`), `reverb`, y `node` solo en desarrollo (Vite HMR). Un único worker con colas priorizadas (`high,default,exports,telemetry`), `--max-time=3600` y reinicio automático.

**ASU-INF-03 · Entornos** `[TÉCNICO]`
- Default: `local` (`apartar.localhost` vía Traefik sin TLS, HMR de Vite expuesto) y `production` (`apartar.store` + `www` redirigido + `ws.apartar.store`). **Sin** entorno de staging en esta iteración.

**ASU-INF-04 · Drivers de Laravel** `[TÉCNICO]`
- Default: `cache=redis`, `session=redis`, `queue=redis`, `broadcast=reverb`, `filesystem=local` (privado). Zona horaria `America/Caracas`, locale `es`, moneda base USD.

**ASU-INF-05 · Versiones y starter kit** `[TÉCNICO]`
- Duda: el prompt indica "Laravel 11+" y "React 18+".
- Default: **Laravel 12** con el starter kit oficial `react` (Inertia 2 + React 19 + TypeScript + Vite + Tailwind 4). PHP 8.3+. daisyUI 5 sobre Tailwind 4 como sistema de componentes.
- **Necesito de ti:** confirmar Laravel 12 + React 19, o fijar Laravel 11 + React 18 exactos.

**ASU-INF-06 · Deploy** `[TÉCNICO]`
- Default: imagen multi-stage y despliegue manual con `docker compose up -d`, `php artisan migrate --force` y `optimize`. **Sin** pipeline de CD automático en esta iteración (el CI de calidad sí existe, ver `QAX`).

---

## Bloque K — Calidad, testing y gates SDD (`QAX`)

**ASU-QAX-01 · Testing backend** `[TÉCNICO]`
- Default: **Pest 3**. Cobertura obligatoria por tests unitarios de las reglas de dominio (cronograma, amortización FIFO, conversión FX, máquina de estados, duplicados); tests de feature Inertia para cada endpoint; factories para todas las entidades. Umbral: **≥85 % en el directorio de dominio/acciones**, sin umbral global.

**ASU-QAX-02 · Testing frontend** `[TÉCNICO]`
- Default: **Vitest + React Testing Library** para hooks y componentes con lógica (formateo bimoneda, formularios dinámicos, tablas). E2E (Playwright) **fuera de alcance** en esta iteración.

**ASU-QAX-03 · Análisis estático** `[TÉCNICO]`
- Default: Pint (preset Laravel), **Larastan nivel 6** con objetivo declarado de subir a 8, ESLint + Prettier, y `tsc --noEmit` con `strict: true` + `noUncheckedIndexedAccess`. Todo obligatorio en CI (GitHub Actions); pre-commit opcional.

**ASU-QAX-04 · Herramienta `indexion`** `[BLOQUEANTE]`
- Hallazgo verificado: **`indexion` no está instalado en este entorno**, por lo que los gates de `spec align` no pueden ejecutarse hoy.
- Default: la spec declara los gates de forma normativa y ejecutable (comandos exactos por módulo) y la instalación de `indexion` se añade como tarea del PASO 4; mientras no exista, los gates se verifican de forma cualitativa por revisión.
- **Necesito de ti:** ¿instalamos `indexion` en desarrollo/CI o dejamos los gates documentados pero inactivos?

**ASU-QAX-05 · Idioma de la especificación vs. del código** `[BLOQUEANTE]`
- Hallazgo: el skill `indexion-sdd` advierte que el idioma de la spec debe coincidir con el del código, porque `spec align` compara vocabulario entre la spec y los identificadores del código; una spec en español contra código en inglés produce falsos `DRIFTED`.
- Default: **narrativa en español** (historias de usuario y reglas de negocio, para lectura del negocio) + **todos los identificadores, entidades, campos, tipos y contratos en inglés**, y generación de un espejo `docs/specs/.align/requirements.en.md` que es el artefacto que consumen los gates.
- Alternativa: spec íntegramente en inglés (óptimo para las herramientas, menos legible para el negocio).

**ASU-QAX-06 · Ubicación canónica de las specs** `[TÉCNICO]`
- Duda: el prompt original pide `.sdd/specs/`; tú pediste `docs/specs/`.
- Default aplicado: **`docs/specs/` es la fuente de verdad** (versionada, numerada, legible). Se genera además `.kiro/specs/<feature>/requirements.md` como artefacto derivado exclusivamente para las herramientas SDD, marcado como generado y no editable a mano.

**ASU-QAX-07 · Fuera de alcance global** `[NEGOCIO]`
- Default excluido de esta iteración, para confirmarlo de una vez: pasarela de pago automática, facturación fiscal/IVA, envíos y logística, cupones y promociones, multi-idioma, multi-tienda, motor de recomendación activo, app móvil nativa, intereses por mora, RBAC granular, 2FA, WhatsApp/SMS, staging y E2E automatizado.

---

## Preguntas que requieren tu respuesta explícita (sin default seguro)

1. **ASU-FX-01** — ¿Cuál es el proveedor/endpoint de tasa que la operación acepta como oficial? (o autorizas arrancar con pyDolarVe + tasa manual de respaldo)
2. **ASU-INF-01** — ¿Existe ya un Traefik en el servidor de producción y cuál es el nombre de su red Docker externa?
3. **ASU-INF-05** — ¿Laravel 12 + React 19 (recomendado) o fijamos 11 + 18?
4. **ASU-QAX-04** — ¿Instalamos `indexion` para activar los gates cuantitativos?
5. **ASU-QAX-05** — ¿Aceptas el esquema bilingüe (narrativa ES / identificadores EN) o prefieres spec 100 % en inglés?
6. **ASU-PAY-01** — ¿Es correcto el catálogo de métodos de pago propuesto?
7. **ASU-LAY-06** — ¿72 h de expiración del apartado sin comprobante de la inicial?
8. **ASU-NOT-03** — ¿Confirmas la cadencia de recordatorios (−3 d, día 0, +1, +7, +15)?
