# PASO 3 — Diseño técnico

> **Estado:** ✅ Listo · **Fecha:** 2026-09-10 · **Sin código todavía.**
> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Consume las 118 especificaciones de los 19 módulos.
> Siguiente: [06-plan-de-tareas.md](06-plan-de-tareas.md).

## 0. Qué contiene este documento y qué no

La spec ya declara, para cada uno de los 118 requisitos, sus contratos de datos y su superficie de código esperada. Repetirlo aquí sería duplicar 9.000 líneas. Este documento contiene **solo las cinco cosas que no viven en ningún módulo** porque son vistas transversales:

| § | Contenido | Por qué no puede vivir en un módulo |
|---|---|---|
| 3 | Stack y paquetes con versión exacta | Es una decisión global |
| 4 | **Set completo de migraciones en orden de dependencias** | 36 tablas donde el orden importa; ningún módulo ve el grafo completo |
| 5 | **Los siete colaboradores transversales con firma definitiva** | Aparecen en varios módulos; una firma mal puesta se paga en todos |
| 6 | Contrato del proveedor de tasa (DolarApi) | Detalle de integración externa |
| 7 | **Tabla de rutas completa** | `REQ-QAX-008` deriva sus tests **de la tabla de rutas**: sin ella no hay gate de aislamiento |
| 8 | Árbol de archivos definitivo | Vista global |
| 9 | Entorno local | Decisión de esta iteración |
| 10 | **Orden de construcción e hitos** | Lo que determina qué es construible primero |

---

## 1. Decisiones cerradas en esta iteración

| Decisión | Resolución | Consecuencia |
|---|---|---|
| **`indexion`** | **Descartado** | Los gates pasan a ser Pest + Vitest + PHPStan + `tenancy:audit` + revisión de PR ([02 §8](02-spec-consolidada.md)). `REQ-QAX-005` y `REQ-QAX-006` reescritos. Se pierde la métrica automática de *drift* documental; se gana que todos los gates son ejecutables desde el primer commit |
| **Entorno** | **Solo local** | `REQ-INF-002` (Traefik, dominios, TLS) y la parte de producción de `REQ-INF-006` quedan **diferidas** y fuera del plan de tareas. Sin dominio, sin TLS, sin proxy |
| **Tasa de cambio** | **DolarApi** (`ve.dolarapi.com`) | `DolarApiProvider` sustituye a `PyDolarVeProvider`. Una sola petición trae oficial y paralelo (§6) |

La decisión de entorno local es más barata de lo que parece precisamente por `Q4`: al ser las URL públicas **por ruta** (`/c/{slug}`) y no por subdominio, el desarrollo local no necesita DNS comodín ni proxy inverso. Basta `http://localhost:8080`.

---

## 2. Modelo mental del sistema en cinco frases

Antes del detalle, la forma del sistema, porque todo lo demás se deduce de aquí:

1. **Una `Organization` es el tenant.** Casi toda tabla lleva `organization_id` y un *global scope* que la filtra. Tres tablas rompen la regla a propósito: `users.email`, `catalogs.slug` y `layaways.code` son únicas globalmente porque son, respectivamente, la clave de login sin selector de organización, la URL pública y la prueba de acceso al portal.
2. **El `Catalog` es la puerta pública.** De su `slug` se deriva el tenant en toda la superficie del cliente final. No hay descubrimiento global.
3. **El cliente final tiene dos identidades**: `Customer` (comercial, por organización, clave = documento) y `Account` (consumidor, global, clave = Google). El puente es `customers.account_id`, y solo se cruza con documento + código de apartado.
4. **La deuda es en USD.** La conversión a VES se congela por pago y nunca se recalcula.
5. **El plan limita crear, nunca destruye.** Y el impago del negocio nunca alcanza a su cliente.

---

## 3. Stack y paquetes

### 3.1 Versiones

| Componente | Versión | Nota |
|---|---|---|
| PHP | 8.3 | `readonly` promoted properties, enums con métodos |
| Laravel | 12.x | Starter kit `react` |
| MySQL | 8.0 | `FULLTEXT`, `lockForUpdate`, índices únicos compuestos |
| Redis | 7 | caché, sesiones, colas, broadcast |
| Node | 22 LTS | solo build y HMR |
| React | 19 | |
| Inertia | 2.x | partial reloads con `only:` |
| TypeScript | 5.6+ | `strict`, `noUncheckedIndexedAccess` |
| Tailwind | 4 | |
| daisyUI | 5 | envuelto en `components/ui` |

### 3.2 Composer

| Paquete | Para |
|---|---|
| `laravel/framework` | — |
| `inertiajs/inertia-laravel` | Inertia server-side |
| `laravel/socialite` | Google OAuth de `Account` (`REQ-ACC-001`) |
| `laravel/reverb` | WebSocket (`REQ-INF-005`) |
| `spatie/laravel-medialibrary` | imágenes de producto (`REQ-CAT-005`) |
| `spatie/laravel-activitylog` | auditoría (`REQ-BOF-005`) |
| `maatwebsite/excel` | exportaciones (`REQ-BOF-043`) |
| `laravel-notification-channels/webpush` | Web Push (`REQ-NOT-001`) |
| `nesbot/carbon` | ya incluido; se usa `CarbonImmutable` en dominio |
| **dev** `pestphp/pest` + `pest-plugin-laravel` | tests (`REQ-QAX-003`) |
| **dev** `larastan/larastan` | PHPStan nivel 6 |
| **dev** `laravel/pint` | formato |

**No se instala:** `spatie/laravel-permission` (sin RBAC, `REQ-ORG-003`), `laravel/horizon` (fuera de alcance), `laravel/cashier` (cobro manual, `Q3`), ninguna librería monetaria externa (VO propio, `REQ-FX-001`).

### 3.3 NPM

`@inertiajs/react` · `react` `react-dom` · `@tanstack/react-table` · `@tanstack/react-query` · `laravel-echo` + `pusher-js` · `tailwindcss` `@tailwindcss/vite` · `daisyui` · `vite` `@vitejs/plugin-react` · **dev:** `typescript` `vitest` `@testing-library/react` `@testing-library/jest-dom` `jsdom` `eslint` `typescript-eslint` `eslint-plugin-react-hooks` `prettier`

---

## 4. Set de migraciones en orden de dependencias

36 tablas de proyecto. El orden es **obligatorio**: cada bloque depende de los anteriores. Convenciones: todo importe es `BIGINT` de centavos (`*_cents`); toda tasa `DECIMAL(18,8)`; `TIMESTAMP` en UTC; enums de dominio como `VARCHAR`; `id` es `BIGINT UNSIGNED`.

> **Los importes son `BIGINT` con signo, nunca `UNSIGNED`.** La resta de dos `BIGINT UNSIGNED` en MySQL no da negativo: desborda a un número enorme. Con dinero eso es un balance corrupto en lugar de un error visible. Las cantidades que no son dinero (`max_*`, `position`, `stock`) sí van `UNSIGNED`.

> **«`TIMESTAMP` en UTC» solo es cierto si la sesión de MySQL corre en la misma zona que la aplicación**, y por eso la conexión fija `timezone` a `APP_TIMEZONE` (`config/database.php`). Un `TIMESTAMP` se guarda en UTC pero se escribe y se lee convirtiendo con la zona **de la sesión**, y por defecto esa zona es `SYSTEM` —UTC en el contenedor— mientras Laravel manda cadenas ingenuas en `America/Caracas`. Lo que escribe PHP cuadra igual, porque va y vuelve por el mismo camino torcido; lo que escribe **la base de datos** no. `exchange_rates.fetched_at` es el primer valor del proyecto que pone MySQL —lleva `useCurrent()` precisamente para que un reloj de contenedor desfasado no lo falsee (`REQ-FX-002` SP-6)— y salió cuatro horas por delante. Toda columna con `useCurrent()` o escrita por un `DEFAULT`/trigger hereda esta dependencia. Detectado al cerrar `T-020`.

### Bloque 0 — Framework (Laravel las genera)
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`.

> La migración por defecto de `users` **se sustituye**: nuestra tabla lleva `organization_id` y `role`, y no puede crearse antes de `organizations`.

### Bloque 1 — Plataforma sin dependencias

| # | Tabla | Columnas | Índices |
|---|---|---|---|
| 1 | `platform_users` | `name`, `email`, `password`, `disabled_at?`, `must_change_password` | `UNIQUE(email)` |
| 2 | `plans` | `code`, `name`, `price_cents`, `interval`, `is_public`, `position`, `max_users?`, `max_catalogs?`, `max_products?`, `max_products_per_catalog?`, `max_active_layaways?`, `max_storage_mb?`, `max_payment_accounts?`, `features JSON` | `UNIQUE(code)` |
| 3 | `organizations` | `name`, `slug`, `document?`, `timezone`, `logo_path?`, `brand_color?`, `storefront_enabled`, `status`, `suspended_at?`, `deleted_at?` | `UNIQUE(slug)`, `INDEX(status)` |
| 4 | `exchange_rates` | `source`, `rate DECIMAL(18,8)`, `published_at`, `fetched_at` | `INDEX(source, published_at)` |
| 5 | `accounts` | `display_name`, `email`, `avatar_url?`, `locale`, `last_login_at?` | `INDEX(email)` |
| 6 | `platform_settings` | `key` PK, `value JSON`, `updated_by?` → `platform_users` | `PRIMARY(key)` |

> `NULL` en una columna `max_*` significa **ilimitado** (`REQ-SUB-001` RB-2).

### Bloque 2 — Suscripción y consumo

| # | Tabla | Depende de | Índices |
|---|---|---|---|
| 7 | `subscriptions` | `organizations`, `plans` | `UNIQUE(organization_id)` |
| 8 | `subscription_invoices` | `organizations`, `plans` | `INDEX(organization_id, due_date)`, `INDEX(status)` |
| 9 | `subscription_receipts` | `subscription_invoices`, `platform_users` | `UNIQUE(file_hash)`, `INDEX(status)` |
| 10 | `usage_counters` | `organizations` | `UNIQUE(organization_id, metric)` |
| 11 | `support_access_grants` | `platform_users`, `organizations` | `INDEX(organization_id, expires_at)` |

### Bloque 3 — Equipo, ajustes y cobro del tenant

| # | Tabla | Columnas clave | Índices |
|---|---|---|---|
| 12 | `users` | `organization_id`, `name`, `email`, `password?`, `role`, `disabled_at?`, `must_change_password`, `invited_at?`, `invitation_token_hash?`, `invitation_expires_at?` | **`UNIQUE(email)` global**, `INDEX(organization_id, role)`, `INDEX(organization_id, invitation_token_hash)` |
| 13 | `organization_settings` | `organization_id`, `key`, `value JSON`, `updated_by?` | `UNIQUE(organization_id, key)` |
| 14 | `payment_accounts` | `organization_id`, `method`, `bank?`, `holder_name`, `holder_document`, `identifier`, `instructions?`, `is_active`, `position` | `INDEX(organization_id, is_active)` |

> **Las dos columnas de invitación se añadieron en `T-013`**, porque el enlace de 48 h y de un solo uso de `REQ-ORG-004` RB-4 no se sostiene sin guardar cuál vale y hasta cuándo. Van en `users` y no en una tabla propia: RB-3 crea al invitado como fila de `users` desde el primer instante y RB-4 deja vivo un solo enlace por persona, así que la relación es uno a uno y sin historia. Una tabla `team_invitations` sería una partición vertical de `users` con un `JOIN` obligatorio y ninguna pregunta que respondiera mejor.
>
> **El token lleva delante el identificador de su organización** (`7.a8f3…`), y eso es lo que permite resolver el tenant **antes** de consultar `users`, cuyo global scope lanza sin organización fijada. La alternativa era un `UNIQUE(invitation_token_hash)` global —la forma de `layaways.code`— y habría exigido una cuarta entrada en `GlobalUniqueExceptions`, que es una lista cerrada (`REQ-QAX-008` RB-4). Va el identificador y no el `slug` porque el `slug` es editable (`REQ-ORG-006`) y un renombrado no puede romper las invitaciones en vuelo. **Es el patrón para toda credencial que tenga que resolver su tenant antes de poder buscarse**: el identificador en claro delante, el secreto detrás y hasheado.

### Bloque 4 — Identidad del cliente final

| # | Tabla | Columnas clave | Índices |
|---|---|---|---|
| 15 | `account_identities` | `account_id`, `provider`, `provider_id`, `email` | `UNIQUE(provider, provider_id)` |
| 16 | `customers` | `organization_id`, `document_number`, `document_kind`, `full_name`, `phone`, `email?`, `account_id?`, `terms_accepted_at?`, `terms_ip_address?`, `last_login_at?`, `remember_token?` (`T-049`) | `UNIQUE(organization_id, document_number)`, `UNIQUE(organization_id, account_id)`, `INDEX(organization_id, phone)` |
| 17 | `customer_login_tokens` | `organization_id`, `customer_id`, `token_hash`, `expires_at`, `consumed_at?`, `requested_ip` | `UNIQUE(organization_id, token_hash)`, `INDEX(customer_id, expires_at)` |

> `customers` **no tiene columna `password`** (`REQ-IDN-001` RB-5). `accounts` **no tiene documento ni teléfono** (`REQ-ACC-001` RB-2). Ambas ausencias son verificables por test de esquema.
> **El índice de `customer_login_tokens.token_hash` es `(organization_id, token_hash)` y no global, corregido antes de `T-039`.** La tabla declaraba `UNIQUE(token_hash)`, que habría sido la **cuarta** entrada de `GlobalUniqueExceptions` —una lista cerrada en tres, y `tenancy:audit` falla con la cuarta (§4.1)—. No hace falta: `REQ-IDN-003` RB-3 dice que una misma solicitud emite **un enlace por organización**, así que el token ya tiene que saber a cuál pertenece, y la ruta `/mi-cuenta/acceso/enlace/{token}` no lo sabe por el camino. Se resuelve como ya se resolvió la invitación de equipo en `T-013`: el token lleva delante el identificador de su organización (`7.a8f3…`), el tenant se fija con ese prefijo **antes** de consultar la tabla, y el índice queda compuesto. Es la misma forma y el mismo motivo; lo que no se repite es la excepción global. `layaways.code` sí es global y sigue siéndolo: es la credencial del portal y de ella se resuelve la organización sin tenant previo (`REQ-IDN-008` RB-3), que es justo lo que un enlace por organización no necesita.

### Bloque 5 — Catálogo

| # | Tabla | Columnas clave | Índices |
|---|---|---|---|
| 18 | `categories` | `organization_id`, `name`, `slug`, `position` | `UNIQUE(organization_id, slug)`, `UNIQUE(organization_id, name)`, `INDEX(organization_id, position)` |
| 19 | `tags` | `organization_id`, `name`, `slug` | `UNIQUE(organization_id, slug)`, `UNIQUE(organization_id, name)` |
| 20 | `products` | `organization_id`, `category_id`, `sku`, `name`, `slug`, `description`, `price_cents`, `compare_at_price_cents?`, `down_payment_cents`, `installments_count`, `frequency_days`, `stock`, `stock_shortfall`, `status`, `published_at?`, `deleted_at?`, `updated_by?` | `UNIQUE(organization_id, sku)`, `UNIQUE(organization_id, slug)`, `INDEX(organization_id, status)`, `INDEX(organization_id, category_id)`, `FULLTEXT(name, description, sku)` |
| 21 | `product_tag` | `product_id`, `tag_id` | `PRIMARY(product_id, tag_id)` |
| 22 | `product_specifications` | `organization_id`, `product_id`, `group?`, `label`, `value`, `position` | `INDEX(product_id, position)` |
| 23 | `media` | de `spatie/laravel-medialibrary` | — |
| 24 | `catalogs` | `organization_id`, `name`, `slug`, `description?`, `cover_path?`, `is_listed`, `published_at?`, `expires_at?`, `updated_by?` | **`UNIQUE(slug)` global**, `INDEX(organization_id, published_at)` |
| 25 | `catalog_product` | `catalog_id`, `product_id`, `position`, `created_at` | `PRIMARY(catalog_id, product_id)`, `INDEX(catalog_id, created_at)`, `INDEX(catalog_id, position)` |

> `catalog_product.created_at` es **funcional, no metadato**: ordena el feed y define qué es novedad (`REQ-CAT-023` RB-3). No se toca al reordenar.
> `products.category_id` con FK `restrict` (`REQ-BOF-023` RB-4).
> **`UNIQUE(organization_id, name)` en `categories` y `tags` lo exige `REQ-BOF-023` RB-3, y tiene que estar en la base y no solo en la acción** (añadido en `T-024`): RB-8 reutiliza la categoría existente cuando se crea al vuelo desde el formulario de producto, y sin el índice dos peticiones simultáneas con el mismo nombre crean dos categorías y dejan la taxonomía duplicada. La colación por defecto es *case-insensitive*, que es justo lo que «se reutiliza» necesita.
> **`product_specifications.group` conserva su nombre aunque sea palabra reservada en MySQL**: Laravel entrecomilla los identificadores, y renombrarla a `group_name` rompería el contrato del módulo [11](11-catalogo-y-productos.md).

### Bloque 6 — Puente cuenta ↔ catálogo (plataforma)

| # | Tabla | Columnas clave | Índices |
|---|---|---|---|
| 26 | `catalog_subscriptions` | `account_id`, `catalog_id`, `subscribed_at`, `muted_at?` | `UNIQUE(account_id, catalog_id)`, `INDEX(catalog_id)` |
| 27 | `catalog_digest_dispatches` | `catalog_id`, `account_id`, `digest_date`, `products_count`, `sent_at` | `UNIQUE(catalog_id, account_id, digest_date)` |

> Estas dos tablas **no llevan `organization_id`** aunque referencien `catalogs`: pertenecen a la capa de plataforma y cruzan la frontera a propósito. Es la única excepción estructural del modelo y está en la lista blanca de `tenancy:audit`.

### Bloque 7 — Apartado y cobranza

| # | Tabla | Columnas clave | Índices |
|---|---|---|---|
| 28 | `layaways` | `organization_id`, `code`, `idempotency_key`, `customer_id`, `product_id`, `catalog_id?`, `quantity`, `unit_price_cents`, `down_payment_cents`, `installments_count`, `frequency_days`, `product_name_snapshot`, `product_sku_snapshot`, `total_cents`, `paid_cents`, `remaining_cents`, `status`, `expires_at?`, `extended_at?`, `activated_at?`, `next_due_date?`, `completed_at?`, `cancelled_at?`, `cancellation_reason?`, `delivered_at?`, `stock_released_at?` | **`UNIQUE(code)` global**, `UNIQUE(organization_id, idempotency_key)`, `INDEX(organization_id, status, next_due_date)`, `INDEX(organization_id, customer_id)` |
| 29 | `installments` | `organization_id`, `layaway_id`, `number`, `due_date`, `amount_cents`, `paid_cents`, `status`, `paid_at?` | `UNIQUE(organization_id, layaway_id, number)`, `INDEX(organization_id, status, due_date)` |
| 30 | `receipts` | `organization_id`, `layaway_id`, `customer_id`, `payment_account_id?`, `payment_method`, `bank?`, `reference?`, `paid_at`, `declared_amount`, `declared_currency`, `note?`, `file_path`, `file_hash`, `file_mime`, `file_size`, `status`, `reviewed_by?`, `reviewed_at?`, `rejection_reason_code?`, `rejection_reason?`, `replaces_receipt_id?` | `UNIQUE(organization_id, file_hash)`, `UNIQUE(organization_id, bank, reference)`, `INDEX(organization_id, status, created_at)` |
| 31 | `payments` | `organization_id`, `layaway_id`, `receipt_id?`, `amount_cents_usd`, `declared_currency`, `declared_amount`, `rate_applied`, `rate_source`, `rate_published_at?`, `settled_at`, `registered_by?`, `note?` | `UNIQUE(organization_id, receipt_id)`, `INDEX(organization_id, settled_at)` |
| 32 | `payment_allocations` | `organization_id`, `payment_id`, `installment_id`, `amount_cents` | `INDEX(organization_id, payment_id)`, `INDEX(organization_id, installment_id)` |

> `layaways.product_id` con FK `restrict` (`REQ-LAY-002` RB-6). `receipts.replaces_receipt_id` autorreferencia nullable.
> **El único de `installments` es `(organization_id, layaway_id, number)`, corregido en `T-039`.** La tabla declaraba `UNIQUE(layaway_id, number)`, sin la columna del tenant: eso contradice `REQ-ORG-002` RB-3 —todo único de negocio la incluye, salvo las tres de §4.1— y `tenancy:audit` lo rechaza (RB-4.2), comprobado con la forma literal. No es una cuarta excepción: una cuota nunca se busca sin tenant. Anteponer la columna admite exactamente las mismas filas mientras cada cuota lleve la organización de su apartado, y sigue siendo lo que hace idempotente la activación (`REQ-LAY-004` SP-5). **`payments` (`UNIQUE(receipt_id)`, `T-054`) y `notification_dispatches` (`UNIQUE(installment_id, type, channel)`) tienen la misma forma** y el gate las rechazará igual: quien cree cada tabla antepone `organization_id`.
>
> **Y el repaso ya está hecho, para que nadie lo descubra tabla por tabla.** Tras `T-039` se revisaron **todos** los únicos declarados en §4, no solo el que falló. Los dos que la nota de arriba anuncia **quedan corregidos aquí mismo**: `payments` es `UNIQUE(organization_id, receipt_id)` y `notification_dispatches` es `UNIQUE(organization_id, installment_id, type, channel)`. De los demás, ninguno es una cuarta excepción: `users.email`, `catalogs.slug` y `layaways.code` son las tres de §4.1; `catalog_subscriptions` y `catalog_digest_dispatches` son puentes de plataforma ya declarados; `push_subscriptions` (`UNIQUE(endpoint)`) es del paquete y ya está en `NON_TENANT_TABLES`. Quedan **dos que decide la tarea que cree su tabla, y cuya respuesta no es ampliar la lista blanca**: `account_identities` (`UNIQUE(provider, provider_id)`, H6) es capa de plataforma como `accounts` y entra en `NON_TENANT_TABLES` con su motivo; y `subscription_receipts` (`UNIQUE(file_hash)`, `T-069`) es «de plataforma» según [50](50-planes-y-suscripciones.md), así que **o no lleva `organization_id` y se declara no-tenant, o lo lleva y el único se compone** — lo que no puede es llevar la columna y un único sin ella.
> La unicidad de `receipts` es **por organización**, no global: es el cambio del pivote (`REQ-PAY-003` RB-4).
>
> **Tres correcciones de `T-054`, al crear las tablas.** (1) **`payment_allocations` lleva `organization_id`**, y sus índices la llevan delante. La tabla estaba escrita como un pivote, y no lo es: tiene modelo (`PaymentAllocation`), la reconciliación la suma por cuota y la reasignación la reescribe. Un modelo sin la columna del tenant no puede llevar `BelongsToOrganization`, y toda consulta sobre él saldría sin filtro; declararla fuera del tenant, como `product_tag`, habría sido ampliar la lista blanca para que `tenancy:audit` callara. (2) **`receipts` lleva un `CHECK` que exige `bank` y `reference` juntos o ninguno.** El único `(organization_id, bank, reference)` no ve una fila con un `NULL` —en MySQL dos `NULL` no chocan—: para el efectivo es lo que se quiere (`REQ-PAY-003` RB-3), pero una referencia sin banco pasaría el único tantas veces como se subiera. `REQ-PAY-001` RB-2 ya exige los dos salvo en efectivo; la base también. (3) **`receipts` lleva además `INDEX(organization_id, layaway_id, status)`**, para las preguntas por apartado de `LayawayReceipts` (expiración, alertas del portal, rechazo al cancelar).

### Bloque 8 — Comunicación, datos y trabajos

| # | Tabla | Columnas clave | Índices |
|---|---|---|---|
| 33 | `conversations` | `organization_id`, `customer_id`, `last_message_at?`, `customer_unread_count`, `admin_unread_count` | `UNIQUE(organization_id, customer_id)`, `INDEX(organization_id, last_message_at)` |
| 34 | `messages` | `organization_id`, `conversation_id`, `sender_type`, `sender_id`, `body`, `read_at?` | `INDEX(conversation_id, id)` |
| 35 | `push_subscriptions` | `notifiable_type`, `notifiable_id`, `endpoint`, `public_key`, `auth_token`, `content_encoding`, `last_used_at?` | `UNIQUE(endpoint)`, `INDEX(notifiable_type, notifiable_id)` |
| 36 | `notification_dispatches` | `organization_id`, `notifiable_type`, `notifiable_id`, `installment_id?`, `type`, `channel`, `sent_at` | `UNIQUE(organization_id, installment_id, type, channel)`, `INDEX(organization_id, sent_at)` |
| 37 | `telemetry_events` | `organization_id?`, `catalog_id?`, `event_name`, `visitor_id`, `account_id?`, `customer_id?`, `subject_type?`, `subject_id?`, `payload JSON`, `session_id`, `ip_hash`, `user_agent`, `occurred_at` | `INDEX(event_name, occurred_at)`, `INDEX(organization_id, occurred_at)`, `INDEX(catalog_id, occurred_at)`, `INDEX(account_id)`, `INDEX(visitor_id)` |
| 38 | `telemetry_monthly_aggregates` | `organization_id?`, `event_name`, `year_month`, `count` | `UNIQUE(organization_id, event_name, year_month)` |
| 39 | `export_jobs` | `organization_id`, `user_id`, `type`, `filters JSON`, `status`, `file_path?`, `rows_count?`, `error_message?`, `expires_at?` | `INDEX(organization_id, created_at)` |
| 40 | `activity_log` | de `spatie` **+ `organization_id?`** | `INDEX(organization_id, created_at)` |
| 41 | `platform_activity_log` | `platform_user_id?`, `organization_id?`, `event`, `subject_type?`, `subject_id?`, `properties JSON`, `created_at` | `INDEX(organization_id, created_at)` |

> `telemetry_events.subject_type/subject_id` es polimórfico **sin FK**, a propósito: archivar un producto no debe borrar su historia (`REQ-TLM-001` SP-5).
> `activity_log.organization_id` es nullable porque una acción de plataforma se registra ahí con `organization_id` puesto pero sin actor del tenant (`REQ-BOF-005` RB-6).

### 4.1 Las tres excepciones de unicidad global

Van en la lista blanca de `GlobalUniqueExceptions` con su motivo, y `tenancy:audit` falla si aparece una cuarta (`REQ-QAX-008` RB-4):

| Índice | Motivo |
|---|---|
| `users.email` | El login del backoffice no pide organización; se deriva del usuario (`REQ-BOF-001` RB-2) |
| `catalogs.slug` | Es la URL pública `apartar.store/c/{slug}`; no puede colisionar entre negocios (`Q4`) |
| `layaways.code` | Es la prueba de acceso al portal; de él se resuelve la organización sin tenant previo (`REQ-IDN-008` RB-3) |

`catalog_subscriptions` y `catalog_digest_dispatches` se añaden a la lista como **tablas** exentas de `organization_id`, por el motivo de §4 bloque 6.

**Y desde `T-024` hay una segunda clase de exención, que conviene no confundir con la primera.** Las dos de arriba son **puentes de plataforma**: relacionan una `Account` global con un catálogo y cruzan la frontera del tenant a propósito. `product_tag` y `catalog_product` son **pivotes entre dos tablas ya aisladas**: a una fila solo se llega desde padres que ya llevan el *global scope*, así que la columna sería un segundo censo de a quién pertenece la fila —derivable de cualquiera de sus extremos y capaz de discrepar de ellos—. Lo que impide juntar el producto de un negocio con el catálogo de otro no es una columna: es la validación de `SyncCatalogProductsAction` (`REQ-CAT-023` RB-1), que es donde la spec la pone. **No hay una tercera clase**: una tabla con modelo propio y consultas propias lleva su `organization_id`, sin excepción.

---

## 5. Los siete colaboradores transversales

Son las clases que aparecen en varios módulos. Su firma se fija aquí una vez.

```php
// ─── 1. app/Domain/Tenancy/TenantContext.php ───────────────────────────
/**
 * Organización activa de la petición o del job.
 *
 * Singleton por ciclo de vida. Lo fija un middleware (web) o el propio job
 * (colas). Ninguna otra clase escribe organization_id a mano.
 */
final class TenantContext
{
    public function organization(): Organization;          // @throws MissingTenantContextException
    public function organizationId(): int;                 // @throws MissingTenantContextException
    public function hasOrganization(): bool;
    /** @throws TenantAlreadySetException si se intenta cambiar a otra en la misma petición */
    public function setOrganization(Organization $organization): void;
    public function forget(): void;                        // obligatorio en el finally de todo job
    /** Ejecuta $callback con ese tenant y lo restaura al salir. */
    public function runFor(Organization $organization, Closure $callback): mixed;
}

// ─── 2. app/Domain/Tenancy/BelongsToOrganization.php ───────────────────
/**
 * Aísla un modelo por organización: global scope al leer, relleno al crear.
 *
 * Usado por todos los modelos del tenant. Quitar el scope exige
 * la exclusión explícita del scope, permitida solo en la lista cerrada
 * de REQ-QAX-008 RB-4b.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void;
    public function organization(): BelongsTo;
    public function scopeWithoutTenantScope(Builder $query): Builder;
}

// ─── 3. app/Domain/Subscription/LimitEnforcer.php ──────────────────────
/**
 * Único punto de decisión sobre si una organización puede crear algo más
 * o usar una función de plan. Ninguna Action comprueba límites por su cuenta.
 *
 * assertCanCreate lee el contador BLOQUEADO dentro de la transacción, para
 * que dos creaciones simultáneas no superen el techo.
 */
final class LimitEnforcer
{
    /** @throws PlanLimitReachedException con metric, used, limit y minimumPlan */
    public function assertCanCreate(UsageMetric $metric, int $amount = 1): void;
    /** @throws PlanFeatureUnavailableException con feature y minimumPlan */
    public function assertHasFeature(PlanFeature $feature): void;
    /** null = ilimitado */
    public function remaining(UsageMetric $metric): ?int;
    public function hasFeature(PlanFeature $feature): bool;
    /** Para las page props: consumo de las seis métricas + funciones activas. */
    public function state(): PlanState;
}

// ─── 4. app/Domain/Payment/PaymentApplier.php ──────────────────────────
/**
 * Aplica un pago a un apartado. Colaborador COMPARTIDO por la aprobación de
 * comprobante (REQ-PAY-005) y el pago manual (REQ-PAY-007): ambos caminos
 * deben producir resultados idénticos para el mismo importe.
 *
 * Se invoca SIEMPRE dentro de una transacción con el apartado bloqueado.
 * Es el único lugar que crea Payment y PaymentAllocation.
 */
final class PaymentApplier
{
    public function apply(
        Layaway $layaway,
        Money $settled,
        RateSnapshot $rate,
        string $declaredAmount,
        Currency $declaredCurrency,
        ?Receipt $receipt,
        ?User $registeredBy,
        ?string $note,
    ): AppliedPayment;
}

/** Resultado de aplicar un pago: qué se creó y qué quedó sin asignar. */
final readonly class AppliedPayment
{
    public function __construct(
        public Payment $payment,
        public AllocationPlan $plan,
        public LayawayStatus $statusBefore,
        public LayawayStatus $statusAfter,
    ) {}
}

// ─── 5. app/Domain/Layaway/LayawayBalanceRecalculator.php ──────────────
/**
 * ÚNICA clase que escribe paid_cents, remaining_cents, next_due_date y status
 * de un apartado. Verifica las cuatro invariantes de REQ-PAY-008 RB-3.
 */
final class LayawayBalanceRecalculator
{
    /** Recalcula desde los pagos y cuotas reales. Acota remaining a >= 0. */
    public function recalculate(Layaway $layaway): void;
    /** Compara lo materializado con lo derivado sin escribir. */
    public function diff(Layaway $layaway): ?BalanceDiscrepancy;
}

// ─── 6. app/Domain/Notification/NotifiableResolver.php ─────────────────
/**
 * Resuelve el destinatario efectivo de un aviso dirigido a una ficha.
 *
 * Si la ficha tiene Account vinculada, se usan las suscripciones push de la
 * cuenta; si no, las de la ficha. Es lo que evita notificar dos veces a una
 * persona que tiene ambas identidades (REQ-NOT-001 RB-2).
 */
final class NotifiableResolver
{
    /** @return list<PushSubscription> */
    public function pushSubscriptionsFor(Customer $customer): array;
    public function emailFor(Customer $customer): ?string;
    public function canReachByPush(Customer $customer): bool;
}

// ─── 7. app/Domain/Account/PortalContext.php ───────────────────────────
/**
 * Identidad efectiva del portal: cuenta global, ficha acotada, o ambas.
 *
 * ÚNICA fuente de "qué fichas puede ver esta sesión". Ninguna consulta del
 * portal accede a customers fuera de este contexto.
 */
final readonly class PortalContext
{
    public function __construct(
        public ?Account $account,
        public ?Customer $scopedCustomer,
    ) {}

    /** @return Collection<int, Customer> Fichas visibles, con su organización cargada. */
    public function customers(): Collection;
    /** @return list<int> */
    public function organizationIds(): array;
    public function isScoped(): bool;
    public function canUseFeed(): bool;
    /** Resuelve un apartado por code acotado a las fichas visibles. @throws ModelNotFoundException */
    public function findLayaway(string $code): Layaway;
}
```

### 5.1 Reglas de composición entre ellos

Importa el orden en que se llaman, porque de eso depende que las invariantes se cumplan:

```
ApproveReceiptAction / RegisterManualPaymentAction
  └─ DB::transaction
       ├─ lockForUpdate() sobre el Layaway            ← siempre primero
       ├─ ExchangeRateService::current($org)          → RateSnapshot
       ├─ PaymentApplier::apply(...)
       │    ├─ crea Payment con el snapshot de tasa
       │    ├─ FifoAllocator::plan()                  → AllocationPlan
       │    ├─ actualiza installments
       │    ├─ LayawayBalanceRecalculator::recalculate()
       │    └─ LayawayStateMachine::transition()
       │         └─ UsageCalculator ajusta active_layaways
       ├─ marca el Receipt (si viene)
       └─ audita
  └─ afterCommit → notificaciones + broadcast
```

Y en creación:

```
CreateLayawayAction                                    (tenant = el del catálogo, RB-1)
  ├─ ¿existe ya la idempotencyKey? → lo devuelve        ← RB-12, sin transacción
  ├─ valida catálogo accesible y producto en el catálogo ← lecturas FUERA de la transacción
  └─ DB::transaction
       ├─ ProductLock::acquire() sobre el Product       ← orden canónico: producto, luego todo lo demás
       ├─ ¿existe ya la idempotencyKey? → lo devuelve   ← RB-12, ya bajo el bloqueo
       ├─ estado publicado y stock >= quantity          ← sobre la fila bloqueada
       ├─ LimitEnforcer::assertCanCreate(ActiveLayaways) → NotAcceptingLayawaysException (neutro)
       ├─ organización activa
       ├─ ResolveCustomerAction                         → Customer
       ├─ congela LayawayTerms → LayawayCode::claim() → crea Layaway
       ├─ ReserveStockAction
       ├─ LimitEnforcer::adjust(ActiveLayaways, +1)
       └─ LinkAccountAction + SubscribeToCatalogAction (si hay sesión de cuenta — H6)
  ├─ colisión en UNIQUE(organization_id, idempotency_key) → deshace todo y devuelve el que ganó
  └─ afterCommit → email de confirmación (T-045b) + telemetría (T-084)
```

> **Por qué las lecturas de validación van fuera de la transacción** (`T-045`). En `REPEATABLE READ`, la primera lectura normal fija la foto de toda la transacción. Si el catálogo se leyera dentro y antes del bloqueo del producto, la foto sería anterior a que confirmase otro envío con la misma clave que esperaba ese mismo bloqueo, y la segunda comprobación de la clave no lo vería: devolvería un `422` de stock en vez del apartado. El `FOR UPDATE` no fija la foto; la primera lectura normal después de él, sí, y ya ve lo confirmado.

---

## 6. Contrato del proveedor de tasa: DolarApi

### 6.1 Endpoint

```
GET https://ve.dolarapi.com/v1/dolares
```

Una sola petición devuelve todas las cotizaciones, lo que satisface `REQ-FX-002` RB-0 (una ingesta para toda la plataforma) sin hacer dos llamadas. Fuente declarada: **BCV**.

Respuesta (por elemento):

```json
{
  "fuente": "oficial",
  "nombre": "Oficial",
  "compra": 0,
  "venta": 0,
  "promedio": 36.5,
  "fechaActualizacion": "2026-09-10T14:30:00.000Z"
}
```

### 6.2 Mapeo

| DolarApi | Nuestro modelo | Regla |
|---|---|---|
| `fuente: "oficial"` | `RateSource::Bcv` | |
| `fuente: "paralelo"` | `RateSource::Parallel` | |
| otras fuentes | — | se ignoran en silencio |
| `promedio` \| `venta` \| `compra` | `rate` | **primer valor > 0 en ese orden**; si los tres son 0 o nulos, la cotización se descarta |
| `fechaActualizacion` | `published_at` | es la antigüedad que se muestra al usuario |
| — | `fetched_at` | lo pone la BD al insertar |

La elección de campo se declara en `config/services.php` (`exchange_rate.field_priority`) por si el negocio decide después que quiere `venta` en lugar de `promedio`. El default es `['promedio', 'venta', 'compra']`.

**«Se descarta» es en singular: se descarta esa cotización, no el payload.** Si el paralelo viene con los tres campos a cero, el BCV de la misma respuesta **entra igual**; perder las dos fuentes porque una venía rota es convertir un fallo parcial del proveedor en uno total nuestro. Quien pregunte después por la fuente descartada recibe **el motivo** —no un hueco—, y el job la anota y sigue con la otra. Lo mismo vale para `fechaActualizacion`: la valida cada cotización por su cuenta.

**`fechaActualizacion` viene en UTC (`…T14:30:00.000Z`) y se normaliza a la zona de la aplicación en el borde**, donde entra el dato de fuera. El instante no cambia, solo cómo se escribe — pero escribirlo mal no se ve como una fecha torcida: `published_at` es la mitad de la clave de deduplicación de `REQ-FX-002` RB-3, así que un desplazamiento de cuatro horas hace que la comparación **no vuelva a coincidir nunca** y el job inserte una fila nueva de la misma cotización cada media hora. Detectado al cerrar `T-020`.

### 6.3 Comportamiento

- Timeout **8 s**, 3 reintentos con backoff (10 s / 60 s / 300 s), `WithoutOverlapping`.
- **Validación de plausibilidad** (`REQ-FX-002` SP-2): se rechaza `rate <= 0` y toda variación **> 30 %** respecto a la última persistida de la misma fuente, **si esa última tiene menos de 24 h** (SP-2b: sin referencia reciente la guarda se abstiene, porque una referencia vieja veta para siempre). Se registra alerta y se conserva la anterior, **sin reintentar**. La cuenta va entera por `bcmath` sobre cadenas: con una división en coma flotante el umbral del 30 % dejaría de ser exactamente el 30 % en los bordes, y es la comprobación que decide si una cifra entra al histórico.
- Si `fechaActualizacion` no cambió y `rate` es idéntica, **no se inserta fila** (`REQ-FX-002` RB-3).
- Test de contrato con un **fixture del payload real** (`REQ-QAX-003` RB-9); `Http::fake()` en el resto de los tests.
- `ManualRateProvider` lee `organization_settings.exchange_rate_manual` y **no** inserta en `exchange_rates` (`REQ-FX-003` RB-4).

### 6.4 Sembrado inicial

`ExchangeRateSeeder` inserta una tasa BCV inicial, para que una instalación nueva no arranque sin ninguna (`REQ-FX-005` SP-1). Es **idempotente** —`exchange_rates` es histórico inmutable y sembrar dos veces ensucia lo que existe para justificar pagos— y se fecha **dos días atrás a propósito**, para que nazca marcada como obsoleta: es un marcador de posición, y una cifra de ejemplo aparentando ser la tasa de hoy engaña mejor que no tener ninguna.

**Esa antigüedad deliberada es también lo que impide que la semilla bloquee la primera ingesta real.** Con la guarda de `REQ-FX-002` SP-2b, una referencia de más de 24 h no veta, así que la primera lectura entra por lejos que quede de `36.50`. Sin SP-2b habría hecho falta acordarse de actualizar la cifra en cada despliegue —un paso manual, no escrito en ningún runbook, cuyo olvido se manifiesta como precios en bolívares equivocados para toda la plataforma y una línea de log—, y eso es exactamente la clase de deber que nadie cumple.

---

## 7. Tabla de rutas

Esta sección no es documental: **`REQ-QAX-008` deriva sus tests de fuga de aquí**. Cada ruta marcada `T` (tenant) genera automáticamente un caso que intenta acceder al recurso equivalente de una organización vecina y exige `404`.

### 7.1 Marketing — `routes/web.php`, sin guard

| Método | URI | Controlador |
|---|---|---|
| GET | `/` | `Marketing\HomeController` |
| GET | `/precios` | `Marketing\PricingController` |
| GET · POST | `/registro` | `Marketing\RegisterController` |
| GET | `/terminos`, `/privacidad` | `Marketing\LegalController` |

### 7.2 Catálogo público — `routes/catalog.php`, middleware `ResolveCatalogTenant` + `AssignVisitorId`

| Método | URI | Controlador | Tenant |
|---|---|---|---|
| GET | `/{organization:slug}` | `Catalog\OrganizationStorefrontController` | T |
| GET | `/c/{catalog:slug}` | `Catalog\CatalogShowController` | T |
| GET | `/c/{catalog:slug}/p/{product:slug}` | `Catalog\ProductShowController` | T |
| GET · POST | `/c/{catalog:slug}/apartar/{product:slug}` | `Catalog\LayawayController` | T |
| GET | `/c/{catalog:slug}/apartar/{code}/gracias` | `Catalog\LayawayThanksController` | T |
| POST · DELETE | `/c/{catalog:slug}/seguir` | `Catalog\CatalogSubscriptionController` | T |
| POST | `/c/{catalog:slug}/chat` | `Catalog\ChatController` | T |

> `/{organization:slug}` va **al final** del archivo de rutas: es un comodín de un segmento y debe ceder ante toda ruta literal. Desde `T-036` además **no admite ningún slug reservado** (`->where('organization', ReservedSlugs::routePattern())`), para que un segmento reservado sin ruta propia todavía —`/precios` antes de `T-037`— no caiga en la vitrina de nadie. La lista negra de `ReservedSlugs` (`registro`, `precios`, `backoffice`, `plataforma`, `c`, `mi-cuenta`, `api`, `archivos`, `terminos`, `privacidad`, `invitacion`, `cuenta`) lo protege en el registro y en el renombrado.

### 7.3 Portal del cliente — `routes/portal.php`, prefijo `/mi-cuenta`

| Método | URI | Guard | Tenant |
|---|---|---|---|
| GET | `/acceso` | — | — |
| POST | `/acceso/codigo` | — | — |
| GET · POST | `/acceso/enlace` | — | — |
| GET · POST | `/acceso/enlace/{token}` | — | — |
| GET | `/cuenta/google/redirect`, `/callback` | — | — |
| GET | `/` (dashboard) | `account`\|`customer` | T por ficha |
| GET | `/apartados/{code}` | `account`\|`customer` | T por ficha |
| GET · POST | `/apartados/{code}/comprobante` | `account`\|`customer` | T por ficha |
| GET | `/novedades` | `account` | — |
| GET · PATCH | `/suscripciones`, `/suscripciones/{catalog}` | `account` | — |
| GET · POST | `/reclamar` | `account` | — |
| GET · POST | `/chat`, `/chat/{organization:slug}` | `account`\|`customer` | T por ficha |
| POST · DELETE | `/push` | `account`\|`customer` | — |
| POST | `/salir` | `account`\|`customer` | — |

### 7.4 Backoffice — `routes/backoffice.php`, prefijo `/backoffice`, guard `web` + `ResolveTenant`

| Grupo | Rutas | Rol | Tenant |
|---|---|---|---|
| Auth | `GET·POST /login`, `POST /logout`, recuperación de contraseña, cambio obligatorio | — | — |
| Dashboard | `GET /` | ambos | T |
| Productos | `GET /productos`, `GET /productos/crear`, `POST /productos`, `GET·PUT /productos/{product}`, `POST /productos/{product}/archivar` | ambos | T |
| Imágenes | `POST /productos/{product}/imagenes`, `DELETE .../{media}`, `PATCH .../orden` | ambos | T |
| Taxonomía | CRUD `/categorias`, `/etiquetas` | ambos | T |
| Catálogos | CRUD `/catalogos`, `PATCH /catalogos/{catalog}/productos`, `POST·DELETE /catalogos/{catalog}/publicar` | ambos | T |
| Clientes | `GET /clientes`, `GET /clientes/{customer}`, `POST /clientes/{customer}/fusionar`, `POST /clientes/{customer}/reenviar-codigo` | ambos | T |
| Cobranza | `GET /cobranza` | ambos | T |
| Apartados | `POST /apartados/{layaway}/cancelar`, `.../entregar`, `POST /apartados/{layaway}/pagos`, `PATCH /cuotas/{installment}/reprogramar` | ambos | T |
| Comprobantes | `GET /comprobantes`, `GET /comprobantes/{receipt}`, `POST .../aprobar`, `POST .../rechazar`, `PATCH /pagos/{payment}/reasignar` | ambos | T |
| Chat | `GET /chat`, `GET /chat/{conversation}`, `POST /chat/{conversation}` | ambos | T |
| Suscriptores | `GET /suscriptores` | ambos | T |
| Notificaciones | `GET·POST /notificaciones` | ambos | T |
| Exportaciones | `GET·POST /exportaciones`, `GET /exportaciones/{export}/descargar` | ambos | T |
| Analítica | `GET /analitica` | ambos | T |
| **Equipo** | CRUD `/equipo`, `POST /equipo/{user}/desactivar`, `.../activar` | **owner** | T |
| **Cuentas de cobro** | CRUD `/cuentas-de-cobro` | **owner** | T |
| **Ajustes** | `GET·PUT /ajustes`, `PUT /ajustes/tasa` | **owner** | T |
| **Suscripción** | `GET /suscripcion`, `POST /suscripcion/cambiar`, `GET /suscripcion/facturas`, `POST /suscripcion/facturas/{invoice}/comprobante` | **owner** | T |
| **Auditoría** | `GET /auditoria` | **owner** | T |

### 7.5 Plataforma — `routes/platform.php`, prefijo `/plataforma`, guard `platform`

| Método | URI | Nota |
|---|---|---|
| GET·POST | `/login`, POST `/logout` | — |
| GET | `/` (métricas) | agregados |
| GET | `/organizaciones`, `/organizaciones/{organization}` | agregados; datos personales exigen grant |
| POST | `/organizaciones/{organization}/plan` | motivo obligatorio |
| POST·DELETE | `/organizaciones/{organization}/soporte` | grant de soporte |
| GET | `/suscripciones`, `/facturas` | — |
| POST | `/facturas/{invoice}/aprobar`, `/rechazar`, `/anular`, `/cortesia` | — |
| GET | `/salud` | sin caché |

### 7.6 Fuera de grupo

| Método | URI | Nota |
|---|---|---|
| GET | `/archivos/comprobantes/{receipt}` | firmada + policy + pertenencia al tenant |
| GET | `/invitacion/{token}`, POST idem | aceptación de invitación de equipo |
| GET | `/api/tasa` | público, cacheado 60 s |
| POST | `/api/telemetry` | público, rate limit 60/min |

### 7.7 Canales de broadcast — `routes/channels.php`

| Canal | Autoriza |
|---|---|
| `organization.{orgId}.receipts` | miembro activo de esa organización |
| `organization.{orgId}.chat` | miembro activo de esa organización |
| `organization.{orgId}.conversation.{id}` | cliente titular **o** miembro activo de esa organización |

---

## 8. Árbol de archivos

```
app/
├── Domain/
│   ├── Tenancy/        Organization…, OrganizationRole, OrganizationStatus, TenantContext,
│   │                   BelongsToOrganization, TenantResolver, GlobalUniqueExceptions
│   ├── Subscription/   Plan…, PlanFeature, UsageMetric, SubscriptionStatus, LimitEnforcer,
│   │                   UsageCalculator, DowngradeOutcome, PlanChangePreview
│   ├── Account/        AccountProvider, LinkOrigin, PortalContext, FeedQuery, FeedFilters
│   ├── Money/          Money, Currency, MoneyFormatter
│   ├── Exchange/       ExchangeRateProvider, DolarApiProvider, ManualRateProvider,
│   │                   ExchangeRateService, RateSnapshot, RateSource
│   ├── Catalog/        ProductStatus, CatalogVisibility
│   ├── Layaway/        LayawayStatus, InstallmentStatus, LayawayTerms, ScheduledInstallment,
│   │                   AmortizationSchedule, ScheduleGenerator, LayawayStateMachine,
│   │                   LayawayBalanceRecalculator
│   ├── Payment/        PaymentMethod, ReceiptStatus, RejectionReasonCode, AllocationPlan,
│   │                   FifoAllocator, PaymentApplier, AppliedPayment
│   ├── Collections/    CollectionPreset
│   ├── Notification/   NotificationType, NotificationAudience, NotifiableResolver
│   ├── Telemetry/      TelemetryEventName, EventSanitizer, SanitizedEvent
│   ├── Settings/       SettingKey
│   └── Auditing/       AuditEvent
├── Actions/            Organization/ Subscription/ Account/ Customer/ Layaway/ Payment/
│                       Product/ Catalog/ Notification/ Chat/ Settings/ Platform/
├── Http/
│   ├── Controllers/    Marketing/ Catalog/ Portal/ Backoffice/ Platform/ Api/
│   ├── Requests/       Marketing/ Catalog/ Portal/ Backoffice/
│   ├── Resources/      + Concerns/ProjectsMoney.php
│   └── Middleware/     ResolveTenant, ResolveCatalogTenant, ResolvePortalTenant,
│                       EnsurePortalSession, EnsureIsOwner, EnsureAdminIsActive,
│                       EnsureOrganizationIsActive, EnsurePasswordIsChanged,
│                       EnsurePlatformUserIsActive, RequiresSupportAccess,
│                       EnforcePlanLimit, AssignVisitorId, HandleInertiaRequests
├── Queries/            CustomerBalanceQuery, CustomerDirectoryQuery, CollectionFilters,
│                       CatalogFacetsQuery, ConversationListQuery, PortalAlertsQuery,
│                       DashboardMetricsQuery, DashboardWarningsQuery, SubscriberListQuery,
│                       SubscriberStatsQuery, CatalogAnalyticsQuery, SpecificationGroupsQuery,
│                       Platform/{OrganizationDirectoryQuery, PlatformMetricsQuery, SystemHealthQuery}
├── Jobs/               + Concerns/RunsInTenantContext.php
├── Models/  Notifications/  Policies/  Events/  Listeners/  Exports/  Rules/  Casts/
└── Support/            Settings, SignedFileUrl, DocumentNumber, PhoneNumber, LayawayCode,
                        IpHasher, ReservedSlugs, UniqueCatalogSlug, FullTextTerm,
                        SortWhitelist, NavigationCategories, Auditing/LogsSensitiveActivity

resources/js/
├── pages/       marketing/ catalog/ portal/ backoffice/ platform/
├── features/    tenancy/ plan/ account/ currency/ catalog/ layaway/ receipts/
│                chat/ telemetry/ collections/ products/ catalogs/ data-table/
├── components/ui/   layouts/   lib/   types/

tests/
├── Unit/Domain/     un archivo por clase de app/Domain
├── Feature/         por superficie: Marketing/ Catalog/ Portal/ Backoffice/ Platform/ Api/
├── Feature/Tenancy/ CrossTenantAccessTest, BroadcastChannelIsolationTest,
│                    JobTenantContextTest, AccountBoundaryTest
└── Concerns/        CreatesNeighbourOrganization
```

---

## 9. Entorno local

Sin Traefik, sin TLS, sin dominio. La aplicación vive en `http://localhost:8080`.

### 9.1 Servicios y puertos

| Servicio | Imagen | Puerto host | Rol |
|---|---|---|---|
| `app` | propia (PHP 8.3-fpm-alpine) | — | PHP-FPM |
| `nginx` | `nginx:1.27-alpine` | **8080** → 80 | web |
| `mysql` | `mysql:8.0` | **3307** → 3306 | BD (puerto no estándar para no chocar con un MySQL local) |
| `redis` | `redis:7-alpine` | **6380** → 6379 | caché, sesiones, colas, broadcast |
| `queue` | propia | — | `queue:work` + `schedule:work` |
| `reverb` | propia | **8081** → 8080 | WebSocket |
| `node` | `node:22-alpine` | **5173** | Vite HMR |

Volúmenes: `mysql_data`, `redis_data`, `app_storage`. Red única `apartar` (no hay `traefik_proxy` en local).

### 9.2 Variables de entorno relevantes

```
APP_URL=http://localhost:8080
APP_TIMEZONE=America/Caracas
APP_LOCALE=es

DB_HOST=mysql   DB_DATABASE=apartar
REDIS_HOST=redis
CACHE_STORE=redis  SESSION_DRIVER=redis  QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local

REVERB_APP_ID= REVERB_APP_KEY= REVERB_APP_SECRET=
REVERB_HOST=reverb            # interno, para difundir
REVERB_PORT=8080
VITE_REVERB_HOST=localhost    # público, para el navegador
VITE_REVERB_PORT=8081
VITE_REVERB_SCHEME=http

GOOGLE_CLIENT_ID= GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8080/cuenta/google/callback

EXCHANGE_RATE_DRIVER=dolarapi
EXCHANGE_RATE_URL=https://ve.dolarapi.com/v1/dolares
EXCHANGE_RATE_TIMEOUT=8

VAPID_PUBLIC_KEY= VAPID_PRIVATE_KEY=

PLATFORM_ADMIN_NAME= PLATFORM_ADMIN_EMAIL= PLATFORM_ADMIN_PASSWORD=
```

### 9.3 Datos de desarrollo

`DevSeeder` (nunca en producción) siembra un escenario **con dos organizaciones**, porque es lo que hace visibles las fugas de tenant desde el primer día (`REQ-QAX-003` RB-10):

- Los 3 planes (`PlanSeeder`) + un `PlatformUser` (`PlatformAdminSeeder`) + una tasa BCV inicial.
- **Org A "Ferretería El Tornillo"** (plan Pro): 2 usuarios, 3 cuentas de cobro, 4 categorías, 12 etiquetas, 40 productos, 3 catálogos (uno `Listed`, uno `Unlisted`, uno `Expired`), 15 clientes y **apartados en los seis estados**, con comprobantes en los tres estados.
- **Org B "Boutique Marisol"** (plan Free, **al límite de productos**): 1 usuario, 1 cuenta de cobro, 30 productos, 1 catálogo, 8 clientes. Sirve para probar límites y degradación.
- **3 `Account`** de consumidor: una vinculada a fichas de **ambas** organizaciones (el caso multi-negocio), una vinculada solo a A, y una sin fichas pero con suscripciones (el caso de seguidor que no compró).

Ese último escenario es el que ejercita el modelo de dos identidades y el que conviene tener sembrado desde el principio.

### 9.4 Comandos de trabajo (`Makefile`)

| Comando | Hace |
|---|---|
| `make up` / `make down` | levanta / baja el stack |
| `make fresh` | `migrate:fresh --seed` con `DevSeeder` |
| `make sh` | shell en `app` |
| `make test` | Pest |
| `make check` | Pint + PHPStan + Pest + ESLint + tsc + Vitest |
| `make tenancy` | `tenancy:audit` + suite `tests/Feature/Tenancy` |

---

## 10. Orden de construcción

El criterio: **tener lo antes posible un esqueleto que camine de punta a punta** —un negocio que publica un catálogo y cobra una cuota— y dejar para después lo que es valioso pero no imprescindible para demostrarlo.

### 10.1 Hitos

| Hito | Objetivo demostrable | Módulos | Req. |
|---|---|---|---|
| **H0 · Cimientos** | El stack corre, hay dos organizaciones sembradas y el gate de aislamiento existe (aunque cubra poco) | INF (local), ORG, QAX | 16 |
| **H1 · Aritmética** | `Money` y el cronograma pasan sus tests de propiedad; la tasa se ingesta de DolarApi | FX | 5 |
| **H2 · Catálogo** | Un negocio carga productos, arma un catálogo y **el enlace público funciona** | CAT, BOF-inventario | 19 |
| **H3 · Apartado** | Un cliente **aparta** desde el catálogo y ve su apartado en el portal | IDN, LAY | 23 |
| **H4 · Cobranza** | El cliente **sube un comprobante**, el negocio lo aprueba y el balance cuadra ← *aquí el producto ya sirve* | PAY, BOF-cobranza + `FX-006` | 16 |
| **H5 · Planes** | Los límites bloquean, el cambio de plan funciona, se cobra por comprobante | SUB | 8 |
| **H6 · Cuentas y feed** | Google, suscripción a catálogo, feed multi-negocio y reclamo de ficha | ACC | 7 |
| **H7 · Comunicación** | Chat en tiempo real y notificaciones de cobro | CHT, NOT | 10 |
| **H8 · Datos y plataforma** | Telemetría, analítica y tu backoffice de plataforma | TLM, PLT | 11 |
| **H9 · Producción** | Traefik, TLS, dominio, despliegue | INF diferido | 3 |

**H4 es la línea de flotación**: al cerrarlo, la plataforma ya hace lo que promete. Todo lo posterior es monetización (H5), red (H6), retención (H7) y operación (H8).

### 10.2 Por qué este orden y no otro

- **ORG antes que todo.** Si el aislamiento se añade después, hay que revisar cada consulta ya escrita. Construirlo primero significa que el *global scope* está puesto antes de que exista la primera consulta que podría olvidarlo.
- **FX antes que LAY y PAY.** El cronograma y la amortización dependen de `Money::allocateEvenly()`. Escribirlos sin esa base obliga a rehacerlos.
- **CAT antes que LAY.** El apartado nace dentro de un catálogo (`REQ-LAY-001` RB-1); sin catálogo no hay dónde apartar.
- **SUB después de H4 y no antes.** `LimitEnforcer` se invoca desde acciones que aún no existirían. Es más limpio insertar las llamadas cuando las acciones ya están escritas y probadas, y mientras tanto `LimitEnforcer` puede devolver "sin límite".
- **ACC después de H4.** La capa de cuentas es aditiva: `customers.account_id` es nullable y todo funciona sin ella. Construirla antes complicaría el flujo de apartado sin necesidad.
- **PLT al final.** Es la superficie que **tú** usas. Puede esperar; lo que no puede esperar es que un negocio pueda cobrar.

### 10.3 Regla de fases dentro de cada hito

Cada hito se construye en dos pasadas (`REQ-QAX-006` RB-2 y RB-3):

1. **Fase A** — enums, VOs, DTOs, migraciones, modelos, firmas de `Action` vacías, tipos TS, tests en rojo. Cierra con `phpstan` y `tsc` limpios.
2. **Fase B** — cuerpos, dominio, jobs, componentes. Cierra con los tests del hito en verde y su `Anti-SHALLOW` resuelto.

La excepción es **H0**, que se hace de una sola pasada: sin migraciones y sin tenancy funcionando no hay nada que tipar.

---

## 11. Riesgos técnicos identificados

Ninguno bloquea el arranque, pero conviene tenerlos escritos:

| Riesgo | Mitigación prevista |
|---|---|
| **Fuga de tenant** por un `where` olvidado | El *global scope* lo hace improbable; `REQ-QAX-008` lo hace detectable. Es el riesgo con más inversión de mitigación de todo el proyecto |
| **Un solo worker de colas** bloqueado por una exportación larga | Aceptado en v1 (`REQ-INF-003` SP-2); la salida es una réplica dedicada a `exports` |
| **DolarApi** cae o cambia de formato | Fixture de contrato, degradación a la última tasa, `ManualRateProvider` como salida (`REQ-FX-005`) |
| **`/{organization:slug}` colisiona** con una ruta futura | `ReservedSlugs` + la ruta va al final del archivo. Añadir una ruta literal nueva exige añadirla a la lista negra |
| **Reverb** en un solo nodo | Fallback a polling ya especificado (`REQ-CHT-003` RB-4); escalar exige Redis como backend |
| **Estado vacío del feed** sin descubrimiento global | Consecuencia asumida (§4.8 de [03](03-pivote-saas-impacto.md)); mitigado por la vitrina y la suscripción automática al apartar |
| **Sin métrica de drift** spec ↔ código | Consecuencia de descartar `indexion`; mitigado por declarar requisitos en cada PR (`REQ-QAX-006` RB-7) |
