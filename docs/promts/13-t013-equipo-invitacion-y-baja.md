# PROMPT 13 — `T-013` · Equipo: invitación y desactivación

> Hito **H0 · Cimientos** · Tarea `T-013` · Fase `AB` · 3 pts
> Cierra `REQ-ORG-004` y `REQ-ORG-005`.
> Depende de `T-012` (`cccd04f`, login y layout), `T-011` (`0361493`, planes) y `T-009` (`65ab324`, roles y `LastOwnerGuard`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 87 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

## 0. Qué vas a hacer

Que el dueño pueda **delegar**: invitar a su equipo, darles de baja y volver a darles de alta. Es lo que convierte el producto en algo que usa un negocio y no una sola persona.

Y trae una pieza que va a gobernar el resto del proyecto: **`LimitEnforcer`**, el único sitio donde se decide si una organización puede crear algo más.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-004`** y **`REQ-ORG-005`** | los dos requisitos completos, con sus trece `SP-` entre ambos |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-013` | alcance y el **adelanto de alcance decidido** sobre `LimitEnforcer` |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§5 (3) y §5.1** | la **firma exacta** de `LimitEnforcer` y el orden en que se llama |
| [docs/specs/50-planes-y-suscripciones.md](../specs/50-planes-y-suscripciones.md) → `REQ-SUB-003` y `REQ-SUB-004` | cómo se mide el consumo y cómo se aplica el límite. **No los implementes enteros**: son H5 |

## 2. Alcance

**Sí:**

1. **`InviteTeamMemberAction`**, **`DisableTeamMemberAction`**, **`EnableTeamMemberAction`**.
2. **`AcceptInvitationController`**, **`TeamInvitationNotification`**, **`TeamController`**.
3. **`LimitEnforcer`** con la firma definitiva de §5 (ver §3).
4. Las páginas de equipo, sobre `BackofficeLayout`.
5. Las rutas, declarando **superficie** y **si sobreviven a la suspensión**.
6. Sus tests, incluido el de concurrencia del último `owner`.

**No:**

- **No** implementes `UsageCalculator`, `RecalculateUsage` ni el comando de reconciliación: son `T-066`.
- **No** insertes llamadas a `LimitEnforcer` en acciones que no sean las de esta tarea. Las doce de `REQ-SUB-004` RB-3 son `T-067`.
- **No** construyas `LimitBanner`, `UpgradePrompt` ni `usePlanLimits`: son `T-067`.
- **No** implementes el cambio de plan (`T-068`) ni la suspensión por impago (`T-071`).

## 3. `LimitEnforcer` nace aquí, y por qué importa cómo

`REQ-ORG-004` RB-2 exige que **una invitación pendiente consuma cupo** y `REQ-ORG-005` RB-5 que **desactivar lo libere**. Eso es aplicación de límites, y [05 §5](../specs/05-design-tecnico.md) es tajante: *ninguna Action comprueba límites por su cuenta*.

Contar a mano aquí y sustituirlo en H5 dejaría esa invariante rota durante cincuenta tareas, con `T-015` repitiendo el mismo apaño para `max_payment_accounts`. Así que se construye ahora, **con la firma definitiva**, soportando las métricas cuyas tablas ya existen (`users`, `payment_accounts`). `T-066` añade las otras cuatro; `T-067` lo cablea en las doce acciones y aporta la UI. Ninguno reescribe lo que hagas.

Dos cosas de su contrato que no son negociables:

- **`assertCanCreate` lee el contador BLOQUEADO dentro de la transacción** (§5). Sin el bloqueo, dos invitaciones simultáneas en un plan con un solo cupo pasan las dos.
- **`NULL` es ilimitado y no bloquea nada.** Ya lo fijó `T-011`; aquí se consume.

## 4. Las cinco reglas que definen esta tarea

**1. La invitación consume cupo desde que se envía, no desde que se acepta** (RB-2). El motivo está escrito en la spec: si contara al aceptar, un plan Free podría enviar cincuenta invitaciones. Y su simétrica: **revocar libera** (RB-6).

**2. La baja es lógica, nunca un borrado** (RB-1). Un usuario borrado se lleva por delante `reviewed_by`, `registered_by` y su rastro en la auditoría — es decir, quién aprobó qué comprobante. Las referencias históricas siguen mostrando su nombre con la marca "inactivo" (RB-7).

**3. Bajar de plan no expulsa a nadie** (SP-7, `REQ-SUB-008`). Si una organización con ocho miembros baja a un plan de cinco, **los ocho se quedan** y lo único que se bloquea es invitar a un noveno. La degradación no destruye: esa es una decisión de producto deliberada, y lo contrario convertiría un cambio de plan en una pérdida de accesos que nadie esperaba.

**4. Reactivar consume cupo igual que invitar** (SP-4), y **reactivar fuerza `must_change_password`** (RB-6). Lo segundo es por precaución: entre la baja y el alta puede haber pasado cualquier cosa con esa contraseña.

**5. Nadie se desactiva a sí mismo** (RB-4) y **el último `owner` activo está protegido** (RB-3, SP-3). El mecanismo ya existe: `LastOwnerGuard`, de `T-009`, con su bloqueo transaccional y su test de concurrencia. Úsalo; no escribas otro.

## 5. El token de invitación

Es una credencial, así que trátala como tal: **48 horas, un solo uso, y el reenvío invalida el anterior** (RB-4, SP-6). Se guarda con hash, como cualquier token.

El caso que suele quedar mal resuelto es SP-3: un enlace caducado o ya usado **no da un error crudo**, muestra una pantalla con la opción de pedir reenvío al `owner`, y el token queda marcado como consumido. Quien llega ahí no ha hecho nada mal — normalmente es alguien que tardó tres días en abrir el correo.

Y SP-5: una invitación aceptada **después** de que la organización quedara suspendida crea al usuario igualmente, pero no podrá entrar hasta que se regularice. No es un caso raro; es exactamente lo que pasa cuando alguien invita y deja de pagar la misma semana.

## 6. Hecho cuando

- [ ] Solo el `owner` invita; un `member` recibe `403`.
- [ ] Una **invitación pendiente cuenta para el límite**; revocarla lo libera.
- [ ] Límite alcanzado → `422` **con el plan que lo desbloquea** y enlace a Suscripción.
- [ ] Token de **48 h, de un solo uso**; el reenvío invalida el anterior; caducado muestra la pantalla de reenvío y se marca consumido.
- [ ] Email ya usado en **cualquier** organización → `422`.
- [ ] Nadie se autodesactiva; el **último `owner` está protegido con un test de concurrencia**.
- [ ] Desactivar **libera** cupo; reactivar lo **consume** y fuerza `must_change_password`.
- [ ] La sesión de un desactivado **cae en su siguiente petición**.
- [ ] Bajar de plan con más miembros que el nuevo límite **no expulsa a nadie**.
- [ ] Las referencias históricas conservan el nombre con la marca "inactivo".
- [ ] Dos invitaciones simultáneas con **un solo cupo**: una pasa, la otra falla.
- [ ] Toda ruta nueva declara **superficie** y **si sobrevive a la suspensión**; `tenancy:audit` y la suite en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 7. Reporta al terminar

1. Cómo quedó `LimitEnforcer`: firma, qué métricas soporta hoy y cómo se mantiene el contador.
2. Cómo probaste la concurrencia del cupo — el mecanismo del test, no solo que pasa.
3. Qué hace exactamente el sistema con una invitación cuyo token caducó, de principio a fin.
4. Qué páginas de equipo creaste y qué átomos nuevos necesitaste en `components/ui/`.
5. Si algo de `REQ-ORG-004`/`005` depende de algo que aún no existe, qué y de qué tarea es.
6. Nada más. No propongas siguientes pasos.
