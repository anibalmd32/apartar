# PROMPT 09 — `T-009` · Roles del equipo y policies

> Hito **H0 · Cimientos** · Tarea `T-009` · Fase `AB` · 2 pts
> Cierra `REQ-ORG-003`.
> Depende de `T-008` (`0ba84c9`) y `T-007` (`30ad69a`): `users.role` en el esquema, gate de aislamiento en pie.

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 87 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

## 0. Qué vas a hacer

El modelo de permisos completo del backoffice: **dos roles, seis capacidades**. Es pequeño a propósito — no hay RBAC granular ni roles personalizados, y eso está fuera de alcance por decisión (`D-4`), no por falta de tiempo.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-003`** | el requisito completo, con sus cinco `SP-` |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-009` | alcance y "hecho cuando"; lee también las **convenciones** del encabezado |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-005` y `REQ-ORG-008` | la protección del último `owner` y los estados de organización comparten mecanismo con esta tarea |

## 2. Alcance

**Sí:**

1. **`OrganizationRole`** con sus tres métodos: `canManageBilling()`, `canManageTeam()`, `canManageSettings()`.
2. **`OrganizationPolicy`**, **`UserPolicy`** y **`SubscriptionPolicy`** (esta última con lo que se pueda afirmar hoy: `Subscription` llega en `T-011`).
3. **`EnsureIsOwner`** como middleware.
4. **`LastOwnerException`**.
5. La matriz completa de tests: **2 roles × 6 capacidades**.

**No:**

- **No** implementes la invitación (`REQ-ORG-004`, `T-013`) ni la desactivación de miembros (`REQ-ORG-005`, `T-013`). Aquí solo el **mecanismo** de protección del último `owner`; su uso llega con esas tareas.
- **No** crees páginas ni rutas de equipo. `T-013` las trae.
- **No** toques `OrganizationStatus`: ya existe desde `T-004`.

## 3. Las tres reglas que definen si esto está bien hecho

**1. El rol se lee de la base de datos en cada petición, nunca de un claim de la sesión** (RB-6). El motivo es concreto: si un `owner` degrada a alguien, la degradación tiene que surtir efecto en la petición siguiente de esa persona, no cuando vuelva a iniciar sesión. Un rol cacheado en sesión convierte una revocación en una sugerencia.

**2. Ocultar en la UI no es control de acceso** (RB-4). Las secciones exclusivas del `owner` no se renderizan para un `member`, **y además** están protegidas por policy en el servidor. Son dos cosas distintas y hacen falta las dos: la primera es cortesía, la segunda es seguridad. Un `member` que escribe la URL de facturación a mano recibe `403`.

**3. Toda organización tiene al menos un `owner` activo, y la comprobación es transaccional con bloqueo** (RB-2, SP-3). El caso que lo justifica es el de dos `owner` degradándose a la vez: sin bloqueo, ambos leen "hay dos owners", ambos proceden, y la organización se queda sin ninguno — un estado del que no se sale sin intervención manual en la base de datos. Con bloqueo, el segundo falla y se preserva uno.

## 4. Las seis capacidades

`RB-1` reparte así: el `owner` gobierna **suscripción, facturación, equipo, ajustes de organización, cuentas de cobro y branding**; el `member` opera **productos, catálogos, clientes, cobranza, comprobantes y chat**.

Léelo con cuidado, porque la frontera es la que hace útil el rol `member`: un empleado tiene que poder **cobrar y conciliar comprobantes** —que es el trabajo diario— sin poder tocar la facturación del negocio ni dar de alta usuarios. Si el reparto sale mal, el `member` no sirve para delegar y el `owner` acaba haciéndolo todo.

Un detalle que no es de esta tarea pero condiciona su forma: **los `platform_users` no tienen rol de organización y nunca aparecen en el equipo** (RB-5). Son tablas distintas por diseño (`D-5`). Si `UserPolicy` o el middleware necesitan contemplarlos, algo se ha mezclado.

## 5. Hecho cuando

- [ ] Matriz de **2 roles × 6 capacidades** con un caso por celda.
- [ ] Un `member` recibe **`403`** en cada capacidad de `owner`.
- [ ] El rol se lee de la base de datos: un test promueve a alguien con la sesión abierta y comprueba que el permiso aplica en la **siguiente petición**.
- [ ] El último `owner` está protegido, **con un test de concurrencia** (dos degradaciones simultáneas, una falla, queda uno).
- [ ] Un `owner` puede promover a un `member` y degradarse a sí mismo **salvo que sea el último**.
- [ ] Los `platform_users` no aparecen en ninguna consulta de equipo.
- [ ] `tenancy:audit` y la suite de tenancy siguen en verde; toda ruta nueva **declara su superficie**.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 6. Reporta al terminar

1. Cómo quedó la matriz: las seis capacidades y qué clase las nombra.
2. Cómo implementaste el bloqueo del último `owner` y **cómo probaste la concurrencia** — el mecanismo del test, no solo que pasa.
3. Qué pudiste afirmar de `SubscriptionPolicy` sin que exista `Subscription`, y qué queda para `T-011`.
4. Si alguna capacidad de `RB-1` resultó ambigua al repartirla, cuál y qué decidiste.
5. Nada más. No propongas siguientes pasos.
