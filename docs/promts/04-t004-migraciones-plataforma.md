# PROMPT 04 — `T-004` · Migraciones bloque 1: plataforma

> Hito **H0 · Cimientos** · Tarea `T-004` · Fase `AB` · 2 pts
> Depende de `T-001` (`6731492`), `T-002` (`46adb8a`) y `T-003` (`4e908f4`): stack en Docker, 148 tests en verde, PHPStan nivel 6 limpio.

## 0. Qué vas a hacer

Las **seis primeras tablas** del esquema: las de la plataforma, las que no dependen de nadie. Son el suelo sobre el que se construyen las otras treinta.

Esta tarea es casi toda esquema. Lo que la hace delicada no es escribirla, es que **un error aquí se propaga a las 83 tareas siguientes** y se corrige con una migración de alteración en vez de con una edición.

## 1. Lee primero, en este orden

| Documento | Para qué |
|---|---|
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-004` | alcance, "hecho cuando" y el **ajuste sobre el plan original** (el modelo `Organization` se adelanta aquí) |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§4** | el encabezado con las **convenciones de columna** y la tabla del **bloque 1** con columnas e índices exactos. Lee también los bloques 2 y 3 para ver quién va a apuntar a estas tablas |
| [docs/specs/02-spec-consolidada.md](../specs/02-spec-consolidada.md) **§5** | el modelo de datos canónico: la distinción entre **tablas de plataforma** y **tablas del tenant** es lo que hace que este bloque exista |

Requisitos que dan el porqué de columnas concretas, para consultar puntualmente y no de corrido:

- `REQ-SUB-001` (en [50-planes-y-suscripciones.md](../specs/50-planes-y-suscripciones.md)) — los límites de `plans` y qué significa `NULL`.
- `REQ-ACC-001` (en [15-cuentas-de-cliente-y-feed.md](../specs/15-cuentas-de-cliente-y-feed.md)) — **por qué `accounts` no lleva documento ni teléfono**.
- `REQ-FX-002` y `REQ-FX-003` (en [13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md)) — por qué `exchange_rates` lleva **dos** marcas de tiempo.
- `REQ-ORG-001` y `REQ-ORG-008` (en [04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md)) — `organizations.status`, `suspended_at`, `deleted_at`.
- `REQ-PLT-001` y `REQ-PLT-006` (en [51-backoffice-de-plataforma.md](../specs/51-backoffice-de-plataforma.md)) — `platform_users` y `platform_settings`.

## 2. Alcance

**Sí:**

1. Las **seis migraciones** del bloque 1, en el orden de la tabla de §4: `platform_users`, `plans`, `organizations`, `exchange_rates`, `accounts`, `platform_settings`. El orden importa: `platform_settings.updated_by` apunta a `platform_users`.
2. Las **convenciones del encabezado de §4**, sin excepción: importes en `BIGINT` de centavos con sufijo `_cents`, tasas en `DECIMAL(18,8)`, `TIMESTAMP` en UTC, enums de dominio como `VARCHAR` (no `ENUM` de MySQL), `id` en `BIGINT UNSIGNED`.
3. Los **índices exactos** de la tabla, incluida la `PRIMARY(key)` de `platform_settings` — que es una PK de cadena, no un `id` autoincremental.
4. El modelo **`Organization`** y su **factory**, adelantados desde `T-008` (ver §3).
5. Tests de esquema (§5).

**No:**

- **No** crees las tablas de otros bloques, ni siquiera las que parezcan triviales.
- **No** sustituyas todavía la migración de `users` de Laravel: eso es `T-008`, y no puede pasar antes de que exista `organizations`... que es justo lo que esta tarea crea. No te adelantes.
- **No** crees los modelos `PlatformUser`, `Plan`, `Account`, `ExchangeRate` ni `PlatformSetting`. Cada uno nace con su tarea (`T-011`, `T-019`, `T-073`, `T-086`) porque ahí es donde se decide su comportamiento. Aquí solo el esquema y `Organization`.
- **No** siembres datos. `PlanSeeder` es `T-011`, `ExchangeRateSeeder` es `T-020`, `PlatformAdminSeeder` es `T-086`.
- **No** añadas `BelongsToOrganization` a nada: el trait no existe hasta `T-005`.

## 3. El ajuste sobre el plan: el modelo `Organization` viene aquí

El plan original lo ponía en `T-008`. Lo adelanto porque `Organization` **es** el tenant, no está sujeta a él: no lleva `organization_id`, no lleva el trait, y por tanto no depende de `T-005`. No hay dependencia circular.

La razón para moverlo es concreta: `CreatesNeighbourOrganization` (que `T-003` dejó fallando a propósito mientras `App\Models\Organization` no exista) es lo que hace real el gate de aislamiento de `T-007`, y `REQ-QAX-008` declara ese gate innegociable. Dejarlo a medias hasta `T-008` sería tenerlo hueco durante cuatro tareas.

Qué implica:

- `app/Models/Organization.php` con sus casts y el enum `OrganizationStatus` **solo si lo necesitas para el cast**; la matriz de capacidades del estado es `T-009`.
- `database/factories/OrganizationFactory.php` capaz de producir una organización válida con slug único.
- **Rellena el cuerpo de `CreatesNeighbourOrganization`** hasta donde llegue sin `TenantContext`: crear la organización vecina es posible ya; fijar el tenant no.

`User` y `PaymentAccount` se quedan en `T-008`, porque esos sí llevan el trait.

## 4. Cuatro columnas que tienen trampa

- **`plans.max_*` admiten `NULL`, y `NULL` significa ilimitado** (`REQ-SUB-001` RB-2). No es "sin definir" ni `0`. Si alguna queda `NOT NULL DEFAULT 0`, el plan Pro se convierte en un plan sin nada.
- **`exchange_rates` lleva `published_at` y `fetched_at`, y no son lo mismo.** `published_at` es cuándo lo publicó la fuente y es la antigüedad que le importa al negocio; `fetched_at` es cuándo lo leímos nosotros y solo sirve para diagnosticar la ingesta. El índice es `INDEX(source, published_at)`, no sobre `fetched_at`.
- **`accounts` no lleva documento ni teléfono, y es deliberado** (`REQ-ACC-001` RB-2). El documento identifica al cliente **dentro de un negocio** y vive en `customers`; `accounts` es la identidad global de consumidor y su clave es Google. Añadir el documento aquí crearía un registro global de cédulas, que es precisamente lo que el diseño evita.
- **`organizations.slug` es `UNIQUE` global** y es una de las tres excepciones declaradas a la regla de unicidad por organización. Aquí es obvio porque `organizations` no es una tabla del tenant; lo anoto para que no te sorprenda cuando en `T-005` veas la lista blanca.

## 5. Hecho cuando

- [ ] `migrate:fresh` corre limpio dentro del contenedor.
- [ ] Las seis tablas existen con sus columnas e índices exactos según §4.
- [ ] Test: ninguna columna `FLOAT`/`DOUBLE` en el esquema (ya existe en `SchemaInvariantsTest`; verifica que siga verde).
- [ ] Test: **`accounts` no tiene ninguna columna de documento ni de teléfono** (`REQ-ACC-001` RB-2, verificable por esquema).
- [ ] Test: todo importe de dinero es `BIGINT` y termina en `_cents`; toda tasa es `DECIMAL(18,8)`.
- [ ] Test: las `plans.max_*` son nullable.
- [ ] Test: `platform_settings` tiene `key` como clave primaria.
- [ ] `Organization` y su factory existen; `CreatesNeighbourOrganization` crea una organización vecina de verdad.
- [ ] Las recetas de calidad siguen en verde (Pint, PHPStan 6, Pest, ESLint, `tsc`, Vitest).

## 6. Reporta al terminar

1. La salida de `migrate:fresh` y el listado de tablas resultante.
2. Qué tests de esquema añadiste y qué comprueba cada uno.
3. Hasta dónde llegó el cuerpo de `CreatesNeighbourOrganization` y qué le sigue faltando de `T-005`/`T-007`.
4. Cualquier columna de §4 que hayas tenido que interpretar porque la tabla no fijaba tipo o longitud, y qué decidiste.
5. Nada más. No propongas siguientes pasos.
