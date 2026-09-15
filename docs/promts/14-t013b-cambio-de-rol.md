# PROMPT 14 — `T-013b` · Cambio de rol del equipo

> Hito **H0 · Cimientos** · Tarea `T-013b` · Fase `AB` · 1 pt
> Cierra de verdad `REQ-ORG-003` (RB-3, SP-2, SP-4).
> Depende de `T-013` (`62db6b7`, equipo, pantalla y `LimitEnforcer`) y `T-009` (`65ab324`, roles, policies y `LastOwnerGuard`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

## 0. Qué vas a hacer

Una tarea pequeña que tapa un agujero real: **en el producto no hay forma de cambiarle el rol a nadie.**

`T-009` cerró `REQ-ORG-003` contra su gate —la matriz de dos roles por seis capacidades, el `403` del `member`, el bloqueo del último `owner`, el rol leído de base de datos— y lo cerró bien. Pero el gate no pedía la **vía por la que un dueño promueve o degrada**, y `RB-3` la enuncia mientras `SP-2` y `SP-4` la dan por hecha. Se descubrió al cerrar `T-013`, que construyó la pantalla donde ese control tiene que vivir.

Es 1 punto porque el mecanismo ya está hecho. Lo que falta es la acción, la ruta y el control.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-003`** | el requisito completo; te interesan **RB-3, RB-4, RB-6, SP-2 y SP-4** |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-013b` y `T-013` | por qué existe esta tarea, y las decisiones ya tomadas sobre el equipo |
| `app/Domain/Tenancy/LastOwnerGuard.php` | **léelo entero antes de escribir nada.** Ya hace lo difícil, y su parámetro `$operation` ya contempla «degradar» |
| `app/Actions/Organization/DisableTeamMemberAction.php` | el patrón de acción de equipo que acaba de escribirse: autorización, guarda, escritura, contador |

## 2. Alcance

**Sí:**

1. **`ChangeTeamMemberRoleAction`**, en `app/Actions/Organization/`.
2. La ruta **`POST /backoffice/equipo/{member}/rol`**, bajo `owner`, declarando **superficie** y **si sobrevive a la suspensión**.
3. Su `FormRequest`, y el método en `TeamController`.
4. El control de rol en `resources/js/pages/backoffice/team/Index.tsx`.
5. Sus tests, incluido el de **SP-4 por HTTP**.

**No:**

- **No** toques `LastOwnerGuard`. Ya tiene su bloqueo y su test de concurrencia; si te ves modificándolo, lo estás usando mal.
- **No** añadas roles, ni permisos por recurso, ni nada parecido: `D-4` deja el RBAC granular fuera de alcance por decisión, no por falta de tiempo.
- **No** entres en `T-014` (ajustes, identidad y vitrina).

## 3. Las cuatro cosas que hay que acertar

**1. Degradar al último `owner` activo se bloquea, y el bloqueo es el que ya existe.** `LastOwnerGuard::whileKeepingAnOwner()` recibe el sujeto, la palabra que describe la operación y la escritura como `Closure`, y ejecuta las tres cosas dentro de una transacción con `SELECT … FOR UPDATE`. Pásale `'degradar'` y la escritura. El motivo del bloqueo está en su docblock y merece leerlo: **dos `owner` degradándose a la vez sin él dejan la organización sin ninguno**, y de ese estado no se sale sin tocar la base de datos a mano.

No hace falta un segundo test de concurrencia: `LastOwnerConcurrencyTest` ya ejercita esa vía desde `T-009`. Lo que sí hace falta es el caso de un solo hilo (SP-2 → `422`).

**2. SP-4 se prueba por HTTP, no tocando la base de datos.** La regla es que un `member` promovido **con la sesión abierta** tenga el permiso en su siguiente petición, porque RB-6 obliga a leer el rol de la base de datos y no de un claim de sesión. Un test que promueva con un `update()` directo probaría RB-6, que ya está probado; lo que falta por probar es que **la vía real del producto** produce ese efecto. Así que: el `owner` promueve por la ruta nueva, y la petición siguiente del `member` —con su sesión intacta— entra donde antes recibía `403`.

Su simétrica vale igual y es la que de verdad importa: a quien **degradas**, la degradación le tiene que aplicar en el siguiente clic. Si tarda hasta que vuelva a iniciar sesión, una revocación se convierte en una sugerencia.

**3. Un `owner` puede degradarse a sí mismo** (RB-3), y eso **no** es el caso de `REQ-ORG-005` RB-4. Esa regla prohíbe *desactivarse*, que es quedarse fuera; degradarse es seguir dentro con menos. Son cosas distintas y la única barrera aquí es ser el último.

**4. El control cuenta la verdad antes de que la intenten.** La pantalla de Equipo ya fija ese patrón —ningún botón imposible está simplemente ausente: se deshabilita con el motivo al lado—. El selector de rol del último `owner` activo sigue esa regla. Y como siempre: **ocultar en la UI no es control de acceso** (RB-4); la policy del servidor es la que manda, y un `member` que llame a la ruta a mano recibe `403`.

Ojo con el tamaño: `Index.tsx` ya va por 247 líneas físicas. La regla de 200 salta blancos y comentarios, así que hoy pasa, pero si el control de rol la aprieta, **extrae la fila de miembro a su propio componente** en vez de pelearte con el límite. Es lo que la regla busca.

## 4. Hecho cuando

- [ ] Un `owner` **promueve** a un `member` y **se degrada** a sí mismo.
- [ ] El **último `owner` activo no puede degradarse** → `422` con el motivo, vía `LastOwnerGuard`.
- [ ] Un `member` llamando a la ruta → `403`.
- [ ] **SP-4 por HTTP**: promovido con la sesión abierta, el permiso aplica en su siguiente petición; degradado, se le retira igual de rápido.
- [ ] El `{member}` de otra organización da **`404`**, no `403` (`REQ-ORG-002` RB-6).
- [ ] La ruta declara **superficie** y **no** sobrevive a la suspensión; `tenancy:audit` y la suite de tenancy en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 5. Reporta al terminar

1. Cómo quedó `ChangeTeamMemberRoleAction` y qué le pasaste a `LastOwnerGuard`.
2. Cómo probaste SP-4 por HTTP — el mecanismo del test, no solo que pasa.
3. Qué hiciste con el tamaño de `Index.tsx` y por qué.
4. Si al escribir esto encontraste algo más de `REQ-ORG-003` que se diera por cerrado sin estarlo, qué.
5. Nada más. No propongas siguientes pasos.
