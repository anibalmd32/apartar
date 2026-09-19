# PROMPT 26 — `T-026` · Taxonomía

> Hito **H2 · Catálogo** · Tarea `T-026` · Fase `B` · 2 pts
> **Cierra `REQ-BOF-023`.**
> Depende de `T-025` (`53dbf4f`, `Category`, `Tag` y `Product` con sus relaciones) y de `T-024` (`8a5a5b8`, los índices que sostienen RB-3 y RB-8).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-026: taxonomia`.

## 0. Qué vas a hacer

Las categorías y las etiquetas con las que un negocio organiza su inventario: su CRUD, su orden manual, su reutilización al vuelo y la reasignación que hace falta para poder borrar una categoría que tiene productos dentro.

Es la **primera tarea de H2 que escribe rutas y pantallas**, así que arrastra las dos declaraciones por ruta y su caso de tenancy. Nada de esto es nuevo en el repositorio: cópialo de `T-015` (cuentas de cobro), que es el CRUD del tenant más parecido al tuyo.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → **`REQ-BOF-023`** | tus nueve reglas y siete `SP-`, y la nota que resuelve lo de la auditoría |
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → `REQ-CAT-006` | para qué sirve la taxonomía del otro lado: los filtros del catálogo público |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → §4 bloque 5 y §7.4 | los índices que ya existen y dónde va tu grupo de rutas |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-024`…`T-027` | lo que heredas y lo que no es tuyo |
| `app/Actions/Organization/UpsertPaymentAccountAction.php`, `app/Http/Controllers/Backoffice/PaymentAccountController.php`, `routes/backoffice.php` | el patrón vigente: acción, datos, excepción de dominio, declaraciones de ruta |
| `app/Domain/Payment/PaymentAccountInUseException.php` | **el precedente exacto** de tu `CategoryInUseException`: una operación que se resuelve de otra forma se señala con excepción, no con un booleano que se ignora sin escribir una línea |

## 2. Alcance

**Sí:**

1. **CRUD de `/backoffice/categorias` y `/backoffice/etiquetas`**, con sus controladores, sus `FormRequest` y **las dos declaraciones por ruta**.
2. **`ReassignCategoryProductsAction`** y **`CategoryInUseException`**.
3. El **orden manual** de categorías (RB-2) y `Category::scopeOrdered()` con su `withCount`.
4. La **reutilización por nombre** de RB-8, que también sirve al formulario de producto de `T-027`.
5. Las pantallas del backoffice para las dos, y sus tests —incluidos los de `tests/Feature/Tenancy`—.

**No:**

- **No** escribas el formulario de producto ni `UpsertProductAction`: son **`T-027`**. Lo que sí es tuyo es dejar lista la reutilización que ese formulario va a llamar.
- **No** construyas la tabla genérica server-driven: es **`T-038`**. Aquí una lista sencilla.
- **No** audites nada (§4).
- **No** llames a `LimitEnforcer`: RB-9 dice que la taxonomía **no consume métrica de plan**, y son metadatos.
- **No** toques los filtros del catálogo público (`REQ-CAT-006`): son de `T-034`.
- **No** añadas jerarquía de categorías, fusión de etiquetas ni taxonomía sugerida: están fuera de alcance explícito.

## 3. Las dos asimetrías que definen el requisito

**La categoría con productos no se borra; la etiqueta sí.** RB-4 y RB-5 no son una inconsistencia: un producto **tiene** una categoría —la FK es `restrict` desde `T-024`— y quitársela lo dejaría sin sitio; una etiqueta es una marca suelta que se desasocia sin que el producto pierda nada. Así que borrar una categoría con productos es `422` con el conteo y la sugerencia de reasignar (SP-1), y borrar una etiqueta usada funciona siempre, incluso si alguien tiene ese filtro en un enlace compartido (SP-4: el filtro se ignora).

**El nombre se edita libremente; el `slug` no.** RB-6: renombrar **no** toca el `slug`, porque los filtros ya compartidos por URL se conservan. Y RB-3 + SP-3: cambiar el `slug` de una categoría con productos **publicados** es `422`. Si renombrar arrastrara el `slug`, cada corrección de una errata rompería en silencio los enlaces que el negocio ya repartió — que es el mismo daño que `REQ-ORG-006` SP-5 evita para la vitrina.

## 4. La auditoría: no la escribas

El gate de `REQ-BOF-023` menciona «auditoría» dentro de `ReassignCategoryProductsAction`, y **eso no crea un evento**. La lista de `REQ-BOF-005` RB-1 es **cerrada** y no tiene ninguno de taxonomía: su bloque de Catálogo cubre el precio, el financiamiento y el estado de un producto y la publicación de catálogos. Mover productos de una categoría a otra no cambia lo que se vende, ni a qué precio, ni quién puede verlo. Está resuelto y escrito en [31](../specs/31-backoffice-inventario.md), junto al gate.

Lo que sostiene ese anti-`SHALLOW` es lo demás: el lote, la validación de pertenencia y la transacción.

## 5. Cinco cosas que se tuercen solas

1. **La reutilización de RB-8 es una carrera, y el índice existe para eso.** `UNIQUE(organization_id, name)` se añadió en `T-024` precisamente porque dos peticiones simultáneas con el mismo nombre crean dos categorías si solo lo comprueba la aplicación. Un `firstOrCreate` bajo concurrencia **lanza** `UniqueConstraintViolationException`, y la respuesta correcta es releer la fila que ganó, no propagar el error: el usuario pidió «esta categoría», y existe. La colación por defecto es *case-insensitive*, así que «Ropa» y «ropa» son la misma — que es lo que RB-8 quiere decir con «si el nombre ya existe, se reutiliza».
2. **La categoría a la que reasignas tiene que ser de la misma organización**, y comprobarlo es la mitad de la acción. El *global scope* ya hace que la ajena no exista al buscarla —de ahí el `404` de SP-7—, pero la acción recibe un id y tiene que resolverlo por consulta del tenant, no por `find()` sin filtrar.
3. **El conteo de RB-7 incluye los `draft`.** Lo dice la regla; `withCount` los incluye solos. Lo que sí tienes que decidir —y decir en el reporte— es qué haces con los **archivados**, que la regla no nombra: son visibles en el backoffice y siguen teniendo categoría, pero ya no se venden.
4. **El reordenamiento concurrente lo gana el último** (SP-5), y eso es una decisión, no una omisión: dos personas arrastrando la misma lista a la vez no tienen conflicto que resolver, tienen un orden que acaba siendo el de quien soltó después. No montes bloqueo optimista para esto.
5. **`position` tiene que quedar contiguo y sin huecos** después de crear, borrar o reordenar. Si borrar deja un hueco, el arrastre siguiente calcula posiciones sobre una lista que no coincide con lo que se ve, y el resultado es un orden que "salta" sin motivo aparente.

## 6. Hecho cuando

- [ ] CRUD completo de categorías y etiquetas, con **superficie** y **supervivencia a suspensión** declaradas en cada ruta.
- [ ] Borrar una categoría con productos → `422` con el conteo, vía `CategoryInUseException` (SP-1).
- [ ] **Reasignar y luego borrar funciona**, en una transacción.
- [ ] Borrar una etiqueta la desasocia de sus productos y no falla (RB-5, SP-4).
- [ ] **El mismo nombre de categoría en dos organizaciones se permite** (test de fuga) y en la misma da `422` (SP-2).
- [ ] La creación al vuelo **reutiliza** por nombre, incluida la carrera de §5.1 (RB-8, SP-6).
- [ ] Renombrar **no** cambia el `slug`; cambiar el `slug` con productos publicados da `422` (RB-6, SP-3).
- [ ] La categoría de otra organización da `404` (SP-7).
- [ ] El orden se persiste y `position` queda **contiguo**; el conteo de RB-7 se muestra.
- [ ] **Nada se audita** y **nada llama a `LimitEnforcer`** (§4, RB-9).
- [ ] `tenancy:audit` y la suite de tenancy en verde, con los casos nuevos añadidos.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 7. Reporta al terminar

1. Cómo resolviste la carrera de la reutilización por nombre, y qué pasa exactamente cuando dos peticiones simultáneas piden la misma categoría nueva.
2. Qué hiciste con los productos **archivados** en el conteo de RB-7, y con qué argumento.
3. Cómo garantizas que `position` queda contiguo después de borrar, y qué pasa si dos personas reordenan a la vez.
4. Si alguna regla de `REQ-BOF-023` te pidió algo que el modelo o la base no soportan, cuál y dónde. **No lo tapes**: dilo.
5. Nada más. No propongas siguientes pasos.
