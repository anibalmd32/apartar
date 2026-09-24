# PROMPT 35 — `T-034` · Filtros y búsqueda del catálogo

> Hito **H2 · Catálogo** · Tarea `T-034` · Fase `B` · 3 pts
> **Cierra `REQ-CAT-002` y `REQ-CAT-006`.**
> Depende de `T-033` (`f49881d`, la página, el cursor y `CatalogFacetsQuery` ya escrita) y de `T-024` (`8a5a5b8`, el índice `FULLTEXT`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-034: filtros y busqueda del catalogo`.

## 1. Cómo correr las pruebas

La suite corre en paralelo desde `T-003b`. El orden está medido y ahorra una pasada entera:

1. **Mientras trabajas**, solo lo que tocas: `docker compose exec -T app php vendor/bin/pest tests/Feature/Catalog`.
2. **`composer test:quality`** antes de la vuelta completa: medio minuto, y trae los fallos estructurales que si no aparecen al final.
3. **`composer check`** (ya incluye el gate de cobertura: **no lo lances aparte**) y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio, sin sondear en segundo plano.

## 2. Qué vas a hacer

El panel que `T-033` dejó deliberadamente sin pintar y el buscador que lo acompaña. Es lo que convierte un catálogo de ochenta productos en algo por lo que se puede navegar, y todo ocurre **dentro** del catálogo: no hay búsqueda global ni entre negocios, y eso no es una limitación técnica sino la consecuencia de que la taxonomía sea de cada organización.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → **`REQ-CAT-002`** y **`REQ-CAT-006`** | tus dos requisitos; RB-2 de `002` y RB-3 de `006` son los que se tuercen |
| [docs/specs/12-catalogos-curados.md](../specs/12-catalogos-curados.md) → `REQ-CAT-021` RB-5 a RB-7 | dónde encajan en la página que ya existe |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-033`, `T-034`, `T-084` | lo que heredas escrito y lo que no es tuyo |
| `app/Queries/CatalogFacetsQuery.php` | **escrita y probada por `T-033`**: cuenta las presentes y descarta las vacías. Móntala, no la reescribas |
| `app/Http/Controllers/Catalog/CatalogShowController.php`, `app/Support/CatalogCursor.php` | la página y el cursor endurecido que ya tienes |
| `app/Support/` | ahí van `FullTextTerm` y `SortWhitelist`, que **escribes tú**: [05 §8](../specs/05-design-tecnico.md) ya los declara en el árbol |

## 4. Alcance

**Sí:**

1. **`FullTextTerm`**: saneado, escapado de operadores, longitud y degradación (§5).
2. **`Product::scopeSearch()`** y **`scopeInCatalog()`**.
3. **Los filtros** de categoría (único) y etiquetas (**OR**), montando `CatalogFacetsQuery`.
4. **El orden** con sus cinco valores y su lista blanca (RB-5 de `REQ-CAT-006`).
5. **El panel y el buscador** en la página, con *debounce* de 300 ms y recarga parcial de Inertia.
6. **El estado en la query string**, compartible y reproducible.
7. Sus tests, incluidos los de acotación y fuga.

**No:**

- **No** emitas `search_performed` ni `product_list_filtered` (RB-5 de `002`, RB-8 de `006`): son **`T-084`**, y este proyecto no deja emisores que traguen eventos en silencio.
- **No** abras una ruta de categoría ni de etiqueta fuera del catálogo: RB-9 de `REQ-CAT-006` dice que **no existe**, y hay un *assert* de rutas que lo exige.
- **No** toques la ficha de producto (`T-035`) ni el backoffice.
- **No** metas Scout, Meilisearch ni sinónimos: fuera de alcance explícito.

## 5. La búsqueda tiene dos modos y un saneado que no es cosmético

**RB-2: `FULLTEXT` en modo booleano, y `LIKE` cuando el término tiene menos de tres caracteres o no devuelve nada.** Los dos modos son parte del contrato, no una optimización: el índice de MySQL ignora los términos cortos, así que sin la degradación buscar «kit» no encontraría nada y el cliente concluiría que el negocio no lo vende.

**RB-7: los operadores booleanos se escapan antes de construir la consulta.** `+ - * " ( )` tienen significado en modo booleano, así que un término con un guion —«taladro de media-pulgada»— cambia la consulta en lugar de buscarse. Y lo que empieza siendo un resultado raro termina siendo una vía para inyectar sintaxis en la consulta de búsqueda de una superficie pública.

Los tres casos que la spec fija y conviene no improvisar: término de solo signos o *stopwords* → se sanea y, si queda vacío, **se ignora el parámetro** (SP-1); más de 100 caracteres → **se trunca sin error** (SP-3); cero resultados → estado vacío **con las categorías presentes** y enlace al chat del negocio (SP-2).

## 6. Las facetas ya están escritas, y lo que falta es no contradecirlas

`CatalogFacetsQuery` la entregó `T-033` con sus tests: cuenta **solo los productos publicados de ese catálogo** y **descarta las que dan cero** (RB-3 de `REQ-CAT-006`). Móntala.

Lo que tienes que cuidar al montarla es que el panel y la parrilla no cuenten cosas distintas: si la faceta dice «Taladros (4)» y al pulsarla salen tres, el cliente deja de fiarse del panel entero, y esa desconfianza no se recupera. Los `draft` y `archived` se omiten en los dos sitios por el mismo camino —`scopePublished()`, de `T-030`—, no con dos condiciones escritas aparte.

Y **las etiquetas son `OR`, la categoría es única y excluyente** (RB-4). Es la combinación que la gente espera sin saber explicarla: más etiquetas amplían, otra categoría cambia de sitio.

## 7. Cinco cosas que se tuercen solas

1. **El `sort` inválido cae a `curated`**, no da error (RB-5 y la lista blanca). La query string la escribe cualquiera: un `sort=price_asc; DROP` tiene que ser ignorado, no interpretado.
2. **Con término hay orden por relevancia, salvo que el usuario haya elegido uno** (RB-4 de `002`). Es un orden por defecto que depende del estado, no una regla fija: quien ordenó por precio y luego busca, sigue viendo precios ordenados.
3. **Un producto de otro catálogo de la misma organización no aparece** (test de acotación) **y uno de otra organización tampoco** (test de fuga). Son dos tests distintos porque son dos fallos distintos: el primero es un `JOIN` que falta, el segundo es el aislamiento roto.
4. **La navegación usa recarga parcial de Inertia** (`only: ['products', 'facets']`) conservando el *scroll* y el estado del panel (RB-7 de `REQ-CAT-006`). Una recarga completa en cada tecla es una página que parpadea y pierde el sitio, que es justo lo que el *debounce* y el `only` existen para evitar.
5. **El cursor y los filtros conviven**: cambiar un filtro **reinicia** la paginación. Si el cursor sobrevive a un cambio de filtro, la segunda página pertenece a otra consulta y salen huecos que nadie sabe explicar. `CatalogCursor` ya rechaza lo que no reconoce; lo que te toca es no mandárselo.

## 8. Hecho cuando

- [ ] Encuentra por **nombre, SKU y con acentos**; degrada a `LIKE` con término corto o sin resultados (RB-2).
- [ ] **Escapa los operadores booleanos** (RB-7), trunca a 100 caracteres (SP-3) e ignora el término vacío tras sanear (SP-1).
- [ ] Las facetas son **solo las presentes en el catálogo**, con su conteo, y **coinciden con lo que devuelve el filtro** (§6).
- [ ] Etiquetas en **OR**, categoría **única**; `sort` inválido cae a `curated`.
- [ ] **Un producto de otro catálogo no aparece** (acotación) y **uno de otra organización tampoco** (fuga).
- [ ] La URL con filtros es **compartible y reproduce el mismo conjunto** (RB-6).
- [ ] *Debounce* de 300 ms y **recarga parcial** preservando *scroll* y panel.
- [ ] Cambiar un filtro **reinicia el cursor** (§7.5).
- [ ] Cero resultados → estado vacío con las categorías presentes (SP-2).
- [ ] **No existe ruta de categoría ni de etiqueta fuera del catálogo** (*assert* de rutas, RB-9).
- [ ] **Ningún emisor de telemetría** (§4).
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Qué hace exactamente `FullTextTerm` con «taladro de media-pulgada» y con `+"kit" -barato`, paso a paso.
2. Cómo garantizas que el número de la faceta y el resultado del filtro no puedan discrepar.
3. Qué consulta se ejecuta con término **y** filtros **y** orden explícito, y cuántas son en total para pintar una página.
4. Cómo se comporta la paginación al cambiar un filtro con el cursor ya avanzado.
5. Qué queda de `REQ-CAT-002` y `REQ-CAT-006` sin cerrar y de qué tarea es.
6. Cuánto tardó la verificación final, para seguir la serie.
7. Nada más. No propongas siguientes pasos.
