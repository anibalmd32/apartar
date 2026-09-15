# PROMPT 15 — `T-014` · Ajustes, identidad y vitrina

> Hito **H0 · Cimientos** · Tarea `T-014` · Fase `AB` · 3 pts
> Cierra `REQ-ORG-006`.
> Depende de `T-013b` (`07508f6`, equipo completo), `T-013` (`62db6b7`, `LimitEnforcer`) y `T-010` (`8d412b0`, `SettingKey` y `ReservedSlugs`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-014: ajustes, identidad y vitrina`. Las dos tareas anteriores se dejaron sin commitear y hubo que registrarlas desde fuera; el `git log` es de lo que depende el arranque en frío de la sesión siguiente.

## 0. Qué vas a hacer

Que la herramienta se adapte al negocio y no al revés: **tasa, plazos, frecuencia de cuotas, identidad visual**. Es la última tarea de configuración antes de que el producto empiece a hacer cosas.

Y trae una pieza que van a usar casi todas las tareas de H1 en adelante: **`Settings`, con caché por organización**. Si la caché filtra entre organizaciones, un negocio calcula sus vencimientos con los plazos de otro.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-006`** | el requisito completo, con sus ocho `SP-` |
| [docs/specs/02-spec-consolidada.md](../specs/02-spec-consolidada.md) **§9.1** | las **10 claves** con sus valores por defecto y sus rangos. Es la fuente |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-014` | alcance, el **añadido** sobre la navegación y por qué la vitrina no es de esta tarea |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§3.1 y §8** | daisyUI envuelto en `components/ui`, y dónde va cada archivo |

## 2. Alcance

**Sí:**

1. **`SettingKey` reducido a 10 claves**, con `validationRules()` y `defaultValue()`.
2. **`Settings`** con caché **por organización**, y **`UnknownSettingException`**.
3. **`UpdateSettingsAction`** y **`UpdateOrganizationAction`**, con **`OrganizationBrand`**.
4. La **migración de `organizations.phone`**, y la limpieza del huérfano que dejó `T-010` (ver §5).
5. La página de ajustes y su ruta, declarando **superficie** y **si sobrevive a la suspensión**.
6. **La navegación derivada de `OrganizationCapability`** (ver §6).
7. Sus tests, incluido el **de fuga de caché entre organizaciones**.

**No:**

- **No** construyas `OrganizationStorefrontController` ni la página `/{org-slug}`: es **`T-036`**. Estaba escrito en las dos tareas y sobra aquí — la vitrina lista catálogos publicados y `Catalog` no existe hasta H2. Lo que sí entra es el **interruptor** en ajustes.
- **No** implementes la conversión de tasa ni el cálculo de vencimientos: las claves se guardan y se validan, quien las consume llega en H1 y H3.
- **No** implementes la auditoría de RB-8: `activity_log` y `LogsSensitiveActivity` son de **`T-016`**, la tarea siguiente.
- **No** toques `LimitEnforcer` ni insertes en él las llamadas de `T-067`.

## 3. La caché es lo que puede salir caro

`REQ-ORG-003` RB-3 pide caché por organización con clave `settings:{organization_id}`, invalidada en cada escritura. Suena a detalle de rendimiento y **es un problema de aislamiento**: los ajustes gobiernan períodos de gracia, ventanas de abono inicial y tolerancias de liquidación. Una caché compartida por error no da un error; da **fechas de vencimiento calculadas con los plazos de otro negocio**, y eso se descubre cuando un cliente reclama.

Por eso el "hecho cuando" pide un **test de fuga** explícito: dos organizaciones, ajustes distintos, leídos en la misma petición y en peticiones consecutivas, cada una con lo suyo.

Y SP-7: **Redis caído se lee de BD**. No es una optimización opcional — es la diferencia entre una caída de Redis que ralentiza y una que para el negocio.

## 4. Las claves: guardar no es aplicar

**RB-4 es la regla que más se incumple sin querer: los cambios afectan solo al futuro.** `grace_period_days` se reevalúa a diario, pero `down_payment_window_hours` **no mueve los `expires_at` ya fijados**. Un ajuste que retroactivara vencimientos cambiaría el estado de apartados que ya se comunicaron al cliente. Cada clave declara su alcance en la UI, y eso es parte de la tarea: quien cambia un plazo tiene que saber a qué afecta antes de guardarlo.

Lo mismo con la zona horaria (SP-6): cambiarla mueve la **presentación** y los envíos futuros, nunca las fechas ya fijadas.

**RB-2: una clave desconocida se ignora en silencio**, no da error. Es lo que permite desplegar una versión que ya escribe una clave nueva contra filas sembradas antes.

## 5. El huérfano de `T-010`

`RegisterOrganizationData` tiene una propiedad `phone` que no acaba en ninguna columna: `organizations` no la tiene. Se detectó al cerrar `T-010` y se decidió resolverlo aquí. Haz las dos mitades: **la migración que añade `organizations.phone`** y **la limpieza de la propiedad huérfana** si el registro no la va a escribir.

Decide cuál de las dos corresponde y deja escrito el porqué. Lo que no vale es dejarla como está: un dato que el formulario pide y el sistema tira es peor que no pedirlo.

## 6. La frontera de los roles, una sola vez

`OrganizationRole::capabilities()` se escribió en `T-009` —lo dice su docblock— «para pintar el menú sin repetir la frontera», y **hoy no tiene un solo llamante**: `backoffice-layout.tsx` la vuelve a declarar a mano con un `ownerOnly` por sección. Lo encontró `T-013b`.

Hoy coinciden, porque son dos entradas. Esta tarea añade la tercera, y ese es el momento de pararlo: **cada sección declara la `OrganizationCapability` que necesita y el layout se la pregunta al rol** (`REQ-ORG-003` RB-4b). Con la frontera en dos sitios, el día que una capacidad cambie de lado el enum lo sabrá y el menú no —y el síntoma no es un test en rojo, es un `member` viendo algo que le dará `403`.

Y lo de siempre: ocultar en la UI **no** es control de acceso. La policy del servidor sigue mandando.

## 7. Branding: se guarda siempre, se aplica según el plan

`LimitEnforcer` existe desde `T-013` y aquí solo se **lee**: `hasFeature(PlanFeature::Branding)`.

No llames a `assertHasFeature()` en `UpdateOrganizationAction`. Bloquearía el guardado y rompería SP-3, que es explícito: en Free el branding **se guarda, no se aplica**, con aviso claro, y **al subir de plan se activa sin volver a configurarlo**. La tabla de `REQ-SUB-004` RB-3 dice «aplicar logo y color», no «guardarlos». Es la diferencia entre una función que no tienes y un dato que no te dejan escribir — y la segunda haría que subir de plan costara rellenar el formulario otra vez.

RB-7 de `REQ-SUB-004` completa el cuadro: el control se muestra **deshabilitado con el motivo**, nunca oculto. Quien está en Free tiene que **ver** lo que se pierde.

## 8. El slug, otra vez

SP-5: **cambiar el slug con catálogos publicados da `422`**, porque rompería los enlaces de la vitrina ya compartidos — y el caso de uso de la vitrina es literalmente pegar esa URL en una bio de Instagram.

Hoy no hay catálogos, así que la comprobación no puede consultarlos. Escribe la regla **con la forma que va a tener**, no con un `TODO`: si necesitas una consulta que aún no existe, deja el punto de extensión explícito y anótalo en el plan para `T-030`+. `ReservedSlugs` ya existe desde `T-010` y cubre SP-8; compruébalo y reutilízalo.

## 9. Hecho cuando

- [ ] Las **10 claves** validan su rango; `storefront_enabled` ya **no** es una clave, y las filas sembradas se retiran.
- [ ] Clave desconocida **ignorada en silencio**, sin error.
- [ ] **Caché aislada entre organizaciones**, con un test de fuga explícito.
- [ ] **Redis caído → se lee de BD** (SP-7).
- [ ] Solo el `owner` edita; un `member` recibe `403`.
- [ ] Branding **se guarda en Free** y no se aplica, con el aviso; el control está deshabilitado con el motivo, no oculto.
- [ ] Cambiar la zona horaria **no mueve** fechas ya fijadas.
- [ ] `organizations.phone` resuelto: migración y huérfano, con el porqué escrito.
- [ ] La **navegación deriva de `OrganizationCapability`**; ninguna sección declara su rol a mano.
- [ ] Slug reservado rechazado; la regla de SP-5 escrita con su forma definitiva.
- [ ] Toda ruta nueva declara **superficie** y **si sobrevive a la suspensión**; `tenancy:audit` y la suite en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 10. Reporta al terminar

1. Cómo quedó la caché y **cómo probaste la fuga** — el mecanismo del test, no solo que pasa.
2. Las 10 claves con su valor por defecto y su regla, para cotejarlas con §9.1.
3. Qué hiciste con `organizations.phone` y por qué esa mitad y no la otra.
4. Cómo quedó la navegación derivada, y si al hacerlo apareció alguna capacidad mal repartida.
5. Qué forma le diste a SP-5 sin que existan catálogos, y qué tarea tiene que completarla.
6. Si algo de `REQ-ORG-006` depende de algo que aún no existe, qué y de qué tarea es.
7. Nada más. No propongas siguientes pasos.
