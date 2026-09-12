# PROMPT 06 — `T-008` · Migraciones bloque 3 + modelos del tenant

> Hito **H0 · Cimientos** · Tarea `T-008` · Fase `AB` · 2 pts
> Depende de `T-004` (`036303e`) y `T-005` (`93fca2b`): seis tablas de plataforma, `TenantContext`, `BelongsToOrganization`, `TenantScope`. 268 tests en verde.

> ⚠️ **Esta tarea se ejecuta fuera del orden numérico, y es deliberado.** Va antes de `T-006` y `T-007`. El motivo está escrito en el plan bajo `T-005`: las cuatro vías de resolución de tenant necesitan un modelo del tenant real del que leer el `organization_id`, y `tenancy:audit` necesita tablas del tenant que auditar. Hoy no existe ninguna de las dos cosas. Orden efectivo: `T-005` → **`T-008`** → `T-006` → `T-007` → `T-009`…

## 0. Qué vas a hacer

Las **tres primeras tablas del tenant** y sus modelos. Es la primera vez que `BelongsToOrganization` se aplica a algo real, así que es también la primera prueba de verdad de todo lo que `T-005` construyó.

## 1. Lee primero, en este orden

| Documento | Para qué |
|---|---|
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-008` y la nota de reordenación bajo `T-005` | alcance y por qué esta tarea se adelantó |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§4 bloque 3** y el encabezado de §4 | columnas, índices y las convenciones (incluida la del `BIGINT` con signo) |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) | `REQ-ORG-003` (roles), `REQ-ORG-004` (invitación), `REQ-ORG-006` (ajustes), `REQ-ORG-007` (cuentas de cobro) — **para conocer las columnas que necesitan, no para implementarlos** |
| [docs/specs/02-spec-consolidada.md](../specs/02-spec-consolidada.md) **§9** | las **once claves** de `organization_settings`, para saber qué va a guardar la tabla |

## 2. Alcance

**Sí:**

1. Migración de **`users` que sustituye la de Laravel**: `organization_id`, `name`, `email`, `password?`, `role`, `disabled_at?`, `must_change_password`, `invited_at?`, con **`UNIQUE(email)` global** e `INDEX(organization_id, role)`.
2. Migración de **`organization_settings`**: `organization_id`, `key`, `value JSON`, `updated_by?`, con `UNIQUE(organization_id, key)`.
3. Migración de **`payment_accounts`**: según §4 bloque 3, con `INDEX(organization_id, is_active)`.
4. Modelos **`User`** y **`PaymentAccount`** con `BelongsToOrganization`. (`Organization` ya existe desde `T-004`.)
5. `UserFactory` y `PaymentAccountFactory` que funcionen **dentro** de un tenant.
6. Sus tests, incluidos los de esquema en la línea de los que `T-004` dejó.

**No:**

- **No** implementes `REQ-ORG-003` (la matriz de roles y sus policies es `T-009`), ni `REQ-ORG-004` (invitación, `T-013`), ni `REQ-ORG-006` (la clase `Settings` con su caché, `T-014`), ni `REQ-ORG-007` (el CRUD y `ValidPaymentIdentifier`, `T-015`).
- **No** crees el enum `OrganizationRole` con sus tres métodos: eso es `T-009`. Aquí `role` es una columna `VARCHAR` y, si necesitas un enum para el cast, que sea el mínimo.
- **No** escribas middlewares (`T-006`) ni el gate de aislamiento (`T-007`).

## 3. El problema que esta tarea desata, y cómo se resuelve

Cuando `users` gane un `organization_id` obligatorio, **el andamiaje de autenticación del starter kit deja de funcionar**, porque crea usuarios sin organización. Los 26 tests heredados que siguen pasando hoy van a romperse. No lo esquives: es la consecuencia real de tener tenancy.

Lo que hay que hacer, en concreto:

- **`UserFactory` crea su propia organización por defecto**, de modo que los tests que solo necesitan "un usuario" sigan funcionando sin saber de tenants. Con eso, la mayoría de los 26 tests heredados vuelven a verde sin tocarlos.
- **La ruta de registro público del starter kit se retira aquí.** `RegisteredUserController` crea un usuario suelto, y con tenancy eso produciría usuarios sin organización, que es un estado que no debe existir. Retírala con su test. El registro de verdad —`RegisterOrganizationAction`, que crea organización + owner + suscripción Free + ajustes en una transacción— es `T-010`.
- **El resto del andamiaje (login, recuperación de contraseña, confirmación, ajustes de perfil) se queda.** `T-012` lo adapta. No lo borres por arrastre.

Si algún test heredado no se puede salvar con la factory, dilo en el reporte con su nombre en lugar de borrarlo sin más.

## 4. Cuatro columnas con intención

- **`users.email` es `UNIQUE` global**, y es una de las tres excepciones ya declaradas en `GlobalUniqueExceptions`. El motivo: el login ocurre **antes** de saber la organización. Consecuencia práctica que hay que respetar: una persona no puede tener cuenta en dos negocios con el mismo correo, y eso es intencional en esta iteración.
- **`users.password` es nullable**, y la migración de Laravel lo declara obligatorio. Un usuario **invitado** existe sin contraseña hasta que acepta la invitación (`REQ-ORG-004`). Si la dejas `NOT NULL`, la invitación de `T-013` no se puede implementar sin una alteración.
- **`must_change_password` con default `false`.** Igual que en `platform_users`: quien tenga que forzarlo lo pone en `true` explícitamente. Un default `true` esconde el requisito detrás del valor por defecto.
- **`organization_settings.value` es JSON y la clave es compuesta** (`organization_id`, `key`). No es una tabla de una fila por organización con once columnas: es clave-valor, porque `REQ-ORG-006` permite que una clave ausente caiga al default sin necesidad de migración.

## 5. Lo que hay que vigilar con el trait puesto por primera vez

`T-005` dejó tres guardas activas que ahora empiezan a morder, y conviene que las pruebes a conciencia porque van a condicionar cómo se escribe cada factory y cada seeder del resto del proyecto:

- **Leer sin tenant fijado lanza.** Cualquier test que toque `User::query()` sin tenant va a fallar, y eso es correcto.
- **Asignar `organization_id` a mano a otra organización lanza `CrossTenantRelationException`.** Así que `User::factory()->for($otraOrg)` no es el camino: para sembrar dentro de otra organización se usa `withinOrganization()` o `runFor()`. Déjalo demostrado con un test, porque es el patrón que va a necesitar el `DevSeeder` de dos organizaciones.
- **La tabla del modelo de prueba se crea antes de abrir la transacción del test** (`TenantTestCase`), porque en MySQL un `CREATE TABLE` provoca un *commit* implícito. Ahora que hay tablas del tenant reales, la mayoría de los tests ya no necesitan ese arnés; úsalo solo donde haga falta.

## 6. Hecho cuando

- [ ] `migrate:fresh` corre limpio y `users` tiene `organization_id` con FK a `organizations`.
- [ ] `UNIQUE(email)` global en `users`; `UNIQUE(organization_id, key)` en `organization_settings`.
- [ ] Test: `User` y `PaymentAccount` **filtran al leer** y **rellenan al crear** (ya no con un modelo de prueba, con los reales).
- [ ] Test de dos organizaciones: los usuarios de una no son visibles desde la otra.
- [ ] Test: `users.password` es nullable; `must_change_password` default `false`.
- [ ] Test: sembrar dentro de la organización vecina funciona vía `withinOrganization()`/`runFor()`.
- [ ] La ruta de registro público del starter kit ya no existe; login y recuperación de contraseña siguen funcionando.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 7. Reporta al terminar

1. Cuántos tests heredados rompieron, cuántos se salvaron con la factory y cuáles tuviste que retirar (con su nombre y motivo).
2. Cómo quedó `UserFactory`: qué rol crea por defecto y cómo se siembra en otra organización.
3. Si alguna de las tres guardas de `T-005` resultó demasiado estricta en la práctica, cuál y en qué caso concreto. **No la relajes**: repórtala.
4. Qué tests de esquema añadiste.
5. Nada más. No propongas siguientes pasos.
