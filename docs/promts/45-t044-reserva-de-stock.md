# PROMPT 45 — `T-044` · Reserva de stock

> Hito **H3 · Apartado** · Tarea `T-044` · Fase `B` · 2 pts
> **Cierra `REQ-LAY-003`** y **la mitad que `T-027` dejó pendiente de `REQ-BOF-020` SP-6** (§6).
> Depende de `T-042` (`d626001`, la máquina de estados y el modelo `Layaway`), `T-043` (`7457a58`, `ResolveCustomerAction` y su bloqueo) y `T-027` (el CRUD de productos y su formulario).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-044: reserva de stock`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo tuyo. El caso de SP-1 es una carrera real: va en `tests/Feature/Concurrency/` (§5).
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio. **Una sola vuelta completa.**
5. **Si a tus acciones les falta cobertura y lo que falta es el camino de la carrera, déjalo y dilo en el informe.** La ronda de concurrencia corre sin instrumentar, así que ese hueco es esperado; taparlo con un caso no concurrente sube el número y no prueba nada. Está razonado en [91](../specs/91-calidad-y-gates.md).

## 2. Qué vas a hacer

Que un apartado inmovilice la unidad. Son dos acciones cortas y un bloqueo, y es la pieza donde **el producto físico y la base de datos tienen que contar lo mismo**: si el stock miente, el negocio vende dos veces la misma nevera y descubre el problema cuando el segundo cliente va a buscarla.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/20-apartado-y-cronograma.md](../specs/20-apartado-y-cronograma.md) → **`REQ-LAY-003`** | tu requisito entero; **RB-1, RB-2, RB-8 y SP-2** son los que se tuercen |
| el mismo fichero → `REQ-LAY-001` paso 4 **y la nota que lo sigue** | el orden de bloqueo, corregido antes de esta tarea (§4) |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → §5.1 | la secuencia canónica, con el orden anotado en el propio diagrama |
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → `REQ-BOF-020` SP-6 y RB-9 | la mitad que cierras, y dónde vive el listado (§6) |
| `tests/Feature/Concurrency/ResolveCustomerConcurrencyTest.php` | **el patrón exacto** de carrera con dos conexiones, de `T-043` |
| `app/Domain/Layaway/LayawayStatus.php` → `isTerminal()` | qué apartados cuentan como reserva viva (§6) |
| `app/Http/Controllers/Backoffice/ProductController.php` y su listado | donde va el aviso y el dato calculado |

## 4. El orden de bloqueo es toda la tarea, y su fallo no sale en los tests

RB-8: **producto primero, cliente después**, en todas las acciones. El texto del paso 4 de `REQ-LAY-001` decía lo contrario y **se corrigió antes de escribirte esto**; manda [05 §5.1], que lo anota en el propio diagrama.

Por qué importa tanto aquí: `ResolveCustomerAction` toma un `FOR UPDATE` sobre la ficha del cliente al reintentar tras una colisión (`T-043`). Dos transacciones que tomen producto y cliente en orden distinto se abrazan. Y —esto es lo que hay que tener presente— **ese fallo no aparece en la suite**: un abrazo mortal solo se observa entre transacciones confirmadas, así que un orden invertido pasa todos los tests ordinarios y se manifiesta en producción, bajo carga, como un `1213` intermitente sin causa aparente.

Consecuencia práctica: el bloqueo del producto se toma **antes que cualquier otra fila del tenant**, y `ReserveStockAction` lo vuelve a pedir y lo obtiene, porque en la misma transacción es reentrante. No te fíes de que el llamante ya lo tenga: pídelo, y que sea la transacción la que decida.

## 5. La carrera de SP-1 se provoca, no se simula

Dos apartados de la última unidad: el bloqueo serializa, uno crea y el otro recibe `InsufficientStockException`. Ese caso va en `tests/Feature/Concurrency/`, con **dos conexiones reales** y el orden forzado a mano. `T-043` acaba de dejarte el patrón en `ResolveCustomerConcurrencyTest`, incluido el barrido manual de filas.

Lo que no vale es un test que llame dos veces a la acción en la misma transacción: eso no serializa nada y pasa siempre. Desde `T-034` la carpeta de concurrencia es «lo que confirma de verdad», y esta es exactamente la clase de garantía que solo existe si se prueba ahí.

Mide además una cosa y dilo: **quita el `FOR UPDATE` y comprueba que el test se pone rojo.** Si no se pone, el test no está probando el bloqueo, y es mejor saberlo ahora que creer que sí.

## 6. La alerta de SP-2 cierra una mitad que lleva abierta desde `T-027`

`REQ-BOF-020` SP-6 dice que bajar el stock por debajo de lo ya reservado **se permite, con aviso** —puede haber pérdida física, y cancelar apartados de clientes que ya pagaron sería mucho peor—. `T-027` implementó el «se permite» y no el «con aviso», porque entonces no había reservas que contar. Ahora las hay.

Dos piezas:

1. **El dato calculado** de RB-6: las unidades reservadas son la suma de `quantity` de los apartados **no terminales** de ese producto. Es **calculado, no columna** — una columna sería un segundo censo del mismo hecho, derivable y capaz de discrepar. Usa `LayawayStatus::isTerminal()`, que ya existe, y no escribas una segunda lista de estados.
2. **Dónde se enseña.** En el formulario de producto, junto al stock, y en el listado de inventario. Ese listado pasó a tabla server-driven en `T-038`: **el dato reservado no puede ser una consulta por fila.** Si te descubres con un `N+1`, agrégalo en la consulta del listado.

Y una ambigüedad de SP-2 que **decides tú y escribes**: dice «el stock queda en 0». Aclara qué significa exactamente cuando un negocio pone `stock = 1` con 3 unidades reservadas —qué guarda la columna, qué enseña la pantalla como disponible, y qué dice la alerta— porque las tres cosas pueden ser números distintos y ahora mismo la frase admite dos lecturas.

## 7. Cinco cosas que se tuercen solas

1. **Se reserva al crear, no al activar** (RB-1). Es la diferencia entre inmovilizar la unidad y no inmovilizarla: si esperas a la inicial, dos clientes apartan la última y solo uno paga.
2. **Se libera al expirar y al cancelar, nunca al completar** (RB-2). Completar significa que la unidad se entregó; devolverla al stock inventa una nevera.
3. **La liberación es idempotente por `stock_released_at`** (SP-3), porque el job se reintenta. Y funciona **con la organización suspendida** (SP-7): devolver stock es mantenimiento de datos, no una operación de negocio que el impago del negocio pueda bloquear.
4. **`stock = 0` no cambia el estado del producto** (RB-5): sigue `published` y visible con el badge «Agotado» que `T-033` ya pinta. Despublicarlo automáticamente le quitaría al negocio una decisión que es suya.
5. **El bloqueo no alcanza productos de otra organización** (RB-7). El *global scope* lo garantiza; el test de fuga afirma que sigue garantizándolo cuando lo que se pide es un `FOR UPDATE`, que es donde más apetece escribir un `DB::table()`.

## 8. Hecho cuando

- [ ] **Concurrencia real con dos transacciones sobre la última unidad** (SP-1), en `tests/Feature/Concurrency/`, y **se pone roja al quitar el `FOR UPDATE`** (§5).
- [ ] **`stock` nunca queda negativo** (RB-4), comprobado bajo el bloqueo.
- [ ] **Liberación idempotente** por `stock_released_at` (SP-3), y **funciona con la organización suspendida** (SP-7).
- [ ] **Expirar y cancelar devuelven stock; completar no** (RB-2).
- [ ] **El bloqueo no alcanza productos de otra organización** (test de fuga, RB-7).
- [ ] **Orden canónico respetado**: producto antes que cualquier otra fila del tenant (RB-8, §4), con un test que lo afirme.
- [ ] **Las unidades reservadas se calculan de los apartados no terminales** (RB-6), por `isTerminal()` y **sin columna nueva**.
- [ ] **La alerta de SP-2 / `REQ-BOF-020` SP-6 aparece** en el formulario y en el listado, **sin `N+1`** (§6).
- [ ] La ambigüedad de «el stock queda en 0» **resuelta y escrita en la spec** (§6).
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Cómo provocaste la carrera de SP-1, y qué pasó exactamente al quitar el `FOR UPDATE`.
2. Qué guarda la columna, qué enseña la pantalla y qué dice la alerta cuando hay `stock = 1` con 3 reservadas, y qué escribiste en la spec.
3. Cuántas consultas hace el listado de inventario ahora, y cuántas hacía antes de añadir el dato reservado.
4. Cómo garantizas el orden canónico de bloqueo, y qué se pone rojo si alguien lo invierte.
5. Qué queda de `REQ-LAY-003` sin cerrar y de qué tarea es.
6. Cuánto tardó la verificación final, **cuántas vueltas completas** hiciste, y si dejaste algún hueco de cobertura por el camino de concurrencia.
7. Nada más. No propongas siguientes pasos.
