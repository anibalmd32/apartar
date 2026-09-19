# PROMPT 19 — `T-017` · `Money` y `Currency`

> Hito **H1 · Aritmética y tasa** · Tarea `T-017` · Fase `A` · 2 pts
> Abre `REQ-FX-001`. **Lo cierra `T-018`**, que es su fase de lógica y tests.
> Depende de `T-019` (`08a8d4e`, `RateSnapshot` ya existe).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-017: Money y Currency`.

## 0. Qué vas a hacer

**El tipo del que depende todo el dinero del sistema.** Cada precio, cada cuota, cada abono, cada saldo y cada factura pasan por aquí. Si esto tiene un error de redondeo, lo tienen los 118 requisitos.

Por eso está partida en dos: tú escribes **las estructuras y las firmas** (`T-017`) y `T-018` escribe **la lógica y los 500 casos de propiedad**. No hagas el trabajo de `T-018`.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → **`REQ-FX-001`** | el bloque de código de `Money` es **normativo**: esas ocho firmas, con esos nombres |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-017`, `T-018` y `T-019` | el reparto entre las dos fases y lo que ya existe |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§4 (cabecera de convenciones)** | por qué el dinero es `BIGINT` **con signo** |
| `app/Domain/Exchange/` (lo que dejó `T-019`) | `RateSnapshot` es real; `convertTo()` se escribe contra él |

## 2. Alcance

**Sí:**

1. **`Currency`** y **`Money`** con sus **ocho** métodos, en `app/Domain/Money/`.
2. **`MoneyFormatter`**.
3. **`MoneyCast`** (`*_cents` ↔ `Money`).
4. **`CurrencyMismatchException`** e **`InvalidRateException`**.
5. Tipos TS **`CentsUsd`** y **`MoneyView`**, y **`resources/js/lib/money.ts`**.
6. Los tests que correspondan a lo que implementes (ver §3).

**No:**

- **No** escribas los 500 casos de propiedad de `allocateEvenly`, ni la batería de formateo, ni los casos de conversión: son **`T-018`**, y son la razón de que esa tarea exista.
- **No** apliques `MoneyCast` a las columnas de modelos existentes. Cablearlo es de las tareas que usen cada modelo.
- **No** toques la tasa: `T-020` y `T-021`.
- **No** implementes el snapshot inmutable de `REQ-FX-006`: es `T-023`.

## 3. Qué significa Fase A aquí, exactamente

La fase separa estructura de lógica **para que no queden algoritmos a medio construir**, no para producir muñones. `T-019` ya sentó el criterio: implementó `isStale()` porque eran tres líneas y un *value object* con un método que miente es peor que uno completo.

Aplícalo con la misma cabeza. Lo que **sí** va entero aquí: `Currency`, el constructor, `add`, `subtract`, `multiply`, `isNegative`, `isZeroOrBelowTolerance`, las dos excepciones, y `MoneyCast`. Son comparaciones y sumas de enteros: dejarlas vacías obligaría a `throw`s provisionales que PHPStan nivel 6 te haría justificar y que alguien tendría que acordarse de quitar.

Lo que **es de `T-018`** son los tres métodos donde vive el riesgo real, y su batería:

- **`allocateEvenly()`** — la invariante `sum(partes) === cents` con 500 casos.
- **`convertTo()`** — `bcmul` a escala 8, *half-up* al final, nunca redondeo intermedio.
- **`MoneyFormatter::format()`** — los dos formatos exactos.
- **`fromDecimalString()`** — el parseo, que es donde entran los datos sucios.

Déjalos declarados con su firma y su PHPDoc completo, y **falla ruidosamente**: un `throw new LogicException('T-018')` con el mensaje que diga qué falta. Es la convención del proyecto y es lo contrario de devolver un valor plausible — un `allocateEvenly()` que devuelva `[]` compila, pasa por delante de todo y reaparece como un cronograma vacío dentro de tres hitos.

Si crees que alguno de los cuatro cae del otro lado, dilo en el reporte con el motivo; lo que no vale es implementarlos a medias.

## 4. Las tres cosas que no se pueden torcer

**1. `BIGINT` con signo, nunca `UNSIGNED`.** Está en la cabecera de convenciones de [05 §4] y el motivo es concreto: restar dos `BIGINT UNSIGNED` en MySQL **no da negativo, desborda a un número enorme**. Con dinero eso es un balance corrupto en lugar de un error visible. Y SP-3 dice que `Money::subtract()` **sí permite** negativos, porque existe el concepto de crédito; quien lo prohíbe son las reglas de `LAY`/`PAY` que lo usen, no el tipo.

**2. Nunca `float`.** Ni en el esquema, ni en el código, ni en el TS. `rate` es un `string` decimal, los importes son enteros de centavos, y la conversión va por `bcmul`. Es una regla verificable en CI (`REQ-FX-001` RB-1); si te ves escribiendo `(float)` para salir de un sitio, ese sitio es el error.

**3. Operar entre monedas distintas lanza** (RB-3). No hay conversión implícita, y eso es lo que hace que un `add()` accidental entre USD y VES sea un fallo visible en vez de una cifra plausible y falsa.

## 5. El lado TypeScript

`MoneyView` viaja en las page props y lleva **los importes ya formateados** además de los centavos (RB-6): `{ cents, usd: "$20.00", ves: "Bs. 850,00" }`. El frontend **no reimplementa la conversión ni el formateo** — solo elige cuál de los dos enseña, según el toggle de `REQ-FX-004`.

Eso define qué es `lib/money.ts` y qué no: ayudas de presentación sobre lo que ya viene hecho, nunca aritmética. Si aparece una multiplicación ahí, la cuenta está en el sitio equivocado.

`CentsUsd` es un tipo de marca sobre `number`: sirve para que un número de centavos no se pueda pasar donde va un número de unidades sin que TypeScript lo note.

Y recuerda que `ves` puede no existir: `REQ-FX-005` RB-4 dice que **sin ninguna tasa la UI oculta el monto en VES** por completo — ni `Bs. 0,00` ni `—`. El tipo tiene que permitir expresarlo.

## 6. Hecho cuando

- [ ] `Money` tiene **las ocho firmas** del bloque normativo, con esos nombres y esos tipos.
- [ ] Lo implementado funciona y tiene su test; lo diferido **lanza** nombrando a `T-018`.
- [ ] `Money` es **inmutable**: toda operación devuelve una instancia nueva.
- [ ] Mezclar monedas lanza `CurrencyMismatchException`.
- [ ] `MoneyCast` con `null` devuelve `null`, **no** `Money(0)` (SP-6), con test.
- [ ] Ningún `float`/`double` en código ni en esquema.
- [ ] `MoneyView` permite expresar **"sin VES"**; `CentsUsd` es un tipo de marca.
- [ ] `tenancy:audit` y la suite en verde; toda ruta nueva declara superficie y supervivencia a suspensión.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 7. Reporta al terminar

1. Qué implementaste entero y qué dejaste lanzando, con el criterio que usaste para repartir.
2. Cómo quedó `MoneyCast` y qué decidiste con `null` y con la moneda de la columna.
3. Qué forma tiene `MoneyView` para poder decir "no hay VES" sin mentir con un cero.
4. Si alguna de las ocho firmas del bloque normativo chocó con algo, cuál y cómo lo resolviste. **No alteres el contrato para que encaje**: repórtalo.
5. Nada más. No propongas siguientes pasos.
