# PROMPT 28 — `T-028` · Ficha técnica dinámica

> Hito **H2 · Catálogo** · Tarea `T-028` · Fase `B` · 3 pts
> **Cierra `REQ-BOF-021`** y la parte de **`REQ-CAT-004`** que no necesita la página pública (§4).
> Depende de `T-027` (`cc708ec`, `UpsertProductAction` y el formulario de producto) y de `T-025` (`53dbf4f`, el modelo `ProductSpecification`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-028: ficha tecnica dinamica`.

## 0. Qué vas a hacer

Las filas de «característica: valor» que describen un producto — dimensiones, material, potencia—, agrupables y ordenables, que el negocio escribe en el mismo formulario que ya existe y que el cliente verá en la ficha pública.

Parece un CRUD anidado y no lo es: **el guardado es un reemplazo completo** y **el orden lo fija el arrastre**, no el índice del array del formulario. Las dos decisiones están en la spec y las dos tienen una razón concreta.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → **`REQ-BOF-021`** | tus ocho reglas y ocho `SP-` |
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → **`REQ-CAT-004`** | cómo se agrupa y se ordena del lado que lo lee |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-028` y `T-035` | el reparto con la página pública, que está escrito |
| `app/Actions/Product/UpsertProductAction.php` | **tu llamante**: la ficha se guarda dentro de su transacción (RB-8) |
| `resources/js/features/products/hooks/useProductForm.ts`, `.../components/` | el formulario en el que entras, y el patrón de `@tanstack/react-form` |
| `app/Domain/Catalog/TaxonomyByName.php`, `app/Domain/Catalog/CategoryOrder.php` | dos precedentes tuyos: resolución dentro de la transacción y renumeración contigua |

## 2. Alcance

**Sí:**

1. **`SyncSpecificationsAction`**, invocada desde `UpsertProductAction` dentro de su transacción.
2. La validación de las filas (`ValidatesSpecifications`) y **`SpecificationGroupsQuery`** para el autocompletado.
3. **`SpecificationBuilder`** y **`useSpecificationRows`** en el formulario de producto.
4. **`SpecificationTable`** con sus tests de Vitest, incluido el de XSS (§4).
5. La **agrupación** de `REQ-CAT-004` RB-2 y RB-3, escrita donde `T-035` pueda llamarla.
6. Sus tests, incluido el de fuga del autocompletado.

**No:**

- **No** escribas `ProductDetailResource` ni la página pública del producto: son **`T-035`**.
- **No** abras un endpoint propio para la ficha: RB-8 dice que se guarda **con el producto**, en la misma operación.
- **No** añadas plantillas por categoría, tipado por especificación ni reutilización entre productos: están fuera de alcance explícito.
- **No** toques las imágenes (`T-029`) ni los estados (`T-030`).

## 3. Reemplazo completo, y por qué no es pereza

RB-5: el guardado **borra y reinserta**, no hace un diff fila por fila. Un diff sobre una lista que se reordena tiene que distinguir «esta fila cambió de texto» de «esta fila cambió de sitio» de «esta fila es nueva y aquella se fue», y cada uno de esos casos es un estado intermedio en el que la ficha de un producto puede quedarse a medias si algo falla. El reemplazo tiene un solo estado intermedio y está dentro de la transacción: SP-4 exige que un fallo a mitad deje **las especificaciones anteriores íntegras**.

De ahí salen dos consecuencias que la spec ya declara y que conviene no «mejorar»:

- **SP-7: gana el último, sin *merge*.** Dos personas guardando el mismo producto no negocian filas.
- **RB-4: `position` se recalcula contiguo desde 1 en cada guardado.** No se conserva el número que traía la fila ni se usa el índice del array del formulario como verdad: el orden lo fija el arrastre y el servidor lo normaliza, igual que `CategoryOrder` hace con las categorías desde `T-026`.

## 4. El reparto con la página pública

`REQ-CAT-004` describe cómo se **lee** la ficha, y su gate nombra `ProductDetailResource`, que es de `T-035`. El reparto está escrito en el plan:

- **Tuyo:** la agrupación de RB-2 y RB-3 —`group = null` primero bajo el rótulo «General», dentro de cada grupo por `position` y a igualdad por `id`, y la agrupación **insensible a mayúsculas** de SP-2 mostrando la primera variante encontrada— en un sitio que `T-035` pueda llamar, no dentro de un *resource* que todavía no existe.
- **Tuyo también:** `SpecificationTable` con sus tests de Vitest. Es el mismo reparto que `T-022` hizo con `MoneyLabel`: la pieza y su prueba ahora, la página que la monta en su tarea.
- **De `T-035`:** el *resource* y la página.

Y el escapado (RB-4, SP-4 de `REQ-CAT-004`) tiene dos mitades que no se confunden: **se persiste como texto tal cual** —no sanees al guardar, que es lo que convierte un `<` legítimo de una medida en otra cosa— y **se escapa al renderizar**, que en React es el comportamiento por defecto siempre que nadie escriba `dangerouslySetInnerHTML`. El test de XSS afirma justo eso.

## 5. Cinco cosas que se tuercen solas

1. **El autocompletado de grupos es una fuga esperando a ocurrir.** RB-6 y SP-8: se sugieren los grupos ya usados **en otros productos de la misma organización**, nunca los del negocio de al lado. `SpecificationGroupsQuery` va por el *global scope*, y el test de fuga con dos organizaciones no es opcional.
2. **Las filas nuevas no tienen `id` de base** (SP-6). El orden en el cliente se mantiene con una clave local, no con el `id`: si el arrastre depende de un `id` que aún no existe, reordenar antes de guardar mezcla las filas nuevas.
3. **Cero filas es válido** (SP-3): borra todas y la ficha omite la sección. No lo trates como «no hay nada que hacer» y te saltes el borrado, porque entonces quitar la última fila no la quita.
4. **El límite de 50 es técnico, no de plan** (RB-3). No llames a `LimitEnforcer` ni lo enmarques como algo que se desbloquea pagando: es `422` y punto.
5. **El `label` duplicado dentro de un grupo se permite** (RB-7), y solo se avisa en la pantalla. Hay fichas legítimas con la misma etiqueta en unidades distintas; rechazarlo en el servidor sería decidir por el negocio cómo describe lo que vende.

## 6. Hecho cuando

- [ ] La ficha se guarda **dentro de la transacción** de `UpsertProductAction`, sin endpoint propio (RB-8).
- [ ] **Posiciones contiguas desde 1** tras reordenar, recalculadas en el servidor (RB-4).
- [ ] `label` vacío → `422` **señalando el índice de la fila** (SP-1); `label` > 60 o `value` > 500 → `422` (RB-2).
- [ ] **51 filas rechazadas** (RB-3, SP-2).
- [ ] **0 filas borra todas** y no falla (SP-3).
- [ ] **Rollback conserva las anteriores íntegras** (SP-4), con test.
- [ ] El **autocompletado de grupos** no cruza organizaciones (test de fuga, SP-8).
- [ ] La **agrupación** cumple RB-2, RB-3 y la comparación *case-insensitive* de SP-2, en un sitio que `T-035` pueda llamar.
- [ ] `SpecificationTable` renderiza y **escapa el HTML**, con test de Vitest.
- [ ] `tenancy:audit` y la suite de tenancy en verde, con el caso nuevo si lo hay.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 7. Reporta al terminar

1. Dónde dejaste la agrupación para que `T-035` la llame sin reescribirla, y por qué ahí.
2. Cómo se comporta el reemplazo cuando el producto no cambia pero sus filas sí, y qué escribe exactamente en la base.
3. Qué hace el arrastre con las filas nuevas sin `id`, y qué pasa si alguien reordena, borra una y guarda sin soltar la pantalla.
4. El escapado: dónde **no** saneas y dónde sí, y qué afirma tu test de XSS.
5. Si `REQ-BOF-021` o `REQ-CAT-004` te pidieron algo que hoy no se puede hacer, cuál y de qué tarea es. **No lo des por hecho.**
6. Nada más. No propongas siguientes pasos.
