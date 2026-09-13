# `spec.md` — Especificación Consolidada · Apartar (plataforma)

> **Versión:** 2.0.0 · **Fecha:** 2026-09-10 · **Estado:** ✅ Aprobada
> **Base de decisiones:** [01-asunciones-criticas.md](01-asunciones-criticas.md) (aritmética, comprobantes, infraestructura) + [03-pivote-saas-impacto.md](03-pivote-saas-impacto.md) (tenancy, planes, cuentas de cliente final).
> **Dominio:** `apartar.store` · **Naturaleza:** SaaS multi-tenant que permite a un negocio gestionar inventario, publicar catálogos con sistema de apartado y cobrar en cuotas a sus propios clientes.
>
> Este archivo es el **índice normativo**. Los requisitos viven en módulos atómicos numerados; aquí solo se referencian.

---

## 1. Alcance del producto

Apartar es una plataforma donde **un negocio** (organización) carga su inventario, arma **catálogos compartibles por enlace** y ofrece a sus clientes **apartar** productos: pagar una cuota inicial y amortizar el resto en cuotas periódicas. La deuda se contabiliza **en dólares**; los pagos llegan mayoritariamente en bolívares y se concilian **manualmente** a partir de comprobantes, sin pasarela de pago. La plataforma se monetiza por **suscripción con capa gratuita**.

No hay descubrimiento global: **el cliente final llega siempre por el enlace de un catálogo concreto**. Dentro de ese catálogo sí busca, ordena y filtra.

### 1.1 Las cinco superficies

| Superficie | Ruta base | Audiencia | Guard |
|---|---|---|---|
| **Marketing** | `/` | Público, negocios prospectos | — |
| **Catálogo** | `/c/{catalog-slug}` | Clientes finales y visitantes | — |
| **Portal del cliente** | `/mi-cuenta` | Clientes finales | `account` o `customer` |
| **Backoffice de la organización** | `/backoffice` | Equipo del negocio | `web` |
| **Backoffice de plataforma** | `/plataforma` | Operación de Apartar | `platform` |

Más el canal en tiempo real `wss://ws.apartar.store` para chat y alertas de conciliación.

### 1.2 Fuera de alcance inmediato (global)

Pasarela de pago automática (ni para las suscripciones ni para los apartados) · facturación fiscal / IVA · envíos y logística · cupones y promociones · multi-idioma · motor de recomendación activo (solo se modelan los datos) · app móvil nativa · intereses o penalizaciones por mora · RBAC granular · 2FA · WhatsApp / SMS y OTP telefónico · entorno de staging · pruebas E2E automatizadas · PWA instalable con modo offline · **directorio público de negocios o catálogos** · **registro global de personas por documento** · proveedores de identidad distintos de Google para la cuenta de cliente final.

---

## 2. Actores

| Actor | Tabla | Alcance | Autenticación |
|---|---|---|---|
| `Visitor` | cookie `visitor_id` (UUIDv7, 1 año) | Global | Ninguna |
| `Account` | `accounts` | **Global** | Google OAuth (obligatorio) |
| `Customer` | `customers` | **Una organización** | Documento + código de apartado, magic link por email, o vínculo con una `Account` |
| `OrganizationUser` | `users` | **Una organización** | Email + contraseña, rol `owner` \| `member` |
| `PlatformAdmin` | `platform_users` | Plataforma | Email + contraseña |
| `System` | — | — | Scheduler y colas |

### 2.1 Las dos identidades del cliente final

Es la distinción central del modelo y la fuente de la mitad de las reglas de negocio:

| | `Customer` — identidad **comercial** | `Account` — identidad de **consumidor** |
|---|---|---|
| Representa | Una deuda con un negocio concreto | Una persona que navega la plataforma |
| Clave | `(organization_id, document_number)` | `account_identities(provider, provider_id)` |
| Alcance | Una organización | Global |
| Datos | Documento, teléfono, nombre; email opcional | Nombre, email de Google, avatar. **Sin documento ni teléfono** |
| Posee | Apartados, cuotas, comprobantes, pagos, balance, hilo de chat | Suscripciones a catálogos, feed, preferencias de aviso |
| Obligatoria para | Apartar | Suscribirse a un catálogo y usar el feed |

Vínculo: `customers.account_id` nullable, `UNIQUE(organization_id, account_id)`. Una cuenta se vincula con **como máximo una** ficha por organización y con N organizaciones en total. Detalle en [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md).

---

## 3. Glosario (ES → identificador canónico EN)

| Término de negocio | Identificador canónico | Notas |
|---|---|---|
| Organización / negocio | `Organization` | El tenant |
| Equipo de la organización | `User` (`OrganizationRole`) | `owner` \| `member` |
| Plan | `Plan` | Free / Pro / Business |
| Suscripción | `Subscription` | Una por organización |
| Consumo | `UsageCounter` | Valor materializado por métrica |
| Cuenta de cobro | `PaymentAccount` | Dónde le pagan a la organización |
| Cuenta de consumidor | `Account` | Identidad global vía Google |
| Suscripción a catálogo | `CatalogSubscription` | `Account` ↔ `Catalog` |
| Novedades / feed | `Feed`, `FeedItem` | Productos de catálogos seguidos |
| Cliente | `Customer` | Ficha comercial dentro de una organización |
| Apartado | `Layaway` | Contrato de reserva con plan de pagos |
| Cuota | `Installment` | Fila del cronograma |
| Cuota inicial | `down_payment_cents` | No es una `Installment` |
| Cronograma | `AmortizationSchedule` | Colección de `Installment` |
| Comprobante | `Receipt` | Declaración del cliente, sujeta a revisión |
| Pago conciliado | `Payment` | Nace al aprobar un `Receipt` o por registro manual |
| Aplicación del pago | `PaymentAllocation` | Trazabilidad `Payment` → `Installment` |
| Saldo pendiente | `remaining_cents` | Centavos de USD |
| Mora | `overdue` | Estado, no un recargo |
| Ficha técnica | `ProductSpecification` | Pares `label` / `value` ordenados |
| Catálogo | `Catalog` | Colección compartible por `slug` |
| Tasa de cambio | `ExchangeRate` | `bcv` \| `parallel` \| `manual` |
| Acceso de soporte | `SupportAccessGrant` | Acceso temporal y auditado de plataforma |
| Evento de telemetría | `TelemetryEvent` | Catálogo cerrado de `event_name` |

**Regla de idioma:** narrativa en español; **toda** entidad, campo, tipo, enum, ruta, clase y función en inglés. El artefacto que consumen los gates es el espejo [.align/requirements.en.md](.align/requirements.en.md) (`REQ-QAX-005`).

---

## 4. Índice de módulos

| Archivo | Módulo | IDs | N |
|---|---|---|---|
| [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md) | Tenancy, organizaciones y equipo | `REQ-ORG-001…008` | 8 |
| [10-identidad-y-autenticacion.md](10-identidad-y-autenticacion.md) | Identidad del cliente y autenticación | `REQ-IDN-001…008` | 8 |
| [11-catalogo-y-productos.md](11-catalogo-y-productos.md) | Productos, taxonomía y ficha técnica | `REQ-CAT-002…007` | 6 |
| [12-catalogos-curados.md](12-catalogos-curados.md) | El catálogo como tienda | `REQ-CAT-021…023` | 3 |
| [13-bimoneda-y-tasa-de-cambio.md](13-bimoneda-y-tasa-de-cambio.md) | Bimoneda, `Money` y tasa de cambio | `REQ-FX-001…006` | 6 |
| [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md) | Cuentas de consumidor, suscripción y feed | `REQ-ACC-001…007` | 7 |
| [20-apartado-y-cronograma.md](20-apartado-y-cronograma.md) | Apartado, estados y amortización | `REQ-LAY-001…011` | 11 |
| [21-portal-del-cliente.md](21-portal-del-cliente.md) | Portal privado del cliente | `REQ-LAY-020…023` | 4 |
| [22-comprobantes-y-conciliacion.md](22-comprobantes-y-conciliacion.md) | Comprobantes, pagos y balances | `REQ-PAY-001…009` | 9 |
| [30-backoffice-accesos-y-usuarios.md](30-backoffice-accesos-y-usuarios.md) | Acceso del equipo y auditoría | `REQ-BOF-001`, `REQ-BOF-005` | 2 |
| [31-backoffice-inventario.md](31-backoffice-inventario.md) | Inventario, fichas y catálogos | `REQ-BOF-020…024` | 5 |
| [32-backoffice-clientes-y-cobranza.md](32-backoffice-clientes-y-cobranza.md) | Clientes, cobranza y exportaciones | `REQ-BOF-040…045` | 6 |
| [40-notificaciones.md](40-notificaciones.md) | Web Push y email transaccional | `REQ-NOT-001…005` | 5 |
| [41-chat-soporte.md](41-chat-soporte.md) | Chat cliente ↔ negocio | `REQ-CHT-001…005` | 5 |
| [42-telemetria.md](42-telemetria.md) | Telemetría e interacciones | `REQ-TLM-001…005` | 5 |
| [50-planes-y-suscripciones.md](50-planes-y-suscripciones.md) | Planes, límites y facturación | `REQ-SUB-001…008` | 8 |
| [51-backoffice-de-plataforma.md](51-backoffice-de-plataforma.md) | Backoffice de plataforma | `REQ-PLT-001…006` | 6 |
| [90-infraestructura-y-entorno.md](90-infraestructura-y-entorno.md) | Docker, Traefik, colas, Reverb | `REQ-INF-001…006` | 6 |
| [91-calidad-y-gates.md](91-calidad-y-gates.md) | Estándares, pruebas y gates SDD | `REQ-QAX-001…008` | 8 |
| | | **Total** | **118** |

Orden de lectura recomendado: `04` → `50` → `10` → `15` → `11` → `12` → `13` → `20` → `21` → `22` → `30`…

---

## 5. Modelo de datos canónico (MySQL 8.0)

Convenciones duras:

- Todo importe es `BIGINT` en **centavos de USD**, sufijo `_cents`. `FLOAT`/`DOUBLE` prohibido en el esquema.
- Toda tasa de cambio es `DECIMAL(18,8)`.
- Toda fecha/hora es `TIMESTAMP` en UTC; la presentación usa la zona de la organización (`America/Caracas` por defecto).
- Los enums de dominio se persisten como `VARCHAR` respaldados por un `enum` de PHP.
- `id` es `BIGINT UNSIGNED AUTO_INCREMENT`; lo expuesto públicamente es `slug` o `code`, nunca el `id`.
- **Toda tabla del tenant lleva `organization_id` y todo índice único de negocio lo incluye.** Sin excepción.

### 5.1 Tablas de plataforma (sin `organization_id`)

| Tabla | Columnas clave | Índices |
|---|---|---|
| `platform_users` | `name`, `email`, `password`, `disabled_at`, `must_change_password` | `UNIQUE(email)` |
| `platform_settings` | `key` (PK), `value JSON`, `updated_by` | `PRIMARY(key)` |
| `organizations` | `name`, `slug`, `document?` (RIF), `timezone`, `logo_path?`, `brand_color?`, `storefront_enabled`, `status`, `suspended_at?`, `deleted_at?` | `UNIQUE(slug)`, `INDEX(status)` |
| `plans` | `code`, `name`, `price_cents`, `interval`, `is_public`, `position`, `max_users`, `max_catalogs`, `max_products`, `max_products_per_catalog`, `max_active_layaways`, `max_storage_mb`, `max_payment_accounts`, `features JSON` | `UNIQUE(code)` |
| `subscriptions` | `organization_id`, `plan_id`, `status`, `started_at`, `current_period_start`, `current_period_end`, `grace_ends_at?`, `cancelled_at?` | `UNIQUE(organization_id)` |
| `subscription_invoices` | `organization_id`, `plan_id`, `period_start`, `period_end`, `amount_cents`, `status`, `due_date`, `paid_at?` | `INDEX(organization_id, due_date)`, `INDEX(status)` |
| `subscription_receipts` | `invoice_id`, `payment_method`, `bank?`, `reference?`, `declared_amount`, `declared_currency`, `paid_at`, `file_path`, `file_hash`, `status`, `reviewed_by?`, `reviewed_at?`, `rejection_reason?` | `UNIQUE(file_hash)`, `INDEX(status)` |
| `usage_counters` | `organization_id`, `metric`, `value`, `computed_at` | `UNIQUE(organization_id, metric)` |
| `exchange_rates` | `source`, `rate DECIMAL(18,8)`, `fetched_at` | `INDEX(source, fetched_at)` |
| `accounts` | `display_name`, `email`, `avatar_url?`, `locale`, `last_login_at?` | `INDEX(email)` |
| `account_identities` | `account_id`, `provider`, `provider_id`, `email` | `UNIQUE(provider, provider_id)` |
| `catalog_subscriptions` | `account_id`, `catalog_id`, `subscribed_at`, `muted_at?` | `UNIQUE(account_id, catalog_id)`, `INDEX(catalog_id)` |
| `catalog_digest_dispatches` | `catalog_id`, `account_id`, `digest_date`, `products_count`, `sent_at` | `UNIQUE(catalog_id, account_id, digest_date)` |
| `push_subscriptions` | `notifiable_type`, `notifiable_id` (`Account` \| `Customer`), `endpoint`, `public_key`, `auth_token`, `content_encoding`, `last_used_at` | `UNIQUE(endpoint)`, `INDEX(notifiable_type, notifiable_id)` |
| `support_access_grants` | `platform_user_id`, `organization_id`, `reason`, `granted_at`, `expires_at`, `revoked_at?` | `INDEX(organization_id, expires_at)` |
| `platform_activity_log` | tabla de auditoría de plataforma | — |

> `accounts` **no** contiene documento ni teléfono. Es una identidad de navegación, no comercial.

### 5.2 Tablas del tenant (`organization_id` obligatorio)

**Equipo, ajustes y cobro**

| Tabla | Columnas clave | Índices |
|---|---|---|
| `users` | `organization_id`, `name`, `email`, `password`, `role`, `disabled_at?`, `must_change_password`, `invited_at?` | `UNIQUE(email)`, `INDEX(organization_id, role)` |
| `organization_settings` | `organization_id`, `key`, `value JSON`, `updated_by` | `UNIQUE(organization_id, key)` |
| `payment_accounts` | `organization_id`, `method`, `bank?`, `holder_name`, `holder_document`, `identifier`, `instructions?`, `is_active`, `position` | `INDEX(organization_id, is_active)` |
| `activity_log` | `organization_id` + tabla de `spatie/laravel-activitylog` | `INDEX(organization_id, created_at)` |

**Clientes**

| Tabla | Columnas clave | Índices |
|---|---|---|
| `customers` | `organization_id`, `document_number`, `full_name`, `phone`, `email?`, `account_id?`, `terms_accepted_at`, `terms_ip_address`, `last_login_at?` | `UNIQUE(organization_id, document_number)`, `UNIQUE(organization_id, account_id)`, `INDEX(organization_id, phone)` |
| `customer_login_tokens` | `organization_id`, `customer_id`, `token_hash`, `expires_at`, `consumed_at?`, `requested_ip` | `UNIQUE(token_hash)` |

**Catálogo**

| Tabla | Columnas clave | Índices |
|---|---|---|
| `categories` | `organization_id`, `name`, `slug`, `position` | `UNIQUE(organization_id, slug)` |
| `tags` | `organization_id`, `name`, `slug` | `UNIQUE(organization_id, slug)` |
| `products` | `organization_id`, `category_id`, `sku`, `name`, `slug`, `description`, `price_cents`, `compare_at_price_cents?`, `down_payment_cents`, `installments_count`, `frequency_days`, `stock`, `status`, `published_at?`, `deleted_at?` | `UNIQUE(organization_id, sku)`, `UNIQUE(organization_id, slug)`, `INDEX(organization_id, status)`, `FULLTEXT(name, description, sku)` |
| `product_tag` | `product_id`, `tag_id` | `PRIMARY(product_id, tag_id)` |
| `product_specifications` | `organization_id`, `product_id`, `group?`, `label`, `value`, `position` | `INDEX(product_id, position)` |
| `media` | tabla de `spatie/laravel-medialibrary` | — |
| `catalogs` | `organization_id`, `name`, `slug`, `description?`, `is_listed`, `published_at?`, `expires_at?` | **`UNIQUE(slug)` global** (Q4), `INDEX(organization_id, published_at)` |
| `catalog_product` | `catalog_id`, `product_id`, `position`, `created_at` | `PRIMARY(catalog_id, product_id)`, `INDEX(catalog_id, created_at)` |

> `catalogs.slug` es el **único** índice único del tenant sin `organization_id`: es la URL pública compartida y debe ser irrepetible en toda la plataforma.

**Apartado y cobranza**

| Tabla | Columnas clave | Índices |
|---|---|---|
| `layaways` | `organization_id`, `code`, `customer_id`, `product_id`, `catalog_id?`, `quantity`, `unit_price_cents`, `down_payment_cents`, `installments_count`, `frequency_days`, `product_name_snapshot`, `product_sku_snapshot`, `total_cents`, `paid_cents`, `remaining_cents`, `status`, `expires_at?`, `extended_at?`, `activated_at?`, `next_due_date?`, `completed_at?`, `cancelled_at?`, `cancellation_reason?`, `delivered_at?`, `stock_released_at?` | **`UNIQUE(code)` global**, `INDEX(organization_id, status, next_due_date)`, `INDEX(organization_id, customer_id)` |
| `installments` | `organization_id`, `layaway_id`, `number`, `due_date`, `amount_cents`, `paid_cents`, `status`, `paid_at?` | `UNIQUE(layaway_id, number)`, `INDEX(organization_id, status, due_date)` |
| `receipts` | `organization_id`, `layaway_id`, `customer_id`, `payment_account_id?`, `payment_method`, `bank?`, `reference?`, `paid_at`, `declared_amount`, `declared_currency`, `note?`, `file_path`, `file_hash`, `file_mime`, `file_size`, `status`, `reviewed_by?`, `reviewed_at?`, `rejection_reason_code?`, `rejection_reason?`, `replaces_receipt_id?` | `UNIQUE(organization_id, file_hash)`, `UNIQUE(organization_id, bank, reference)`, `INDEX(organization_id, status, created_at)` |
| `payments` | `organization_id`, `layaway_id`, `receipt_id?`, `amount_cents_usd`, `declared_currency`, `declared_amount`, `rate_applied`, `rate_source`, `rate_fetched_at?`, `settled_at`, `registered_by?`, `note?` | `UNIQUE(receipt_id)`, `INDEX(organization_id, settled_at)` |
| `payment_allocations` | `payment_id`, `installment_id`, `amount_cents` | `INDEX(payment_id)`, `INDEX(installment_id)` |

> `layaways.code` es único global por la misma razón que el slug: es la prueba de acceso al portal (`REQ-IDN-008`) y no puede colisionar entre organizaciones.
> `receipts` cambia respecto a v1: la unicidad de `file_hash` y de `(bank, reference)` es **por organización**. Dos negocios distintos pueden legítimamente recibir la misma referencia bancaria.

**Comunicación y datos**

| Tabla | Columnas clave | Índices |
|---|---|---|
| `conversations` | `organization_id`, `customer_id`, `last_message_at?`, `customer_unread_count`, `admin_unread_count` | `UNIQUE(organization_id, customer_id)`, `INDEX(organization_id, last_message_at)` |
| `messages` | `organization_id`, `conversation_id`, `sender_type`, `sender_id`, `body`, `read_at?` | `INDEX(conversation_id, id)` |
| `notification_dispatches` | `organization_id`, `notifiable_type`, `notifiable_id`, `installment_id?`, `type`, `channel`, `sent_at` | `UNIQUE(installment_id, type, channel)` |
| `telemetry_events` | `organization_id?`, `catalog_id?`, `event_name`, `visitor_id`, `account_id?`, `customer_id?`, `subject_type?`, `subject_id?`, `payload JSON`, `session_id`, `ip_hash`, `user_agent`, `occurred_at` | `INDEX(event_name, occurred_at)`, `INDEX(organization_id, occurred_at)`, `INDEX(catalog_id, occurred_at)`, `INDEX(account_id)` |
| `telemetry_monthly_aggregates` | `organization_id?`, `event_name`, `year_month`, `count` | `UNIQUE(organization_id, event_name, year_month)` |
| `export_jobs` | `organization_id`, `user_id`, `type`, `filters JSON`, `status`, `file_path?`, `rows_count?`, `error_message?`, `expires_at?` | `INDEX(organization_id, created_at)` |

> `telemetry_events.organization_id` es nullable porque los eventos del feed y de la cuenta no pertenecen a ninguna organización.

### 5.3 Relaciones (ERD textual)

```
PLATAFORMA
  Plan 1──N Subscription 1──1 Organization
  Organization 1──N SubscriptionInvoice 1──N SubscriptionReceipt
  Organization 1──N UsageCounter
  PlatformUser 1──N SupportAccessGrant N──1 Organization
  Account 1──N AccountIdentity
  Account 1──N CatalogSubscription N──1 Catalog
  Account 1──N PushSubscription  (polimórfico con Customer)

TENANT  (todo bajo Organization)
  Organization 1──N User | PaymentAccount | OrganizationSetting
  Organization 1──N Category 1──N Product N──N Tag
  Product 1──N ProductSpecification | Media
  Product N──N Catalog  (catalog_product, con position y created_at)

  Organization 1──N Customer 0..1──1 Account        ← el puente entre las dos identidades
  Customer 1──N Layaway N──1 Product
  Layaway 0..1──1 Catalog        ← catálogo de origen, para telemetría y contexto
  Layaway 1──N Installment | Receipt | Payment
  Receipt 0..1──1 PaymentAccount
  Payment 0..1──1 Receipt
  Payment 1──N PaymentAllocation N──1 Installment

  Organization 1──N Conversation 1──1 Customer
  Conversation 1──N Message
```

---

## 6. Arquitectura de código

### 6.1 Backend (Laravel 12, PHP 8.3)

```
app/
├── Domain/
│   ├── Tenancy/          Organization, OrganizationRole, TenantContext,
│   │                     BelongsToOrganization (trait + global scope), TenantResolver
│   ├── Subscription/     Plan, PlanLimit, UsageMetric, LimitEnforcer, UsageCalculator,
│   │                     SubscriptionStatus, PlanFeature
│   ├── Account/          Account (VO de contexto), CatalogSubscriptionPolicy, FeedQuery
│   ├── Money/            Money, Currency, MoneyFormatter
│   ├── Exchange/         ExchangeRateProvider, DolarApiProvider, ManualRateProvider,
│   │                     ExchangeRateService, RateSnapshot, RateSource
│   ├── Layaway/          LayawayStatus, InstallmentStatus, ScheduleGenerator,
│   │                     AmortizationSchedule, LayawayStateMachine, LayawayBalanceRecalculator
│   ├── Payment/          PaymentMethod, ReceiptStatus, RejectionReasonCode,
│   │                     FifoAllocator, AllocationPlan, PaymentApplier
│   ├── Catalog/          ProductStatus, CatalogVisibility
│   ├── Notification/     NotificationType
│   └── Telemetry/        TelemetryEventName, EventSanitizer
├── Actions/
│   ├── Organization/     RegisterOrganizationAction, UpdateOrganizationAction,
│   │                     InviteTeamMemberAction, DisableTeamMemberAction,
│   │                     UpsertPaymentAccountAction, SuspendOrganizationAction
│   ├── Subscription/     StartFreeSubscriptionAction, ChangePlanAction,
│   │                     IssueInvoiceAction, ApproveSubscriptionReceiptAction,
│   │                     ApplyDowngradeAction, RecalculateUsageAction
│   ├── Account/          AuthenticateAccountAction, SubscribeToCatalogAction,
│   │                     UnsubscribeFromCatalogAction, ClaimCustomerAction, LinkAccountAction
│   ├── Customer/         ResolveCustomerAction, MergeCustomersAction, IssueLoginLinkAction,
│   │                     ConsumeLoginTokenAction, StartScopedSessionAction
│   ├── Layaway/          CreateLayawayAction, ActivateLayawayAction, ExpireLayawaysAction,
│   │                     CancelLayawayAction, RescheduleInstallmentAction, MarkDeliveredAction,
│   │                     ReserveStockAction, ReleaseStockAction, DetectOverdueAction
│   ├── Payment/          SubmitReceiptAction, ApproveReceiptAction, RejectReceiptAction,
│   │                     RegisterManualPaymentAction, ReallocatePaymentAction
│   ├── Product/          UpsertProductAction, SyncSpecificationsAction, SyncImageOrderAction
│   ├── Catalog/          PublishCatalogAction, SyncCatalogProductsAction
│   ├── Notification/     DispatchDueRemindersAction, SendManualPushAction,
│   │                     DispatchCatalogDigestAction
│   ├── Chat/             SendMessageAction
│   └── Platform/         GrantSupportAccessAction, RevokeSupportAccessAction
├── Http/
│   ├── Controllers/Marketing/    landing, precios, registro
│   ├── Controllers/Catalog/      catálogo público, producto, apartar, suscribirse
│   ├── Controllers/Portal/       dashboard, apartados, comprobantes, feed, chat
│   ├── Controllers/Backoffice/   panel de la organización
│   ├── Controllers/Platform/     panel de plataforma
│   ├── Controllers/Api/          tasa, telemetría
│   ├── Requests/                 Form Requests
│   ├── Resources/
│   └── Middleware/               ResolveTenant, ResolveCatalogTenant, EnsurePortalSession,
│                                 EnforcePlanLimit, AssignVisitorId, EnsureAdminIsActive
├── Jobs/, Models/, Notifications/, Policies/, Queries/
└── Support/                      Settings, SignedFileUrl, DocumentNumber, PhoneNumber,
                                  LayawayCode, IpHasher, NavigationCategories
```

Reglas arquitectónicas obligatorias:

1. **Aislamiento por defecto.** Todo modelo del tenant usa el trait `BelongsToOrganization`, que aplica un *global scope* por `organization_id` y rellena la columna al crear. Quitar el scope exige `withoutTenantScope()` explícito, permitido **solo** en jobs de plataforma y comandos de mantenimiento.
2. **Thin controllers.** Un método recibe un `FormRequest`, invoca **una** `Action` inyectada por constructor y devuelve `Inertia::render(...)` o `JsonResponse`. Sin queries ni `if` de negocio.
3. **Actions transaccionales.** Toda mutación de más de una tabla va en `DB::transaction()`, con bloqueo pesimista donde la spec lo exija.
4. **Modelos anémicos.** `app/Models` solo relaciones, casts, scopes y accesores triviales.
5. **Sin facades en `app/Domain`.** El dominio se prueba sin arrancar el framework.
6. **Los límites de plan se comprueban en la `Action`, no en el controlador**, mediante `LimitEnforcer` inyectado.
7. **PHPDoc obligatorio** en clase, interfaz, enum, método público y propiedad promovida.
8. **Colas** para: exportaciones, telemetría, Web Push, digests de catálogo, conversiones de imagen, ingesta de tasa, recálculo de consumo y reconciliación.

### 6.2 Frontend (React 19 + TypeScript estricto + Inertia 2)

```
resources/js/
├── pages/
│   ├── marketing/     Home, Pricing, Register
│   ├── catalog/       CatalogShow, ProductShow, LayawayCreate, OrgStorefront
│   ├── portal/        Dashboard, Feed, Subscriptions, LayawayShow, ReceiptCreate, Access, Claim
│   ├── backoffice/    Dashboard, products/*, catalogs/*, customers/*, collections/*,
│   │                  receipts/*, chat/*, team/*, payment-accounts/*, subscription/*, settings/*
│   └── platform/      Dashboard, organizations/*, subscriptions/*, support/*
├── features/
│   ├── tenancy/       hooks/useOrganization, components/OrgBrandProvider
│   ├── plan/          hooks/usePlanLimits, components/LimitBanner, components/UpgradePrompt
│   ├── account/       hooks/useAccountSession, components/FollowCatalogButton, components/FeedList
│   ├── currency/      hooks/useCurrencyDisplay, components/MoneyLabel, components/CurrencyToggle
│   ├── catalog/       components/, hooks/useCatalogFilters
│   ├── layaway/       components/, hooks/
│   ├── receipts/      components/, hooks/
│   ├── chat/          components/, hooks/, lib/echo
│   ├── telemetry/     hooks/useTelemetry, lib/buffer
│   ├── collections/   hooks/useCollectionFilters
│   └── data-table/    components/DataTable, hooks/useServerTable
├── components/ui/     átomos daisyUI tipados
├── layouts/           MarketingLayout, CatalogLayout, PortalLayout, BackofficeLayout, PlatformLayout
├── lib/               money.ts, http.ts, echo.ts, identity-format.ts
└── types/             domain.ts, inertia.d.ts
```

Reglas obligatorias: ningún componente supera **200 líneas** (objetivo 150, regla ESLint como error); la lógica vive en custom hooks; `strict: true` + `noUncheckedIndexedAccess`, sin `any`; `@tanstack/react-table` solo en backoffice y plataforma en modo manual; `@tanstack/react-form` en los formularios complejos; `@tanstack/react-query` **solo** para tasa, chat, badge de bandeja y paginación del feed; daisyUI envuelto en `components/ui`; mobile-first desde 375 px; TSDoc obligatorio.

---

## 7. Contratos transversales

```php
/** Importe monetario inmutable en centavos. Toda aritmética monetaria pasa por aquí. */
final readonly class Money
{
    public function __construct(public int $cents, public Currency $currency = Currency::USD) {}

    public function add(self $other): self { /* … */ }
    public function subtract(self $other): self { /* … */ }
    public function multiply(int $factor): self { /* … */ }

    /** @return list<self> Reparte el importe en $parts, con el residuo en la última parte. */
    public function allocateEvenly(int $parts): array { /* … */ }

    public function convertTo(Currency $currency, RateSnapshot $rate): self { /* … */ }
    public function isZeroOrBelowTolerance(int $toleranceCents): bool { /* … */ }
}

/** Organización activa de la petición. Resuelta por middleware, inyectable en cualquier parte. */
final class TenantContext
{
    public function organization(): Organization { /* … */ }
    public function organizationId(): int { /* … */ }
    public function hasOrganization(): bool { /* … */ }
    public function setOrganization(Organization $organization): void { /* … */ }
}

/** Identidad del portal: cuenta global, ficha acotada, o ambas. */
final readonly class PortalContext
{
    public function __construct(
        public ?Account $account,
        public ?Customer $scopedCustomer,
    ) {}

    /** @return Collection<int, Customer> Fichas visibles para esta sesión. */
    public function customers(): Collection { /* … */ }
    public function isScoped(): bool { /* … */ }
    public function canUseFeed(): bool { /* … */ }
}
```

```ts
/** Centavos de USD. Nunca se representa un importe como decimal. */
export type CentsUsd = number & { readonly __brand: 'CentsUsd' };

/** Importe listo para presentación bimoneda. */
export interface MoneyView {
  readonly cents: CentsUsd;
  readonly usd: string;          // "$20.00"
  readonly ves: string | null;   // "Bs. 850,00" — null si no hay tasa
}

export type CurrencyDisplay = 'usd_primary' | 'ves_primary';

/** Identidad de la organización presente en toda página del catálogo y del backoffice. */
export interface OrganizationBrand {
  readonly name: string;
  readonly slug: string;
  readonly logoUrl: string | null;
  readonly brandColor: string | null;
  /** false en plan Free: se muestra el sello "Hecho con Apartar". */
  readonly brandingEnabled: boolean;
}

/** Estado del plan, presente en toda página del backoffice. */
export interface PlanState {
  readonly planCode: 'free' | 'pro' | 'business';
  readonly status: 'active' | 'past_due' | 'grace' | 'suspended' | 'cancelled';
  readonly usage: Readonly<Record<UsageMetric, { used: number; limit: number | null }>>;
  readonly features: readonly PlanFeature[];
}

export type UsageMetric =
  | 'users' | 'catalogs' | 'products' | 'active_layaways'
  | 'storage_mb' | 'payment_accounts';

export type PlanFeature =
  | 'branding' | 'excel_export' | 'web_push' | 'subscriber_digest'
  | 'full_analytics' | 'custom_domain';
```

---

## 8. Definición de "hecho" y gates

> **Cambio de esta versión:** `indexion` queda **descartado**. Los gates dejan de ser una comprobación de vocabulario por herramienta y pasan a ser **criterios de aceptación verificables con Pest, Vitest, PHPStan y un comando de Artisan propio**. Se pierde la métrica automática de *drift* documental; se gana que **todos los gates son ejecutables desde el primer día** en lugar de depender de instalar algo.

### 8.1 Qué significa "hecho" para un requisito

Las cuatro líneas de la sección *Gate de validación* de cada uno de los 118 requisitos son su definición de hecho, y se leen así:

| Línea | Qué exige |
|---|---|
| **Superficie esperada** | Los archivos declarados existen y contienen lo que el requisito describe. Es la lista de entregables. |
| **Tipos esperados** | Las clases, enums, VOs, excepciones y tipos TS existen con ese nombre exacto. Es el contrato de nombres. |
| **Anti-`SHALLOW`** | **Existe lógica real, no solo tipos.** Nombra el método concreto que debe tener el cuerpo no trivial. Un requisito con los tipos creados y los métodos vacíos **no está hecho**. |
| **Tests** | Los casos listados pasan. Es la prueba de que la lógica hace lo que dice. |

La etiqueta `Anti-SHALLOW` se conserva —aunque ya no exista la herramienta que la inventó— porque nombra un fallo real y frecuente: dar por terminado un módulo que solo tiene el andamiaje. Ahora se verifica en revisión y por los tests que la acompañan, no por un comando.

### 8.2 Fases de implementación

| Fase | Contenido | Criterio de cierre |
|---|---|---|
| **A — Tipos y estructuras** | enums, VOs, DTOs, migraciones, interfaces, firmas de `Action`, tipos TS, andamiaje de tests | `phpstan` y `tsc` limpios; las migraciones corren; *Tipos esperados* completos. `Anti-SHALLOW` **tolerado**: los métodos aún están vacíos. |
| **B — Lógica** | cuerpos de `Action`, dominio, jobs, componentes, tests reales | Todos los *Tests* del requisito pasan y su `Anti-SHALLOW` está resuelto. |

Esta división sigue siendo la que ordena el plan de tareas ([06-plan-de-tareas.md](06-plan-de-tareas.md)): primero el esqueleto tipado de un módulo completo, después su lógica.

### 8.3 Gates automáticos que sí existen

Los tres jobs de CI de `REQ-QAX-004`:

| Job | Verifica |
|---|---|
| `backend` | Pint, PHPStan nivel 6, Pest con umbral de cobertura del dominio |
| `frontend` | ESLint (incluida la regla de 200 líneas), `tsc --noEmit`, Vitest, `vite build` |
| `tenancy` | `php artisan tenancy:audit` + la suite `tests/Feature/Tenancy` |

**El gate que no puede fallar nunca es `tenancy`** (`REQ-QAX-008`): aislamiento multi-tenant verificado por test de fuga cruzada en cada endpoint, más auditoría estática del esquema. **No dependía de `indexion`**, así que la decisión de descartarlo no lo afecta en absoluto.

### 8.4 Qué pasa con el espejo en inglés

[.align/requirements.en.md](.align/requirements.en.md) se creó para alimentar `spec align`. Sin esa herramienta pierde a su único consumidor automático, pero **conserva un valor real**: es el único lugar donde están, en un solo archivo y en inglés, los **identificadores esperados de los 118 requisitos**. Sirve como contrato de nombres para el equipo y como contexto compacto para trabajo asistido por IA. `REQ-QAX-005` se reencuadra en consecuencia: deja de ser un artefacto que hay que mantener sincronizado por obligación y pasa a actualizarse **solo cuando cambia un identificador público**.

---

## 9. Ajustes

### 9.1 `organization_settings` (por tenant, edita `owner`)

| `key` | Tipo | Default | Requisito |
|---|---|---|---|
| `exchange_rate_source` | `bcv \| parallel \| manual` | `bcv` | `REQ-FX-003` |
| `exchange_rate_manual` | `decimal?` | `null` | `REQ-FX-003` |
| `currency_display_default` | `usd_primary \| ves_primary` | `usd_primary` | `REQ-FX-004` |
| `grace_period_days` | `int` | `3` | `REQ-LAY-007` |
| `down_payment_window_hours` | `int` | `72` | `REQ-LAY-006` |
| `settlement_tolerance_cents` | `int` | `50` | `REQ-FX-005` |
| `default_frequency_days` | `int` | `15` | `REQ-LAY-004` |
| `default_installments_count` | `int` | `4` | `REQ-BOF-020` |
| `reminder_offsets_days` | `list<int>` | `[-3, 0, 1, 7, 15]` | `REQ-NOT-002` |
| `overdue_alert_days` | `int` | `30` | `REQ-LAY-009` |

> **`storefront_enabled` no es un ajuste: es la columna `organizations.storefront_enabled`.** Estuvo duplicada en ambos sitios hasta `T-010`, que lo detectó. Manda la columna, por dos razones: la vitrina pública `/{org-slug}` la consulta en **cada petición anónima** (`REQ-ORG-006` RB-6) y una columna se filtra en la misma consulta que resuelve la organización, sin pasar por la caché de ajustes ni por su respaldo en BD; y encender o apagar la vitrina es **identidad** de la organización, como el logo o el slug, no un parámetro de negocio como la tolerancia de liquidación. `T-014` retira la clave del enum y las filas ya sembradas.

### 9.2 `platform_settings` (globales, edita `PlatformAdmin`)

| `key` | Tipo | Default | Requisito |
|---|---|---|---|
| `telemetry_retention_days_default` | `int` | `365` | `REQ-TLM-005` |
| `export_link_ttl_hours` | `int` | `24` | `REQ-BOF-043` |
| `receipt_max_size_mb` | `int` | `5` | `REQ-PAY-002` |
| `subscription_grace_days` | `int` | `7` | `REQ-SUB-007` |
| `downgrade_grace_days` | `int` | `7` | `REQ-SUB-008` |
| `support_access_ttl_hours` | `int` | `8` | `REQ-PLT-005` |
| `digest_min_products` | `int` | `1` | `REQ-ACC-006` |
| `digest_max_per_day` | `int` | `1` | `REQ-ACC-006` |
