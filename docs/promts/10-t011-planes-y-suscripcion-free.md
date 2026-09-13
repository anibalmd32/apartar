# PROMPT 10 — `T-011` · Planes y suscripción Free

> Hito **H0 · Cimientos** · Tarea `T-011` · Fase `AB` · 2 pts
> Cierra `REQ-SUB-001` y `REQ-SUB-002`.
> Depende de `T-004` (esquema de plataforma) y `T-009` (`65ab324`, policies).

> ⚠️ **Se ejecuta antes que `T-010`, y es deliberado.** El registro de organización crea la suscripción Free en la misma transacción, así que necesita que el plan exista. El plan de tareas ya lo declara en la línea "Depende de" de `T-010`.

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 87 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

## 0. Qué vas a hacer

El **modelo de negocio en forma de datos**. Tres planes, siete límites numéricos, un puñado de funciones booleanas, y el alta automática en la capa gratuita.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/50-planes-y-suscripciones.md](../specs/50-planes-y-suscripciones.md) → **`REQ-SUB-001`** y **`REQ-SUB-002`** | los dos requisitos completos |
| [docs/specs/03-pivote-saas-impacto.md](../specs/03-pivote-saas-impacto.md) **§5** | la **matriz de planes aprobada**, que es la fuente exacta del seeder |
| [docs/specs/03-pivote-saas-impacto.md](../specs/03-pivote-saas-impacto.md) **§4.6** | por qué los suscriptores son ilimitados. No es un descuido |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§4 bloques 1 y 2** | columnas de `plans` (ya migrada) y de `subscriptions`/`usage_counters` (las creas tú) |

## 2. Alcance

**Sí:**

1. Migraciones del **bloque 2, parcial**: `subscriptions` (con `UNIQUE(organization_id)`) y `usage_counters` (con `UNIQUE(organization_id, metric)`). Las otras tres tablas del bloque llegan en H5.
2. **`Plan`**, **`Subscription`**, **`SubscriptionStatus`**.
3. **`PlanFeature`** y **`UsageMetric`** con sus mapas.
4. **`PlanSeeder`** con la matriz **exacta** de §5, idempotente por `UNIQUE(code)`.
5. **`StartFreeSubscriptionAction`** y **`MissingFreePlanException`**.
6. Migrar **`SubscriptionPolicy`** de gates con nombre a descubrimiento por convención, ahora que `Subscription` existe. `T-009` lo dejó apuntado.

**No:**

- **No** implementes `LimitEnforcer` ni la medición de consumo (`REQ-SUB-003`, H5). Aquí se crea la tabla de contadores y se inicializa en cero; quien los lee y los hace cumplir llega después.
- **No** crees la página `/precios` ni `PricingController`: es de su propio hito. `REQ-SUB-001` RB-8 te dice que se renderiza desde la BD — respétalo cuando llegue, no lo adelantes.
- **No** implementes cambio de plan, facturación ni impago (`REQ-SUB-005`…`008`, H5).
- **No** toques el registro de organización: es `T-010`, la tarea siguiente.

## 3. Las cuatro decisiones que no se deducen de la matriz

**1. Los planes son datos, no código** (RB-1). El código **nunca** compara contra `'pro'` para decidir un límite: consulta el plan. Esto suena obvio y se incumple solo: en cuanto aparece un `if ($plan->code === 'free')` en cualquier sitio, la matriz deja de ser ajustable sin desplegar y hay dos fuentes de verdad.

**2. Los suscriptores de catálogo son ilimitados en los tres planes, y no son una métrica de consumo** (RB-4). Es tentador añadir un `max_subscribers` por simetría con los demás límites. No lo hagas: §4.6 lo explica — el número de suscriptores es lo que hace valioso el producto, y cobrarlo frenaría lo único que crece solo. La palanca de pago es **avisar** a los suscriptores, no tenerlos.

**3. `NULL` significa ilimitado, y la UI lo dice con esa palabra** (RB-2, SP-2). Ni `0`, ni "sin definir". Un `0` mostrado donde va "ilimitado" convierte el mejor plan en el peor.

**4. La suscripción Free nace `active`, sin factura y con `current_period_end` en `null`** (RB-2). No hay prueba gratuita de plan superior, y eso es una decisión de producto, no una simplificación: la capa gratuita **es** la prueba, y así nadie pierde acceso a su inventario porque se le acabó un trial. Si escribes una fecha de fin para Free, estás reintroduciendo el acantilado que el diseño evita.

## 4. Dónde va cada fila de la matriz

`plans` tiene **siete columnas `max_*`** y un `features JSON`. El reparto no es arbitrario:

- Las **siete primeras filas numéricas** de §5 son las siete columnas y son las siete `UsageMetric`: usuarios, catálogos, productos, productos por catálogo, apartados activos, almacenamiento, cuentas de cobro.
- Todo lo demás va en **`features`**: las booleanas (avisos a suscriptores, branding, exportación, Web Push, dominio propio, sello "Hecho con Apartar") y las que llevan número pero **no son un techo que se alcance** (retención de telemetría en días, historial de chat, nivel de analítica).

La diferencia entre las dos categorías es útil: una `UsageMetric` es algo que la organización **consume** y que un día se le acaba; una `PlanFeature` es algo que **tiene o no tiene**. Si dudas de dónde va algo, pregúntate si tiene sentido decir "te quedan 3".

Ojo con el almacenamiento: la matriz lo da en MB y GB (200 MB, 5 GB, 25 GB) y la columna es `max_storage_mb`. Conviértelo.

## 5. Hecho cuando

- [ ] El seeder crea los **tres planes con la matriz exacta** de §5, y es **idempotente** (ejecutarlo dos veces no duplica ni altera).
- [ ] `NULL` se interpreta como ilimitado en todo el camino, incluido el tipo TS.
- [ ] Una organización tiene **exactamente una** suscripción; un segundo intento es una excepción de dominio, no un error de base de datos crudo.
- [ ] La suscripción Free nace `active`, **sin factura** y con `current_period_end` en `null`.
- [ ] Los contadores de consumo se inicializan **en cero**.
- [ ] **Sin plan Free en la base, la acción falla** con `MissingFreePlanException` y un mensaje explícito, en lugar de crear una organización sin plan.
- [ ] `SubscriptionPolicy` se descubre por convención y sus tests de `T-009` siguen en verde.
- [ ] Ningún `if` compara contra el código de un plan.
- [ ] `tenancy:audit` y la suite de tenancy en verde; toda ruta nueva declara su superficie.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 6. Reporta al terminar

1. La matriz tal como quedó sembrada, para poder cotejarla con §5 de un vistazo.
2. Qué filas acabaron en `features` y con qué forma, en particular las que llevan número.
3. Cómo quedó `UsageMetric` y qué relación tiene con las siete columnas.
4. Qué cambió al migrar `SubscriptionPolicy` a descubrimiento por convención, y si algún test de `T-009` tuvo que tocarse.
5. Si algo de la matriz de §5 no encajó en el esquema de `T-004`, qué y cómo lo resolviste. **No alteres la matriz para que encaje**: repórtalo.
6. Nada más. No propongas siguientes pasos.
