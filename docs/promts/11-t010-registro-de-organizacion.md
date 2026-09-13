# PROMPT 11 — `T-010` · Registro de organización

> Hito **H0 · Cimientos** · Tarea `T-010` · Fase `AB` · 3 pts
> Cierra `REQ-ORG-001`.
> Depende de `T-011` (`0361493`, el plan Free existe) y `T-009` (`65ab324`, roles y policies).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 87 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

## 0. Qué vas a hacer

La **puerta de entrada al producto**: un negocio se registra y queda dentro, con su organización, su usuario dueño, su plan Free y sus ajustes, todo creado de una vez o nada.

Es también **la primera página propia del proyecto**. Hasta ahora toda la UI es andamiaje heredado del starter kit. Lo que escribas aquí fija el patrón para las otras ochenta.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-001`** | el requisito completo, con sus siete `SP-` |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-010` | alcance, el **reparto con `T-014`** y la nota sobre la primera superficie visual |
| [docs/specs/02-spec-consolidada.md](../specs/02-spec-consolidada.md) **§9** | las **once claves** de `organization_settings` con sus valores por defecto |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§3.1, §5.1 y §8** | daisyUI envuelto en `components/ui`, orden de los colaboradores, árbol de archivos |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-006` | solo para conocer las claves; **no lo implementes**, es `T-014` |

## 2. Alcance

**Sí:**

1. **`RegisterOrganizationAction`** — las **cuatro creaciones en una sola transacción**: organización, usuario `owner`, suscripción Free y ajustes por defecto.
2. **`RegisterOrganizationRequest`**, **`RegisterController`** (en `Marketing/`), **`RegisterOrganizationData`**.
3. **`ReservedSlugs`** y **`SlugTakenException`**, con la sugerencia de variante libre.
4. El enum **`SettingKey`** con sus once claves y sus valores por defecto (ver §3 — es el reparto acordado con `T-014`).
5. **`resources/js/pages/marketing/Register.tsx`** y los envoltorios daisyUI que necesite, en `components/ui/`.
6. La ruta `/registro`, **declarando su superficie** (`DeclaresNoTenantSurface`: el registro ocurre antes de que exista la organización).
7. Rate limit de **5 registros por hora y por IP**.

**No:**

- **No** implementes la clase `Settings` con su caché, las reglas de validación por clave, `UpdateSettingsAction` ni la UI de ajustes: todo eso es `T-014`.
- **No** implementes el checklist de arranque del backoffice ni el dashboard: `T-012` trae el login y la pantalla vacía.
- **No** toques el login ni la recuperación de contraseña heredados.
- **No** uses los primitivos de `components/vendor/`: son el shadcn heredado en retirada.

## 3. El reparto con `T-014`

`RegisterOrganizationAction` tiene que sembrar los ajustes por defecto **dentro de la transacción**, y no puede hacerlo contra un array escrito a mano sin crear una segunda fuente de verdad. Por eso el enum **`SettingKey` se crea aquí**, con sus once claves y sus valores por defecto.

Lo que **no** se crea aquí: la clase `Settings` con caché por organización, la validación de rango por clave, la acción de actualizar y la página. Eso es `T-014`, que además tiene que probar que la caché **no filtra entre organizaciones**.

## 4. Las cuatro decisiones que hay que acertar

**1. Las cuatro creaciones son atómicas, y el fallo deja cero rastro** (RB-1, SP-5). No es una precaución genérica: una organización sin suscripción es un estado del que el sistema no sabe salir —`REQ-SUB-002` RB-6 dice que una organización **nunca** puede quedarse sin plan— y un `owner` huérfano es una fila que ocupa un correo único de toda la plataforma sin que nadie pueda usarla. Prueba el rollback, no solo el camino feliz.

**2. Slug tomado → se sugiere una variante libre, no se rechaza y ya** (SP-1). La diferencia entre `ferreteria-el-tornillo-2` y "ese nombre no está disponible" es la diferencia entre un registro que termina y uno que se abandona. Es la primera pantalla que ve un cliente: no la conviertas en un acertijo.

**3. Un correo que ya existe como `Account` de cliente final se acepta** (SP-3). Esto parece un descuido y es el corazón del diseño: son **dos identidades distintas** (`D-8`). La misma persona puede ser dueña de un negocio en Apartar y clienta de otro negocio en Apartar, y su correo de Google no tiene por qué ser distinto del de su equipo. Lo que sí se rechaza es un correo ya usado como **usuario de equipo** de otra organización (SP-2), porque `users.email` es único global para que el login no tenga que preguntar por la organización.

**4. El email sin verificar no bloquea el backoffice** (RB-5). Solo bloquea **publicar un catálogo**. Un registro que exige verificar el correo antes de dejar mirar nada es un registro que pierde a quien solo quería probar. Aquí solo tienes que no bloquear; la verificación para publicar llega con los catálogos.

## 5. La primera pantalla propia: qué patrón estás fijando

Es la primera página que no viene del starter kit, así que lo que decidas aquí lo van a copiar las demás.

- **daisyUI 5, envuelto en `resources/js/components/ui/`** (`REQ-QAX-001` RB-5). Los primitivos de `components/vendor/` son shadcn heredado y están en retirada; no los importes ni los extiendas.
- **Ningún componente pasa de 200 líneas** (regla ESLint como error), **ningún `any`**, `noUncheckedIndexedAccess` activo.
- **El slug se sugiere solo al escribir el nombre**, y es editable. Ese detalle es de `RB-2` y es lo que hace que SP-1 casi nunca se dispare.
- **Mobile-first.** El público de este producto entra por teléfono.
- Si haces un envoltorio daisyUI reutilizable (campo de formulario, botón, alerta), escríbelo pensando en que lo van a usar ochenta pantallas, no solo esta.

## 6. Hecho cuando

- [ ] Crea **organización + owner + suscripción Free + ajustes** en una sola transacción.
- [ ] **Rollback total** ante fallo: un test que hace fallar la cuarta creación y comprueba que no queda ninguna de las tres anteriores.
- [ ] Slug duplicado **sugiere una variante libre**; slug reservado rechazado; nombre que produce slug vacío exige slug manual (SP-7).
- [ ] Email ya usado como usuario de equipo → `422`; email que existe como `Account` → **aceptado**.
- [ ] Contraseña con `Password::min(12)->mixedCase()->numbers()->uncompromised()`; comprometida → `422` explicando el motivo.
- [ ] El plan inicial es **Free**, y sin plan Free en la base el registro falla con el error de `T-011`.
- [ ] `429` al **sexto** registro en una hora desde la misma IP.
- [ ] La sesión queda autenticada en el guard `web` y aterriza en el backoffice.
- [ ] Los once ajustes por defecto quedan sembrados.
- [ ] La ruta `/registro` **declara su superficie**; `tenancy:audit` y la suite de tenancy en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 7. Reporta al terminar

1. Cómo quedó la transacción y **cómo probaste el rollback** — qué hiciste fallar.
2. El algoritmo de sugerencia de slug y qué pasa con un nombre que solo tiene emojis.
3. Qué envoltorios daisyUI creaste en `components/ui/` y qué patrón fijan para las páginas siguientes.
4. Las once claves de `SettingKey` con sus valores por defecto, para cotejarlas con §9.
5. Si algo de `REQ-ORG-001` chocó con lo ya construido, qué y cómo lo resolviste.
6. Nada más. No propongas siguientes pasos.
