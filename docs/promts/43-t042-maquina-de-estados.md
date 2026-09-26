# PROMPT 43 — `T-042` · Máquina de estados

> Hito **H3 · Apartado** · Tarea `T-042` · Fase `B` · 3 pts
> **Cierra `REQ-LAY-005`.**
> Depende de `T-039` (`a5dc9ad`, la tabla `layaways`), `T-041` (`ef24c7e`, el dominio del cronograma). `UsageMetric`, `LimitEnforcer` y `usage_counters` ya están escritos de H0; `app/Events/` no existe todavía y lo creas tú.

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-042: maquina de estados`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo tuyo. Hay un caso de concurrencia: va en `tests/Feature/Concurrency/`, que corre en serie (§7.3).
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio. **Una sola vuelta completa.**
5. Si cambias algo **después** de pasar una suite, esa suite ya no está pasada.

## 2. Qué vas a hacer

Los seis estados de un apartado y las únicas formas de pasar de uno a otro. Es la pieza de la que dependen la cobranza, los reportes y el cupo del plan: **si un apartado puede estar en un estado que nadie previó, todo lo que se apoya en el estado deja de ser cierto a la vez.**

Y traes el modelo `Layaway`, en su forma mínima: sin él no hay a quién transicionar.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/20-apartado-y-cronograma.md](../specs/20-apartado-y-cronograma.md) → **`REQ-LAY-005`** | tu requisito entero; la matriz de RB-1 es literal |
| el mismo fichero → `REQ-LAY-004`, `REQ-LAY-006` | de dónde llega `active` y de dónde llega `expired` |
| [docs/specs/50-planes-y-suscripciones.md](../specs/50-planes-y-suscripciones.md) → `REQ-SUB-003` RB-3, RB-8; `REQ-SUB-007` RB-3 | el cupo que se libera, y por qué la suspensión no te frena |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → **`T-042`**, `T-045`, `T-046`, `T-066` | **léelo antes que nada**: las dos decisiones de §4 están ahí |
| `app/Models/Catalog.php` y `app/Models/Product.php` | el patrón de modelo de este proyecto: trait de tenancy, *casts*, nada más |
| `app/Domain/Subscription/LimitEnforcer.php` | cómo se mueve un contador **dentro** de la transacción, y su guarda |
| `app/Domain/Catalog/CatalogVisibility.php` | un enum de dominio con sus métodos con nombre y su `match` sin `default` |
| `tests/Feature/Concurrency/` | dónde va tu caso de SP-3, y por qué (§7.3) |

## 4. Alcance

**Sí:**

1. **`LayawayStatus`** con sus cuatro métodos, y **`InstallmentStatus`**.
2. **El modelo `Layaway` en su forma mínima**: tabla, `BelongsToOrganization`, *casts* del estado y de las fechas. **`T-045` lo termina**; no le pongas relaciones ni accesores que nadie usa todavía.
3. **`LayawayStateMachine`**, **`IllegalLayawayTransitionException`** y los **eventos de dominio** (`LayawayActivated`, `LayawayCompleted`, …) en `app/Events/Layaway/`.
4. El **ajuste del cupo** `active_layaways` en cada transición (RB-6).
5. Sus tests, incluida la **matriz 6×6** y el de concurrencia.

**No:**

- **No** escribas `ActivateLayawayAction` ni materialices cronogramas: es **`T-046`**.
- **No** escribas `CreateLayawayAction`, `LayawayCode` ni el formulario: es **`T-045`**.
- **No** escribas el job de expiración por falta de inicial (`REQ-LAY-006`) ni el de mora: tú das la transición, ellos la llaman.
- **No** escribas `UsageCalculator` ni `RecalculateUsage`: son **`T-066`** (§6).
- **No** escribas *listeners* de notificación: los eventos se emiten y se quedan sin escuchar hasta `T-082`.

## 5. Ninguna escritura de estado ocurre fuera de la máquina

RB-2 lo dice en una línea y es toda la tarea: **`ninguna` escritura de estado ocurre fuera de la máquina.** No es una preferencia de estilo — es lo que hace que la matriz de RB-1 signifique algo. Un `$layaway->status = 'active'` en cualquier acción convierte las seis reglas en una sugerencia, y el día que aparezca un apartado `completed` con cuotas pendientes no habrá dónde mirar.

Dos consecuencias prácticas:

1. **La marca de tiempo, el evento y el cupo viajan con la transición**, dentro de `transition()`, no repartidos por quien llama (RB-6, RB-7). Si `activated_at` lo escribe la acción y el estado lo escribe la máquina, existe un instante —y un fallo— en que discrepan.
2. **Hazlo difícil de saltarse, no solo prohibido.** El enum, un *mutator* que lance, un test que recorra el código: elige el mecanismo y **escríbelo en la spec**. Este proyecto ya tiene precedentes de guardas que fallan ruidosamente (`PublishedCatalogs`, la del orden de *middlewares* en los resolutores de tenant); una regla que solo vive en una frase de la spec se incumple sin que nada se ponga rojo.

## 6. El cupo se mueve aquí, y no espera a `T-066`

RB-6: `countsTowardPlanLimit()` es verdadero en los tres estados vivos y falso en los tres terminales, y **toda transición ajusta `active_layaways` en la misma transacción**. Completar o cancelar **libera cupo**, y un negocio en el tope de su plan lo nota en la siguiente venta.

Lo que **sí** falta hasta `T-066` es `LimitEnforcer::measure()` para esta métrica —la reconciliación, que recuenta desde cero y corrige la deriva—. El movimiento incremental no depende de eso: `usage_counters` existe, `UsageMetric::ActiveLayaways` existe, y `T-045` lo incrementará al crear. Tú lo bajas al terminar.

Dos detalles que la spec fija y conviene no improvisar:

- **En la misma transacción, con el contador bloqueado.** Comprobar en una transacción y escribir en otra deja pasar dos movimientos con un solo cupo; `LimitEnforcer` ya lanza si lo llamas fuera de una transacción, y esa guarda está ahí por eso.
- **El consumo nunca queda negativo** (`REQ-SUB-003` RB-8, SP-7). Liberar cupo cuando el contador ya está en cero no es un error: es cero.

## 7. Cinco cosas que se tuercen solas

1. **`completed` gana a `overdue`** (SP-5). Un apartado que se salda teniendo cuotas vencidas queda `completed` y **no** deja un `overdue` residual. Es la clase de estado fantasma que sobrevive en los reportes durante meses.
2. **`overdue` ⇄ `active` es bidireccional** (RB-4), y es la única transición que va y vuelve. Al cubrirse lo vencido se regresa a `active` automáticamente; un pago parcial insuficiente **no** crea un estado intermedio, se queda `overdue` (SP-4).
3. **La concurrencia se prueba de verdad**, con dos conexiones y `lockForUpdate` (SP-3). Ese caso va en `tests/Feature/Concurrency/`, que corre en serie: desde `T-034` esa carpeta es «lo que confirma de verdad», y un test de bloqueo dentro de la transacción de `RefreshDatabase` no prueba ningún bloqueo. Lo dice la convención de «Cómo leer este plan».
4. **Un *listener* que falla no revierte la transición** (SP-6): los eventos van encolados y `afterCommit`. Si el envío de una notificación puede deshacer un `completed`, el estado del apartado depende de que el correo salga.
5. **La suspensión de la organización no te frena** (RB-9, `REQ-SUB-007` RB-3). Esta máquina **no pregunta por la suscripción**: que el negocio no le haya pagado a la plataforma no puede impedir que su cliente termine de pagar su nevera. Si te encuentras leyendo el estado de la suscripción aquí, es que la comprobación está en el sitio equivocado.

## 8. Hecho cuando

- [ ] **Matriz 6×6** probada entera: las transiciones válidas de RB-1 pasan y **todas las demás lanzan** `IllegalLayawayTransitionException` con un mensaje que dice desde dónde y hacia dónde.
- [ ] `acceptsPayments()` y `countsTowardPlanLimit()` **por estado**, con un caso por cada uno (RB-3, RB-6).
- [ ] **El cupo se ajusta en cada transición**, en la misma transacción, y **nunca queda negativo** (RB-6, SP-7).
- [ ] **Concurrencia**: dos transiciones simultáneas y la fila bloqueada (SP-3), en `tests/Feature/Concurrency/`.
- [ ] **`completed` prevalece sobre `overdue`** (SP-5); `overdue` ⇄ `active` va y vuelve (RB-4).
- [ ] **Un *listener* que falla no revierte** (SP-6), con eventos `afterCommit`.
- [ ] **Funciona con la organización suspendida** (RB-9), con su test.
- [ ] **Los terminales son inmutables**: activar un `expired` y cancelar un `completed` lanzan (SP-1, SP-2).
- [ ] **Cada transición escribe su marca y emite su evento** (RB-7), dentro de `transition()`.
- [ ] **Ninguna escritura de estado fuera de la máquina**, con el mecanismo que lo impide **escrito en la spec** (§5.2).
- [ ] El modelo `Layaway` llega **mínimo** y `tenancy:audit` sigue en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Cuántas de las 36 celdas de la matriz son válidas, y qué dice el mensaje de una inválida.
2. Qué mecanismo impide escribir el estado fuera de la máquina, y qué se pone rojo si alguien lo intenta.
3. Cómo se mueve el cupo en una transición, y qué pasa exactamente al liberar con el contador en cero.
4. Cómo probaste el bloqueo de SP-3 y por qué ese test no podría vivir en la suite en paralelo.
5. Qué le dejaste al modelo `Layaway` y qué deliberadamente no.
6. Qué queda de `REQ-LAY-005` sin cerrar y de qué tarea es.
7. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
8. Nada más. No propongas siguientes pasos.
