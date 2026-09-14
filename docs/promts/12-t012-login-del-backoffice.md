# PROMPT 12 — `T-012` · Login del backoffice

> Hito **H0 · Cimientos** · Tarea `T-012` · Fase `AB` · 3 pts
> Cierra `REQ-BOF-001`.
> Depende de `T-010` (`8d412b0`, registro y primeros átomos daisyUI) y `T-009` (`65ab324`, roles y policies).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 87 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

## 0. Qué vas a hacer

La puerta del backoffice, y con ella **la primera pantalla que alguien usa todos los días**. Al cerrar esta tarea, un negocio se registra, entra, y ve su panel — vacío, pero suyo.

También retiras por fin el andamiaje de autenticación del starter kit que llevamos conservando desde `T-001` precisamente para este momento.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/30-backoffice-accesos-y-usuarios.md](../specs/30-backoffice-accesos-y-usuarios.md) → **`REQ-BOF-001`** | el requisito completo: doce reglas y diez `SP-` |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-012` | alcance y "hecho cuando" |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-008` **RB-3** | qué puede hacer exactamente una organización `suspended` |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§7 y §8** | la tabla de rutas y dónde va cada archivo |

## 2. Alcance

**Sí:**

1. **`LoginController`** y **`PasswordResetController`** en `Backoffice/Auth/`, con `routes/backoffice.php`.
2. Los tres middlewares: **`EnsureAdminIsActive`**, **`EnsureOrganizationIsActive`**, **`EnsurePasswordIsChanged`**.
3. **`AuthenticatedTeamMember`**, **`AdminDisabledException`**, **`OrganizationNotActiveException`**.
4. **`BackofficeLayout`** y un **dashboard vacío**.
5. **Retirar el andamiaje heredado** del starter kit que esto sustituye (ver §4).
6. Sus tests, incluidos los de tenancy con dos organizaciones.

**No:**

- **No** implementes el checklist de arranque del dashboard (`REQ-ORG-001` happy path punto 3): necesita productos, catálogos y cuentas de cobro, que no existen. El dashboard queda **vacío** a propósito.
- **No** implementes la gestión de equipo (`T-013`) ni los ajustes (`T-014`).
- **No** toques la suscripción ni el modo restringido más allá de lo que exige RB-9.

## 3. Las cuatro decisiones que definen esta tarea

**1. El login no pide la organización** (RB-2). Es la consecuencia directa de que `users.email` sea único en toda la plataforma, y por eso esa unicidad global figura en la lista blanca de `GlobalUniqueExceptions` desde `T-005`. La organización se **deriva** del usuario autenticado y la fija `ResolveTenant`. Si te encuentras escribiendo un selector de organización, algo se torció.

**2. Una organización `suspended` no expulsa a su equipo** (RB-9, SP-8). Entra, pero queda **restringida a leer cobranza y aprobar comprobantes**, con un aviso persistente y el enlace de pago. Esto es deliberado y va contra el instinto: un negocio que no ha pagado su suscripción **sigue teniendo clientes que le deben dinero**, y cortarle el acceso a cobrarles le impide precisamente conseguir con qué pagarte. Se le quita crear, no cobrar.

`closed` sí rechaza el login, con el motivo (RB-10).

**3. El mensaje de credenciales inválidas es genérico** (SP-1). No distingue "ese correo no existe" de "esa contraseña está mal". Con `users.email` único global, un mensaje que distinga convierte el login en un oráculo que dice si una persona tiene cuenta en la plataforma — y con ella, en qué negocios trabaja.

**4. Un `PlatformUser` no entra por este guard** (SP-5). Son tablas distintas por diseño (`D-5`). Si tu implementación necesita contemplarlos aquí, algo se mezcló.

## 4. Lo que se retira

Desde `T-001` venimos conservando el andamiaje de autenticación del starter kit con la instrucción explícita de no borrarlo, porque esta tarea lo iba a **adaptar** y reutilizar. Ha llegado el momento.

Lo que se sustituye por lo nuestro: login, y recuperación/restablecimiento de contraseña. Reutiliza lo que sirva —`LoginRequest` ya trae el rate limiting por `email|ip`, que es justo lo que pide RB-3— en lugar de reescribir desde cero.

Lo que **no** se toca todavía: los ajustes de perfil (`settings/profile`, `settings/password`, `settings/appearance`) siguen en pie. Su sitio definitivo se decide cuando exista el backoffice completo.

Y lo que ya no debería quedar: si al terminar sigue habiendo páginas en `resources/js/pages/auth/` que nadie enruta, retíralas con su test. Una página muerta que compila es peor que un hueco, porque aparenta estar viva.

## 5. Cómo se ve un backoffice sin nada dentro

`BackofficeLayout` es el esqueleto que van a heredar unas cuarenta pantallas, así que importa más que el dashboard que lo estrena:

- **daisyUI envuelto en `components/ui/`**, sobre los tres átomos que `T-010` dejó (`button`, `text-field`, `alert`). Si necesitas más, créalos ahí con el mismo criterio.
- **Navegación por rol**: las secciones de `owner` **no se renderizan** para un `member` (`REQ-ORG-003` RB-4). Hoy casi no hay secciones; el mecanismo sí tiene que estar.
- **El aviso de organización suspendida** es parte del layout, no del dashboard: tiene que verse en todas las pantallas.
- Mobile-first, 200 líneas por componente, sin `any`.

Un dashboard vacío honesto —"aún no hay nada aquí, empieza por…"— es mejor que uno lleno de tarjetas en cero.

## 6. Hecho cuando

- [ ] Login válido entra; inválido da **mensaje genérico** sin distinguir el motivo.
- [ ] **`429` al sexto intento** en 15 minutos por (email + IP), con `Retry-After`.
- [ ] **El tenant se fija desde el usuario y las consultas quedan filtradas**, con un test de dos organizaciones.
- [ ] Un usuario con `disabled_at` **pierde la sesión en la siguiente petición**.
- [ ] `must_change_password` redirige toda navegación al cambio de contraseña.
- [ ] Un `PlatformUser` **no entra** por este guard.
- [ ] Organización `suspended`: **aprueba comprobantes y no crea productos**; el aviso y el enlace de pago se ven.
- [ ] Organización `closed`: login rechazado con el motivo.
- [ ] Recuperación de contraseña de **un solo uso**, TTL 60 minutos.
- [ ] Petición Inertia con sesión caducada → **`409`** que fuerza recarga al login (SP-6).
- [ ] La sesión se regenera al autenticar y se invalida al cerrar.
- [ ] Los intentos fallidos se registran con email e IP, **nunca la contraseña**.
- [ ] Todas las rutas nuevas **declaran su superficie**; `tenancy:audit` y la suite en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 7. Reporta al terminar

1. Cómo probaste que el tenant queda fijado y filtrado desde la sesión — el mecanismo del test.
2. Cómo implementaste el modo restringido de `suspended`: dónde se decide y cómo se prueba que aprueba comprobantes pero no crea.
3. Qué reutilizaste del andamiaje heredado y qué retiraste, con nombres.
4. Qué quedó en `BackofficeLayout` y qué patrón fija para las cuarenta pantallas siguientes.
5. Si algo de `REQ-BOF-001` no se pudo cerrar porque depende de algo que aún no existe, qué y de qué tarea es.
6. Nada más. No propongas siguientes pasos.
