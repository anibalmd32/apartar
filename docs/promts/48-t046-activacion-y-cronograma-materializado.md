# PROMPT 48 — `T-046` · Activación y cronograma materializado

> Hito **H3 · Apartado** · Tarea `T-046` · Fase `B` · 2 pts
> **Cierra `REQ-LAY-004`** en su parte de materialización, y **extrae la interfaz de los eventos** (§7).
> Depende de `T-041` (`ef24c7e`, `ScheduleGenerator`), `T-042` (`d626001`, la máquina y sus eventos) y `T-045` (`4ab2d0e`, el apartado que llega en `pending_down_payment`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 90 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-046: activacion y cronograma materializado`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo tuyo: es casi todo dominio y base de datos.
2. **Los baratos primero**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio.
5. **Una vuelta completa si pasa.** Si la primera destapa trabajo real, arreglarlo y repetir es lo correcto.

## 2. Qué vas a hacer

El momento en que un apartado se vuelve real: se aprobó la inicial, y ahora hay N cuotas con fechas concretas que el cliente tiene que pagar. `T-041` dejó el cálculo probado con 500 casos; tú lo escribes en la base.

No tienes quien te llame: **quien aprueba la inicial es el flujo de comprobantes, que es H4**. Tus tests llaman la acción directamente, y eso es lo correcto.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/20-apartado-y-cronograma.md](../specs/20-apartado-y-cronograma.md) → **`REQ-LAY-004`** y **la nota bajo sus *sad paths*** | tu requisito, y la contradicción de SP-2 ya resuelta (§5) |
| el mismo fichero → `REQ-LAY-005` RB-1 | la matriz de nueve celdas, que **no** se amplía |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → **`T-046`** | tus tres encargos, con su razonamiento |
| `app/Domain/Layaway/ScheduleGenerator.php` y `AmortizationSchedule.php` | **escritos y probados**: aquí solo se llaman |
| `app/Domain/Layaway/LayawayStateMachine.php` | la única puerta al estado, y su guarda |
| `app/Events/Layaway/LayawayTransitioned.php` | la clase base de §7 |
| `app/Models/Layaway.php` | el patrón de modelo mínimo, con su guarda `saving` |
| `database/migrations/…_create_installments_table.php` | tu tabla, con `due_date` en `DATE` y el único compuesto |

## 4. Alcance

**Sí:** el modelo `Installment`, `ActivateLayawayAction`, `next_due_date`, la idempotencia, la interfaz de los eventos (§7) y sus tests.

**No:**

- **No** escribas el flujo de aprobación del comprobante ni quien llama a tu acción: es **H4**.
- **No** toques `ScheduleGenerator` ni `AmortizationSchedule`: están cerrados y probados con 500 casos.
- **No** escribas el job de mora ni la vuelta a `active`: son `REQ-PAY-005` y `T-058`.
- **No** amplíes la matriz de `REQ-LAY-005` RB-1 (§5).

## 5. El financiado cero no abre una décima celda

SP-2 dice que con financiado cero el apartado «pasa directamente a `completed`». La matriz de `REQ-LAY-005` RB-1 tiene **nueve celdas válidas de treinta y seis**, y `pending_down_payment → completed` no es una de ellas. **No se añade.**

Va en **dos pasos, dentro de la misma transacción**: `pending_down_payment` → `active` → `completed`. Tres razones, y conviene entenderlas porque son el tipo de cosa que alguien «simplifica» después:

1. **La mercancía quedó comprometida.** La inicial se aprobó; el apartado estuvo activo aunque fuera un instante, y saltárselo cuenta una historia que no ocurrió.
2. **`activated_at` solo se escribe al salir de `pending_down_payment`** (`T-042`). Un salto directo dejaría un apartado `completed` **sin fecha de activación**, y todo informe que cruce por esa columna lo perdería sin decir nada.
3. **Se emiten los dos eventos**, que es lo que las notificaciones de H7 van a necesitar.

Y no se genera cronograma: `AmortizationSchedule` vuelve vacío, que ya es su comportamiento desde `T-041`, y `next_due_date` se queda en `null`.

## 6. SP-5 y SP-6 son dos garantías distintas

Se parecen y no lo son:

- **SP-5 es el reintento del job** con una inserción a medias. Lo resuelve el `UNIQUE(organization_id, layaway_id, number)` que `T-039` compuso con el tenant delante: reintentar no duplica cuotas.
- **SP-6 es una segunda llamada** sobre un apartado que **ya está activo**. Eso lo rechaza la máquina de estados sola, porque `active → active` no está en la matriz.

Dos casos distintos, y ninguno cubre al otro. Si escribes solo uno, la mitad que falta se descubre cuando un job se reintente en producción.

## 7. Seis eventos que van a ser más, y un despachador que no mira a los padres

`LayawayTransitioned` es hoy una **clase base abstracta**, y el despachador de Laravel resuelve los *listeners* por clase concreta y por **interfaz**, nunca por clase padre. Tal como está, `T-082` tendría que suscribirse a los seis eventos uno a uno.

Eso es un censo repartido con la peor forma de fallo: **el séptimo evento que alguien añada en H5 no notificaría nada, y ningún test se pondría rojo.** Es la convención de «Cómo leer este plan» —una lista de casos es propiedad del tipo, no del consumidor— y van seis censos así en este proyecto.

Son tres líneas: una interfaz marcadora que la clase base implementa, y **un caso que recorra `app/Events/Layaway/` y exija que todo evento de ahí la implemente**. Es aquí porque ya emites `LayawayActivated` y tocas esos ficheros.

## 8. Cinco cosas que se tuercen solas

1. **La fecha de la cuota 1 sale de `activated_at`**, no de `now()` ni de la creación del apartado (RB-1, RB-3). Y en la **zona de la organización**, que `generate()` recibe por parámetro precisamente para que no se pueda coger del ambiente.
2. **`next_due_date` es la `due_date` de la primera cuota no pagada** (RB-8), no la de la primera cuota. Hoy son la misma; en cuanto exista un pago, dejan de serlo, y quien lo escriba como «la primera» dejará una fecha vencida colgando.
3. **Las cuotas son inmutables en importe** (RB-6). Solo `RescheduleInstallmentAction` mueve fechas, y es `REQ-LAY-011`. Si tu acción expone algo que permita cambiar un `amount_cents`, sobra.
4. **Todo ocurre en una transacción**, con el apartado bloqueado: insertar las cuotas, fijar `next_due_date` y transicionar son una sola cosa. Un cronograma insertado con el apartado todavía en `pending_down_payment` es un estado que nadie sabe leer.
5. **La invariante se vuelve a afirmar contra la base** (RB-5): `sum(installments.amount_cents) === total_cents − totalDownPayment`, leyendo las filas insertadas. `T-041` la probó sobre el dominio; aquí se prueba que lo que llegó a MySQL es lo mismo — que es donde se pierden los céntimos, en un *cast* o en una columna con el tipo equivocado.

## 9. Hecho cuando

- [ ] **Genera N cuotas** con importes y fechas de `ScheduleGenerator`, y **`next_due_date` correcto** (RB-8).
- [ ] **Financiado 0 no genera cuotas** y el apartado termina `completed` **pasando por `active`**, con `activated_at` y `completed_at` puestos (§5).
- [ ] **Reintento del job no duplica** (SP-5) y **segunda llamada sobre un apartado activo es rechazada** (SP-6) — dos casos distintos (§6).
- [ ] **`sum(amount_cents) === financiado`** leído **de la base** (RB-5, §8.5).
- [ ] **Dos organizaciones en zonas distintas producen fechas distintas** para la misma activación (SP-7).
- [ ] **Todo en una transacción** con el apartado bloqueado (§8.4).
- [ ] **La interfaz de los eventos extraída**, con el caso que recorre `app/Events/Layaway/` (§7).
- [ ] **La matriz sigue teniendo nueve celdas válidas** y `AuditEventTest` sigue en verde.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro.
- [ ] **Commit hecho.**

## 10. Reporta al terminar

1. Las fechas e importes exactos que materializas para un apartado de $400 con inicial $100, 5 cuotas cada 15 días, activado el 10/09 en Caracas.
2. Qué estados y qué marcas de tiempo quedan en un apartado de financiado cero, en orden.
3. Qué caso cubre SP-5 y cuál SP-6, y qué falla si solo existiera uno.
4. Cómo quedó la interfaz de los eventos y qué se pone rojo si alguien añade un séptimo sin implementarla.
5. Qué queda de `REQ-LAY-004` sin cerrar y de qué tarea es.
6. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
7. Nada más. No propongas siguientes pasos.
