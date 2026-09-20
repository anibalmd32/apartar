# PROMPT 31 — `T-031` · Composición de catálogos

> Hito **H2 · Catálogo** · Tarea `T-031` · Fase `B` · 3 pts
> **Cierra `REQ-CAT-023`** salvo el tope de plan, que es de `T-067` (§4).
> Depende de `T-024` (`8a5a5b8`, el pivote y su `created_at` funcional), de `T-025` (`53dbf4f`, las relaciones que no filtran ni ordenan) y de `T-030` (`ef4ee33`, los estados del producto).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-031: composicion de catalogos`.

## 0. Qué vas a hacer

Qué productos entran en cada catálogo y en qué orden se ven. Es la tarea que cierra el pivote `catalog_product` que existe desde `T-024` y que hasta hoy nadie escribe.

Trae además **el arrastre de todo H2**: `useSortableProducts` es tuyo, y con él se cierran de una vez las tres listas que hoy se ordenan con botones (§5). Haz primero lo tuyo; el relevo va después y no al revés.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/12-catalogos-curados.md](../specs/12-catalogos-curados.md) → **`REQ-CAT-023`** | tus nueve reglas y ocho `SP-`; RB-3 es la que no se puede equivocar |
| Mismo documento → `REQ-CAT-022` RB-4 | por qué vaciar un catálogo publicado no puede permitirse |
| [docs/specs/15-cuentas-de-cliente-y-feed.md](../specs/15-cuentas-de-cliente-y-feed.md) → `REQ-ACC-003` RB-2 y RB-4 | **quién lee el `created_at` del pivote**, que es de otro módulo y de otro hito |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-031` y `T-067` | el arrastre que recoges y el tope de plan que no es tuyo |
| `app/Models/Catalog.php`, `app/Models/Product.php` | las relaciones, que **no filtran por estado ni ordenan**, y por qué |
| `app/Domain/Catalog/CategoryOrder.php` | el precedente de renumeración contigua, de `T-026` |
| `app/Actions/Product/UpsertProductAction.php` | cómo `T-027` resolvió la pertenencia al tenant de las etiquetas: lo mismo te toca aquí |

## 2. Alcance

**Sí:**

1. **`SyncCatalogProductsAction`**, `SyncCatalogProductsRequest` y `PATCH /backoffice/catalogos/{catalog}/productos`, con sus dos declaraciones.
2. El **buscador de productos** y **`CatalogProductSorter`** con **`useSortableProducts`**: arrastre con guardado explícito (RB-8).
3. La **validación de pertenencia al tenant** de cada `product_id` (RB-1, SP-1).
4. **El relevo del arrastre** en las otras tres listas (§5).
5. Sus tests, incluido el de fuga.

**No:**

- **No** implementes `max_products_per_catalog` (RB-6, SP-3): es de **`T-067`**, y está anotado allí porque es el punto de aplicación distinto de los otros once (§4).
- **No** escribas el CRUD del catálogo ni su publicación: son **`T-032`**.
- **No** toques la vista pública: `T-033`.
- **No** añadas reglas automáticas de composición, orden por métricas, duplicar catálogo ni destacados: fuera de alcance explícito.

## 3. `created_at` es el campo que no se puede tocar

RB-3: el `created_at` del pivote registra **cuándo entró el producto al catálogo**, y es lo que ordena el feed multi-negocio y decide qué es «novedad» (`REQ-ACC-003`). **Reordenar no lo actualiza**, porque reordenar no convierte un producto viejo en novedad.

Esto tiene una consecuencia práctica que es donde se rompe solo: **`sync()` de Eloquent no sirve tal cual**. Un `sync()` que desasocie y vuelva a asociar borra y reinserta las filas, y con ellas el `created_at` — y el síntoma no aparece aquí: aparece en H6, cuando el feed de un cliente le anuncie como novedades doce productos que llevaban meses en ese catálogo, porque el negocio reordenó su escaparate. Nadie relacionará una cosa con la otra.

Así que: las filas que siguen estando **se actualizan** (solo `position`), las que entran se insertan con su `created_at`, y las que salen se borran. SP-6 lo dice desde el otro lado: reordenar un catálogo publicado tiene efecto inmediato y **no** genera novedades ni *digests*.

Esa es la diferencia con la ficha técnica de `T-028`, donde el reemplazo completo sí era la respuesta correcta: allí las filas no tenían nada que conservar; aquí tienen una fecha que significa algo para otro módulo.

## 4. El tope de plan no es tuyo, y conviene saber por qué

RB-6 y SP-3 limitan los productos por catálogo con `max_products_per_catalog`. **No lo implementes**: las llamadas de límite se insertan en `T-067`, como en las siete tareas anteriores.

Lo que sí conviene que sepas —y que `T-067` ya tiene anotado— es que este tope es **el distinto de los doce**: no pasa por `LimitEnforcer::assertCanCreate()` porque no tiene `UsageMetric`; se cuenta por catálogo y los contadores son por organización. Si tu acción queda escrita de forma que meter ahí la comprobación sea natural —el número de filas ya lo tienes contado—, `T-067` lo agradecerá.

## 5. El arrastre de H2 se cierra aquí

`useSortableProducts` es tuyo, y con él llega la dependencia de *drag & drop* que el proyecto no tiene. Tres listas quedaron con botones de subir y bajar esperando este momento, las tres con su desviación declarada en su requisito:

| Lista | Requisito | Dónde |
|---|---|---|
| Categorías | `REQ-BOF-023` RB-2 (`T-026`) | `PATCH /backoffice/categorias/orden` |
| Filas de la ficha técnica | `REQ-BOF-021` RB-1 (`T-028`) | dentro del guardado del producto |
| Galería de imágenes | `REQ-BOF-022` RB-5 (`T-029`) | `PATCH .../imagenes/orden` |

**Ninguna cambia de endpoint ni de *payload***: las tres mandan ya la lista entera en su orden, que es justo lo que hace que el gesto sea sustituible. Si te ves tocando un controlador o una acción para esto, párate: eso significa que el contrato no era el que creíamos y hay que decirlo en el reporte, no arreglarlo de paso.

Y aquí el guardado es **explícito** (RB-8): arrastrar no guarda, hay un botón. En las otras tres, respeta lo que cada una ya haga.

## 6. Cinco cosas que se tuercen solas

1. **El producto de otra organización no existe, y aun así hay que rechazarlo** (RB-1, SP-1). El *global scope* hace que no aparezca al buscarlo —de ahí que el id simplemente no resuelva—, pero el pivote **no lleva `organization_id`**: está declarado así desde `T-024` con el argumento de que la `Action` valida (`REQ-ORG-002` RB-7). Esa validación es esta, y su test de fuga con dos organizaciones es la razón por la que el pivote puede seguir sin columna.
2. **Los `draft` se admiten** (RB-5): preparar una campaña completa antes de lanzarla es el caso de uso. Lo que no se renderiza lo decide la consulta pública de `T-033`, no tú.
3. **Vaciar un catálogo publicado es `422`; en `Draft` se permite** (SP-7). Es la misma familia que `REQ-CAT-022` RB-4: un catálogo publicado y vacío es un enlace compartido que no enseña nada.
4. **Los identificadores repetidos se deduplican por su primera aparición** (SP-2), y **`position` queda contiguo desde 1** (RB-2), renumerado en el servidor. Ya es la tercera lista del proyecto con esta regla; míra cómo lo hace `CategoryOrder` antes de escribirlo distinto.
5. **Quitar un producto del catálogo no afecta a los apartados** (RB-9, SP-5). Hoy no hay apartados: lo que te toca es no escribir ningún recorrido que los busque.

## 7. Hecho cuando

- [ ] `PATCH /backoffice/catalogos/{catalog}/productos` existe con **superficie** y **supervivencia a suspensión** declaradas.
- [ ] **`created_at` del pivote NO cambia al reordenar**, con test explícito (§3).
- [ ] `position` **contiguo desde 1**, recalculado en el servidor (RB-2).
- [ ] **Identificadores duplicados deduplicados** por primera aparición (SP-2).
- [ ] **Producto de otra organización rechazado**, con test de fuga entre dos organizaciones (RB-1, SP-1).
- [ ] **Producto inexistente o archivado → `422` sin aplicar nada**, en transacción (SP-1).
- [ ] **`draft` admitido** (RB-5); **vaciar un catálogo publicado rechazado** y en `Draft` permitido (SP-7).
- [ ] Un producto **en varios catálogos del mismo negocio** funciona (SP-8).
- [ ] El arrastre guarda **solo al pulsar** (RB-8).
- [ ] **Las tres listas de §5 pasan al arrastre**, sin tocar sus endpoints ni sus *payloads*.
- [ ] Ninguna llamada de límite de plan (§4).
- [ ] `tenancy:audit` y la suite de tenancy en verde, con el caso de fuga nuevo.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Qué escribe exactamente tu acción en el pivote en un guardado que reordena, añade uno y quita otro — fila por fila.
2. Cómo pruebas que el `created_at` sobrevive, y por qué ese test no pasaría con un `sync()` normal.
3. Cómo quedó la validación de pertenencia, y qué ve quien manda el id de un producto del negocio de al lado.
4. Cómo fue el relevo del arrastre en las otras tres listas: si alguna necesitó tocar servidor, cuál y por qué.
5. Si `REQ-CAT-023` te pidió algo que hoy no se puede hacer, cuál y de qué tarea es. **No lo des por hecho.**
6. Nada más. No propongas siguientes pasos.
