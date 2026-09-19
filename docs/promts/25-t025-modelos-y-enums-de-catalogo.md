# PROMPT 25 — `T-025` · Modelos y enums de catálogo

> Hito **H2 · Catálogo** · Tarea `T-025` · Fase `A` · 2 pts
> **Requisitos: `REQ-CAT-007`** (su parte estructural; la lógica de estados es `T-030`).
> Depende de `T-024` (`8a5a5b8`, las ocho tablas y el `Catalog` mínimo) y de `T-017` (`aeabbb4`, `MoneyCast`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-025: modelos y enums de catalogo`.

## 0. Qué vas a hacer

Las tablas existen desde `T-024`. Esto pone encima los **modelos y los dos enums** sobre los que van a escribir las trece tareas siguientes de H2: el producto con su dinero y su estado, la taxonomía, la ficha técnica, y el catálogo terminado con su visibilidad derivada.

Es Fase `A`: estructura, relaciones y tipos. La lógica —publicar, componer, buscar— tiene sus tareas y no es esta.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → *Contratos compartidos* y **`REQ-CAT-007`** | `ProductStatus` con sus dos métodos, y las nueve reglas que explican por qué cada columna es como es |
| [docs/specs/12-catalogos-curados.md](../specs/12-catalogos-curados.md) → *Contratos compartidos* y `REQ-CAT-021`/`022` | **`CatalogVisibility`**, que es lo más delicado de esta tarea |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → §4 bloque 5 y §4.1 | las columnas y los índices que ya están en la base, y las dos clases de exención |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-024`…`T-032` | dónde termina esta tarea y empiezan `T-026`, `T-030`, `T-031` y `T-032` |
| `app/Models/Catalog.php`, `app/Models/PaymentAccount.php`, `app/Models/User.php` | el que tienes a medias y el estilo de la casa: docblock, `$fillable`, `casts()`, `@property` |
| `app/Domain/Tenancy/BelongsToOrganization.php`, `tests/Feature/Tenancy/` | el trait, su guarda de relaciones cruzadas y la convención de que cada modelo nuevo añade su caso |

## 2. Alcance

**Sí:**

1. **`Category`**, **`Tag`**, **`Product`**, **`ProductSpecification`**, con el trait del tenant, sus `casts()`, sus relaciones y sus factories.
2. **Terminar `Catalog`**: `CatalogVisibility` derivada y sus relaciones. No lo crees — ya existe a medias.
3. **`ProductStatus`** con `isVisibleInCatalog()` y `canBeReserved()`.
4. **`CatalogVisibility`** con `isPubliclyAccessible()` y `appearsInStorefront()`.
5. Sus tests, **incluidos los casos de `tests/Feature/Tenancy`** que exige la convención.

**No:**

- **No** escribas `ChangeProductStatusAction` ni las transiciones de estado: son **`T-030`**.
- **No** escribas `scopeSearch`, `scopeInCatalog` ni `FullTextTerm`: son **`T-034`**.
- **No** escribas el CRUD de taxonomía ni `ReassignCategoryProductsAction`: son **`T-026`**.
- **No** escribas `PublishCatalogAction` ni `SyncCatalogProductsAction`: son **`T-032`** y **`T-031`**.
- **No** añadas `HasMedia` ni `InteractsWithMedia` a `Product`: las imágenes son **`T-029`**.
- **No** añadas columnas ni migraciones. Si crees que falta una, dilo en el reporte en vez de añadirla.
- **No** escribas espejos TypeScript: `ProductCard`, `ProductDetail` y `CatalogView` llegan con las pantallas que los consumen.

## 3. `CatalogVisibility` es derivada, y ahí está el riesgo

El contrato de [12] lo dice en su primera línea: **estado derivado de `is_listed`, `published_at` y `expires_at`**. No hay columna `visibility` y no la añadas. Este proyecto ya ha pagado tres veces el mismo error —una lista escrita dos veces acaba discrepando de sí misma—, y aquí la discrepancia sería peor que de costumbre: un catálogo que la columna diga `listed` y las fechas digan `expired` sigue vendiendo con precios de una campaña que terminó.

Los cuatro casos se solapan, así que **el orden de evaluación es la regla**, no un detalle de implementación:

- `published_at` nulo → `Draft`, **aunque `expires_at` ya haya pasado**: lo que nunca se publicó no puede haber expirado.
- `expires_at` en el pasado → `Expired`, por delante de `is_listed`: un catálogo caducado no aparece en la vitrina ni se sirve por enlace (`410`, `REQ-CAT-021`).
- Después, `is_listed` decide entre `Listed` y `Unlisted`. **`is_listed` no controla el acceso** (`REQ-CAT-022` RB-3): un `Unlisted` es accesible por enlace, y por eso `isPubliclyAccessible()` cubre los dos y `appearsInStorefront()` solo el primero.

Escribe la precedencia con un test por caso, incluido el cruce raro —sin publicar y con fecha de expiración pasada—, que es el que se tuerce solo.

## 4. El dinero del producto pasa por `MoneyCast`

`price_cents`, `compare_at_price_cents` y `down_payment_cents` son `BIGINT` de centavos de USD, y **este es el primer modelo del proyecto que los tiene**. Van con `MoneyCast` (`T-017`), que ya está escrito y probado: devuelve `Money` en USD, `null` cuando la columna es `null` —el caso de `compare_at_price_cents`— y **lanza si alguien le da otra moneda** (`REQ-FX-001` RB-9).

No escribas accesores que hagan cuentas con esas cifras. La cuota mostrada, el plan de pago y la proyección a bolívares tienen sus sitios (`ScheduleGenerator` en `T-041`, `ProjectsMoney` desde `T-022`) y ninguno es este.

## 5. Cinco cosas que se tuercen solas

1. **`catalog_product` no tiene `updated_at`.** Su `created_at` es funcional —ordena el feed y define qué es novedad (`REQ-CAT-023` RB-3)— y se escribe solo. Si declaras la relación con `withTimestamps()`, cada `attach()` intentará escribir una columna que no existe. Lo que necesitas es `withPivot('position', 'created_at')` y nada más.
2. **Los archivados conservan su relación con el catálogo** (`REQ-CAT-007` RB-7), para que desarchivar los devuelva sin recomponer nada. Así que la relación `Catalog → products` **no filtra por estado**: quien filtra es la consulta pública de `T-033`. Un `where('status', …)` metido en la relación desactivaría RB-7 sin que nada fallara.
3. **La visibilidad de un producto es una intersección** (RB-6): su estado **y** la accesibilidad del catálogo. Ningún método de `ProductStatus` puede contestar solo por su lado; `isVisibleInCatalog()` responde por el estado del producto y nada más, y su nombre no significa que sepa en qué catálogo está.
4. **`product_specifications.group` es palabra reservada en MySQL** y conserva ese nombre a propósito ([05 §4], nota). Laravel entrecomilla los identificadores; no lo renombres ni le pongas un alias que luego el módulo [11] no reconozca.
5. **Cada modelo nuevo del tenant añade su caso a `tests/Feature/Tenancy`** (`REQ-QAX-008` RB-2, y es convención del plan, no una sugerencia). Incluye el caso que de verdad podría filtrar: una relación que apunte a una fila de otra organización tiene que lanzar `CrossTenantRelationException`, que es lo que el trait ya hace y lo que hay que fijar para estos cuatro.

## 6. Hecho cuando

- [ ] `Category`, `Tag`, `Product` y `ProductSpecification` existen con el trait, sus `casts()`, sus relaciones y sus factories.
- [ ] `Catalog` queda terminado: `CatalogVisibility` derivada y sus relaciones; **sin columna `visibility`**.
- [ ] `ProductStatus::isVisibleInCatalog()` y `canBeReserved()` responden `REQ-CAT-007` RB-1 al pie de la letra.
- [ ] `CatalogVisibility::isPubliclyAccessible()` cubre `Listed` **y** `Unlisted`; `appearsInStorefront()` solo `Listed`.
- [ ] La **precedencia** de los cuatro casos tiene un test cada uno, incluido el no publicado con expiración pasada (§3).
- [ ] El dinero del producto va por `MoneyCast`, y `compare_at_price_cents` nulo devuelve `null`.
- [ ] La relación con `catalog_product` lleva `withPivot('position', 'created_at')` y **no** `withTimestamps()`.
- [ ] Los cuatro modelos tienen su caso en `tests/Feature/Tenancy`, con la relación cruzada lanzando.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 7. Reporta al terminar

1. Cómo derivaste `CatalogVisibility` y en qué orden evalúas los cuatro casos. Si un `published_at` **futuro** entra en la derivación, di qué hace y de dónde sacaste esa respuesta: para el producto está escrita (`REQ-CAT-007` RB-5), para el catálogo búscala antes de inventarla.
2. Qué relaciones escribiste entre `Catalog` y `Product`, y cómo evitaste el `updated_at` que no existe.
3. Qué casos añadiste a `tests/Feature/Tenancy` y cuál de ellos detectaría una fuga real si mañana alguien quitara el trait.
4. Si alguna regla de `REQ-CAT-007` o de `REQ-CAT-022` pide una columna o un método que no existe, cuál y dónde. **No lo tapes ni lo inventes**: dilo.
5. Nada más. No propongas siguientes pasos.
