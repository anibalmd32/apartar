# PROMPT 20 — `T-018` · Lógica y tests de `Money`

> Hito **H1 · Aritmética y tasa** · Tarea `T-018` · Fase `B` · 3 pts
> **Cierra `REQ-FX-001`.**
> Depende de `T-017` (`aeabbb4`, las estructuras y las cuatro firmas que lanzan) y `T-019` (`08a8d4e`, `RateSnapshot`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-018: logica y tests de Money`.

## 0. Qué vas a hacer

Los cuatro métodos donde vive el riesgo. `T-017` dejó `Money` con sus ocho firmas y cuatro de ellas lanzando un `LogicException` que te nombra: **`fromDecimalString`**, **`allocateEvenly`**, **`convertTo`** y **`MoneyFormatter::format`**. Esas cuatro son tu tarea, con la batería que las sostiene.

Esta tarea existe aparte por una razón que conviene tener presente mientras la escribes: **es la base de todo el dinero del sistema.** Si algo de aquí tiene un error de redondeo, lo tienen los 118 requisitos, y se manifestará dentro de tres hitos como un saldo que no cuadra por dos céntimos en un apartado que nadie sabe reproducir.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → **`REQ-FX-001`** | las nueve reglas y los seis `SP-`. RB-4, RB-5 y RB-7 son tu contrato |
| `app/Domain/Money/` | lo que dejó `T-017`: los mensajes de los cuatro `LogicException` dicen qué falta |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-017` y `T-018` | el reparto entre fases y las decisiones ya tomadas |

## 2. Alcance

**Sí:**

1. **`allocateEvenly()`**, con la invariante y el residuo en la última parte.
2. **`convertTo()`**, con `bcmul` a escala 8 y *half-up* al final.
3. **`fromDecimalString()`**.
4. **`MoneyFormatter::format()`**, los dos formatos exactos.
5. La batería completa, incluidos los **500 casos aleatorios**.

**No:**

- **No** cablees `MoneyCast` a las columnas de ningún modelo: eso es de la tarea que use cada uno.
- **No** toques la ingesta de tasa (`T-020`) ni la fuente por organización (`T-021`).
- **No** implementes la proyección bimoneda ni el toggle (`T-022`), ni el snapshot inmutable (`T-023`).
- **No** rehagas lo que `T-017` dejó funcionando, salvo que encuentres un error — y entonces dilo.

## 3. La invariante, y por qué 500 casos

`sum(allocateEvenly(n)) === cents`, **para cualquier `cents` y cualquier `n ≥ 1`** (RB-4). El residuo de la división entera va **en la última parte**: `$100 / 3` es `[3333, 3333, 3334]`, no `[3334, 3333, 3333]` ni tres partes de `3333` con un céntimo perdido.

Los 500 casos aleatorios no son un capricho de cobertura. Un reparto se prueba con `$100 / 4` y sale bien; los que fallan son los feos, y no se te ocurren escribiendo casos a mano: importes negativos, `n` mayor que `cents`, `cents` primos, `n = 1`. Genera y comprueba la invariante; si tu generador solo produce números redondos, no está probando nada.

Casos que tienen que estar por nombre: **`allocateEvenly(0)` lanza `InvalidArgumentException`** (SP-1), y `cents` negativo reparte igual de bien, porque existe el concepto de crédito (SP-3).

## 4. La conversión: dónde se redondea

RB-5 es exacto y el orden importa: **`bcmul` con escala 8, y se redondea *half-up* a 2 decimales una sola vez, al final**. Nunca en pasos intermedios.

Redondear a mitad de camino es el error clásico y no se nota en una cifra: se nota al sumar cuarenta cuotas, cuando el total en bolívares difiere del total en dólares convertido. Si tu implementación redondea antes de la última operación, la prueba que lo detecta es convertir un cronograma entero y comparar la suma.

**Tasa `0` o negativa lanza `InvalidRateException` antes de convertir** (SP-4). Y `rate` llega como **`string` decimal** desde `RateSnapshot`: no lo pases por `(float)` para operar, que es justo lo que `bcmul` viene a evitar.

## 5. El formateo es exacto, y las dos convenciones son distintas

RB-7: USD `$1,234.56` —coma de miles, punto decimal— y VES `Bs. 1.234,56` —punto de miles, coma decimal—. **Están invertidas entre sí** a propósito: es la convención local, no un descuido.

No delegues en `NumberFormatter` sin fijar el resultado con tests literales: la salida depende del `locale` del sistema y de la versión de ICU, y un entorno distinto cambiaría la presentación del dinero sin que nadie tocara el código.

## 6. Lo que `T-017` ya decidió y no se revisa

Léelo para no deshacerlo sin querer:

- **`isZeroOrBelowTolerance()` compara con signo, no en valor absoluto** (RB-8): haber pagado de más no deja un apartado abierto.
- **`MoneyCast` es de USD y lanza si le dan otra moneda** (RB-9).
- **`MoneyView.ves` es `string | null`**, y el `null` significa "no hay tasa", no "cero bolívares" (`REQ-FX-005` RB-4).
- **`subtract()` permite negativos** (SP-3); quien los prohíbe son las reglas de `LAY`/`PAY`.

## 7. Hecho cuando

- [ ] **`sum(allocateEvenly(n)) === cents` con 500 casos aleatorios**, incluidos negativos, `n > cents`, `n = 1` y primos.
- [ ] El residuo queda en la **última** parte; `$100 / 3` → `[3333, 3333, 3334]`.
- [ ] `allocateEvenly(0)` lanza `InvalidArgumentException`.
- [ ] `convertTo()` usa `bcmul` escala 8 y redondea **una sola vez** al final; hay un test que suma un cronograma convertido y cuadra.
- [ ] Tasa `≤ 0` lanza `InvalidRateException` **antes** de convertir.
- [ ] Formateo `$1,234.56` y `Bs. 1.234,56` **exactos**, fijados con tests literales y sin depender del locale.
- [ ] `fromDecimalString()` con entradas sucias: qué acepta y qué rechaza, decidido y probado.
- [ ] Mezclar monedas lanza `CurrencyMismatchException`.
- [ ] **Ningún `LogicException` de `T-017` sigue en pie**, y ningún `float` en todo el camino.
- [ ] `tenancy:audit` y la suite en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Cómo generas los 500 casos y **qué rangos cubre el generador** — un generador que solo produce números redondos no prueba nada.
2. Cómo verificaste que no se redondea en pasos intermedios.
3. Qué acepta y qué rechaza `fromDecimalString()`, y por qué esa frontera.
4. Cómo fijaste el formateo sin depender del locale del sistema.
5. Si al implementar encontraste que alguna decisión de `T-017` estaba mal, cuál y por qué.
6. Nada más. No propongas siguientes pasos.
