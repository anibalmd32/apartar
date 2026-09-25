# PROMPT 42 — `T-041` · Dominio del cronograma

> Hito **H3 · Apartado** · Tarea `T-041` · Fase `B` · 3 pts
> **Cierra `REQ-LAY-004`** en su parte de dominio, y **el caso diferido que dejó `T-035`**.
> Depende de `T-039` (`a5dc9ad`, la tabla `installments` con su `due_date` en `DATE`) y de `T-027` (`FinancingPlan`, que reparte la misma cuenta desde el otro lado).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-041: dominio del cronograma`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo tuyo: es dominio puro, así que casi todo son tests unitarios y corren en segundos.
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio. **Una sola vuelta completa.**
5. Si cambias algo **después** de pasar una suite, esa suite ya no está pasada.

## 2. Qué vas a hacer

El cronograma: cuántas cuotas, de cuánto y para cuándo. Es dominio puro —sin base de datos, sin modelos, sin acciones— y es el corazón del producto: lo que el cliente acepta cuando pulsa «Apartar» es exactamente esta lista de fechas e importes.

Y cierras una deuda concreta. `T-035` publicó la ficha con la cuota que calcula `FinancingPlan`, **sin que nadie haya probado nunca que esa cuenta y la del cronograma coincidan**. Ese caso está esperándote, y §5 te dice por dónde se rompe.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/20-apartado-y-cronograma.md](../specs/20-apartado-y-cronograma.md) → **`REQ-LAY-004`** | tu requisito entero, con la firma de `generate()` ya escrita |
| el mismo fichero → el bloque de tipos, `LayawayTerms` | el contrato de las condiciones congeladas, **con `quantity`** |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-035`, **`T-041`**, `T-042`, `T-045`, `T-046` | lo que heredas y lo que no es tuyo |
| `app/Domain/Catalog/FinancingPlan.php` | **la otra mitad de §5**: la misma cuenta, sin `quantity` |
| `app/Domain/Money/Money.php` → `allocateEvenly()` | **léelo entero**: las 25 líneas de §5 dependen de cómo reparte |
| `database/migrations/…_create_installments_table.php` | tu `due_date` es un `DATE`, y la cabecera explica por qué |
| `app/Domain/Catalog/CatalogVisibility.php` | el patrón de dominio puro de este proyecto: enum, métodos con nombre, sin infraestructura |

## 4. Alcance

**Sí:** `LayawayTerms`, `ScheduledInstallment`, `AmortizationSchedule`, `ScheduleGenerator` e `InvalidScheduleException`, todos en `app/Domain/Layaway/`, con sus tests.

**No:**

- **No** escribas el modelo `Installment` ni `ActivateLayawayAction`: los cronogramas se **materializan** en `T-046`. Aquí no se toca la base de datos.
- **No** escribas `LayawayStatus`, `InstallmentStatus` ni la máquina de estados: son **`T-042`**.
- **No** escribas `CreateLayawayAction`, `LayawayCode` ni el formulario: son **`T-045`**. `LayawayTerms` sí es tuyo, y `T-045` lo **usa**, no lo reescribe.
- **No** decidas qué pasa cuando el financiado es cero más allá de devolver un cronograma vacío (§8.2).

## 5. La trampa está en multiplicar, y es de céntimos

`FinancingPlan` reparte **por unidad**. `LayawayTerms` tiene `quantity`, y su `financedAmount()` es `(unitPrice × quantity) − (downPayment × quantity)`. Parece que una cosa es la otra multiplicada, y **no lo es**, porque `allocateEvenly()` reparte con división entera y deja el resto en la última parte:

```
financiado por unidad = $50,00 (5000 céntimos), 3 cuotas
  allocateEvenly(5000, 3) → 1666, 1666, 1668
  × 2 unidades           → 3332, 3332, 3336

financiado total con 2 unidades = $100,00 (10000 céntimos), 3 cuotas
  allocateEvenly(10000, 3) → 3333, 3333, 3334
```

Dos cuotas distintas para el mismo apartado, y la diferencia no se ve hasta que alguien compara el papel con la pantalla. **Reparte siempre el total, nunca multipliques un reparto.** La regla en una frase: `allocateEvenly` no es lineal, así que un importe repartido es un resultado, no un ingrediente.

Esto ordena el caso que heredas de `T-035`:

- **Con `quantity = 1`, la cuota de la ficha y la del cronograma tienen que ser idénticas hasta el céntimo**, residuo incluido. Ese es el test que `T-035` dejó en rojo diferido: escríbelo de verdad y bórralo de donde está.
- **Con `quantity > 1` pueden diferir**, y es correcto que difieran: la ficha enseña el plan de **una** unidad, porque la cantidad aún no se ha elegido. Lo que **no** puede existir en ningún sitio es una cifra por unidad multiplicada por la cantidad y presentada como la cuota real. Déjalo escrito en la spec, bajo `REQ-LAY-004`, porque `T-045` va a tener esa tentación justo cuando el cliente elija cantidad en el formulario.

## 6. Las fechas son días de calendario en la zona del negocio

RB-3: la cuota N vence a `activatedAt + (N × frequencyDays)` **días de calendario**, en la zona horaria de la organización (`REQ-ORG-006` RB-7). Tres consecuencias que no se pueden confundir:

1. **La cuota 1 vence a los `frequencyDays` de la activación**, no el mismo día. Un apartado activado el 10 con frecuencia 15 tiene su primera cuota el 25.
2. **La zona es la del negocio, no la del servidor ni UTC** (SP-7). `generate()` la recibe como parámetro precisamente para que no se pueda coger de ambiente; dos organizaciones en zonas distintas que activan en el mismo instante producen fechas distintas, y hay un test de eso.
3. **Nada se desplaza por domingo ni por feriado** (RB-4), y la frecuencia es en **días**, no en meses, así que el 29 de febrero y los fines de mes no son casos especiales (SP-4). Dilo en el código: es una pregunta que alguien hará.

Y encaja con lo que dejó `T-039`: `due_date` es un `DATE`, y «vencida» se decide comparando días contra el «hoy» de la organización calculado en PHP, nunca con `CURDATE()`.

## 7. La invariante se prueba con 500 casos, y dice una sola cosa

RB-5: `sum(cuotas) === financiado`, **exactamente**, para cualquier importe y cualquier número de cuotas. `allocateEvenly()` ya lo garantiza por construcción —la última parte es «lo que queda», no «la base más el resto»—, y el test de propiedad con 500 combinaciones aleatorias existe para que siga siendo verdad el día que alguien lo «optimice».

Con la semilla fija, que es la convención del proyecto: lo que se congela en un test de propiedad es el azar, no el calendario.

## 8. Cinco cosas que se tuercen solas

1. **`frequencyDays` ∈ [1, 90]` e `installmentsCount` ∈ [1, 36]`** (RB-7), y fuera de rango es `InvalidScheduleException`, no un cronograma raro. El mensaje dice el rango, como los de `T-040`.
2. **Financiado 0 devuelve un cronograma vacío, y no lanza** (SP-2). Que el apartado pase entonces a `completed` es de la máquina de estados (`T-042`) y de la activación (`T-046`); aquí solo hay cero cuotas, y `AmortizationSchedule` tiene que responder a `totalAmount()` y a `firstUnpaid()` sin reventar.
3. **`installmentsCount = 1`** es una sola cuota con todo el financiado (SP-3): no es un caso especial, pero es el que se rompe cuando el bucle empieza en 1 o termina en `count - 1`.
4. **`ScheduleGenerator` es dominio puro.** Sin `now()`, sin `config()`, sin `TenantContext`, sin consultas. Todo lo que necesita llega por parámetro —las condiciones, el instante de activación y la zona—, y por eso se puede probar con 500 casos en un segundo. Un `CarbonImmutable::now()` dentro convierte el test en algo que depende del día en que se ejecuta.
5. **`LayawayTerms` es tuyo y `FinancingPlan` ya existe.** No los fundas, pero tampoco escribas la resta dos veces: si `LayawayTerms::financedAmount()` y `FinancingPlan` no producen lo mismo para `quantity = 1`, uno de los dos sobra. Decide cómo se relacionan y **escríbelo**.

## 9. Hecho cuando

- [ ] **Invariante `sum(cuotas) === financiado` con 500 combinaciones aleatorias** y semilla fija (RB-5).
- [ ] **El residuo cae en la última cuota** (RB-2, SP-1): `$100 / 3` da `3333`, `3333`, `3334`.
- [ ] **Fechas exactas con frecuencia 7, 15 y 30**, con la cuota 1 a los `frequencyDays` de la activación (RB-3).
- [ ] **Dos organizaciones en zonas distintas producen fechas distintas** para la misma activación (SP-7).
- [ ] **Financiado 0 no genera cuotas** y no lanza (SP-2); `installmentsCount = 1` da una cuota con todo (SP-3).
- [ ] **Rangos inválidos rechazados** con el rango en el mensaje (RB-7).
- [ ] **La cuota de `FinancingPlan` y la del cronograma coinciden hasta el céntimo con `quantity = 1`** — el caso que `T-035` dejó diferido, ahora de verdad, y **borrado de donde estaba**.
- [ ] **Nadie multiplica un reparto** (§5), y la regla está **escrita en la spec** bajo `REQ-LAY-004`.
- [ ] `ScheduleGenerator` no toca reloj, configuración, tenant ni base de datos (§8.4).
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 10. Reporta al terminar

1. Qué da tu generador para 2 unidades de un producto con $50 financiados por unidad y 3 cuotas, céntimo a céntimo, y qué daría multiplicando el reparto por unidad.
2. Cómo quedó la relación entre `LayawayTerms` y `FinancingPlan`, y qué escribiste en la spec.
3. Las fechas exactas del *happy path* de RB-3 (activación 10/09, 5 cuotas quincenales) y qué cambia si la organización está en otra zona.
4. Cómo respondió `AmortizationSchedule` al cronograma vacío de SP-2.
5. Qué queda de `REQ-LAY-004` sin cerrar y de qué tarea es.
6. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
7. Nada más. No propongas siguientes pasos.
