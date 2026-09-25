# PROMPT 41 — `T-040` · Identidad: VOs y validación

> Hito **H3 · Apartado** · Tarea `T-040` · Fase `B` · 3 pts
> **Cierra `REQ-IDN-007`.**
> Depende de `T-039` (`a5dc9ad`, la tabla `customers` con sus columnas `document_number`, `document_kind` y `phone`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-040: identidad, VOs y validacion`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo que tocas: los tests unitarios de tus dos clases, que es donde va casi todo.
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio. **Una sola vuelta completa.**
5. Si cambias algo **después** de pasar una suite, esa suite ya no está pasada.

## 2. Qué vas a hacer

Cómo se escribe una cédula, un RIF y un teléfono venezolanos, y cómo se guardan. Es una tarea pequeña y sin pantallas, y es de las que más lejos llegan: `document_number` es **la clave por la que un negocio encuentra a su cliente** —`UNIQUE(organization_id, document_number)`, de `T-039`— y la mitad de la credencial del portal (`REQ-IDN-008`). Si la misma persona puede quedar guardada de dos formas, el negocio tendrá dos fichas del mismo cliente con dos saldos, y eso no se arregla después.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/10-identidad-y-autenticacion.md](../specs/10-identidad-y-autenticacion.md) → **`REQ-IDN-007`** | tu requisito entero; **RB-2, RB-5, RB-6 y RB-7** son los que se tuercen |
| el mismo fichero → `REQ-IDN-008` RB-3 | por qué el documento es media credencial |
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) y [32](../specs/32-backoffice-clientes-y-cobranza.md) → `REQ-BOF-040` RB-1 | quién más va a usar tu normalización (§6) |
| `app/Domain/Money/Money.php` y `MoneyFormatter.php` | **el patrón de VO de este proyecto**: inmutable, lanza en el constructor, formatea aparte |
| `app/Support/FullTextTerm.php` → `sanitize()` | **el patrón de la segunda puerta** (§6): la que devuelve `null` en vez de lanzar |
| `app/Rules/ValidPaymentIdentifier.php` | el patrón de regla de validación, con su mensaje |
| `resources/js/lib/money.ts`, `plan.ts` | cómo se reparte hoy el trabajo entre PHP y TypeScript (§7) |

## 4. Alcance

**Sí:**

1. **`DocumentNumber`** con **`DocumentKind`**, y **`PhoneNumber`**, en `app/Support/` (es lo que dice el gate del requisito).
2. **`ValidDocumentNumber`** y **`ValidVenezuelanPhone`** en `app/Rules/`.
3. **`resources/js/lib/identity-format.ts`** con `formatDocumentNumber(raw)` y `formatPhone(raw)`.
4. Sus tests, incluidos los de la tabla de §5.

**No:**

- **No** escribas el modelo `Customer`, ni su *factory* de negocio, ni ninguna acción: son `T-043` en adelante.
- **No** toques el formulario de apartado ni ninguna pantalla: es `T-045`.
- **No** valides el dígito verificador del RIF: RB-8 lo deja fuera **a propósito**, y rechazar por eso cuesta ventas con datos de campo irregulares.
- **No** admitas numeración internacional que no sea `+58` (SP-5), ni consultes operadoras.

## 5. Los ceros a la izquierda son el caso que hay que no romper

**RB-7: `V-0012345` conserva sus ceros.** Parece un detalle de formato y no lo es: recortarlos **colapsa dos documentos distintos en uno**, y el efecto no es un error sino dos personas compartiendo ficha, saldo y apartados en el mismo negocio, bajo un índice único que además impediría crear la segunda.

Lo que lo rompe es lo más natural de escribir: un `(int)`, un `ltrim($n, '0')`, un `intval` para comparar longitudes. **El número de documento es una cadena de principio a fin**, y los dígitos se cuentan con `strlen`, no convirtiendo.

Y esto convive con RB-1 sin contradecirla: «6 a 9 dígitos» se cuenta sobre la cadena, así que `0012345` son siete dígitos y es válida.

## 6. Hay dos puertas, y una no puede lanzar

RB-6 dice que los VO son inmutables y **lanzan en el constructor**: no existe un `DocumentNumber` en estado inválido. Correcto para el formulario, donde un documento mal escrito es un `422` con su mensaje.

Pero RB-5 manda la misma normalización a **la búsqueda del backoffice** —buscar `12.345.678` tiene que encontrar `V12345678`— y SP-6 dice que una cadena no normalizable **se degrada a `LIKE` sobre nombre, email y teléfono, sin error**. Ahí una excepción sería un `500` por teclear medio documento en un buscador.

Así que hacen falta **dos puertas**: la que lanza, para validar, y la que devuelve `null`, para preguntar. Es el patrón que el proyecto ya usa en `FullTextTerm::sanitize()` y en `FinancingPlan::tryFrom()`; míralos y escribe el tuyo igual. Lo que no vale es una sola puerta que lance y que el buscador envuelva en un `try`: eso reparte la decisión entre el que la toma y el que la tapa.

Un aviso de dirección: **el buscador es tuyo solo en la puerta**. La pantalla de clientes es `REQ-BOF-040` y llega después; aquí dejas la normalización lista y un test que afirme que `12.345.678` y `V12345678` producen la misma forma canónica.

## 7. Dos censos que no pueden discrepar, en dos lenguajes

Los prefijos móviles válidos (`412`, `414`, `416`, `424`, `426`) y las formas de escribir un documento van a existir en PHP **y** en TypeScript, y no hay forma de compartir el código. Es la cuarta vez que este proyecto se encuentra con un censo repartido entre dos sitios, y la convención de «Cómo leer este plan» dice cómo acaba: el que se queda corto **sirve de menos, en silencio**.

La salida que el proyecto ya eligió para el dinero es útil aquí: **formatear en el servidor y mandar el resultado**. Aplícala donde puedas. Para lo que quede en el cliente —la máscara mientras se teclea— vale una regla sencilla y muy eficaz:

> **La máscara nunca rechaza.** Da forma a lo que se escribe y deja pasar todo; quien decide si es válido es PHP, siempre. Una máscara que rechaza es una segunda validación que nadie mantiene, y su fallo típico es bloquear un documento legítimo sin decir por qué.

Si aun así un censo tiene que estar en los dos lados, **pruébalo igual**: un test que lea las dos listas y afirme que coinciden, o una tabla de casos compartida que recorran las dos suites. Decide cuál y **escríbelo en la spec**, bajo `REQ-IDN-007`.

## 8. Cuatro cosas que se tuercen solas

1. **Sin prefijo es cédula, nunca RIF** (RB-2, SP-3). Un `J` que falta convierte una empresa en una persona, y el `kind` es parte de lo que se guarda. Por eso el prefijo del RIF es **obligatorio** en la entrada y el de la cédula no: la asimetría es deliberada, y la UI tiene que explicarla —eso es de `T-045`, pero el mensaje de rechazo lo escribes tú y tiene que dejarlo claro.
2. **Canónico y presentación son dos cosas.** Se guarda `V12345678`; se enseña `V-12.345.678`. La que viaja a la base y al índice único es la primera, **siempre**, y la segunda no se parsea nunca para volver atrás.
3. **E.164 para el teléfono** (RB-4): `04121234567`, `4121234567` y `+584121234567` son el mismo número y se guardan `+584121234567`. Los fijos entran con código de área válido; el que no lo tenga es `422` (SP-7).
4. **Los rechazos dicen el rango.** SP-1 pide «`422` con el rango explícito»: «entre 6 y 9 dígitos», no «documento inválido». Quien escribe un documento en un formulario de apartado está a punto de comprar; un mensaje que no dice qué arreglar es una venta perdida.

## 9. Hecho cuando

- [ ] **12 variantes de escritura de cédula** dan la **misma forma canónica**, con su test.
- [ ] **RIF con y sin prefijo** con el `kind` correcto: sin prefijo es cédula (RB-2, SP-3).
- [ ] **Los ceros a la izquierda se conservan** (RB-7, §5), y la cuenta de dígitos es sobre la cadena.
- [ ] **6 variantes de teléfono** dan el mismo E.164; fijo con código inválido da `422` (SP-7); `+` que no sea `+58`, `422` (SP-5).
- [ ] Los rechazos esperados, **con el rango en el mensaje** (SP-1, SP-2).
- [ ] **Las dos puertas** (§6): la que lanza y la que devuelve `null`, con un test de cada una.
- [ ] **`12.345.678` y `V12345678` producen la misma forma canónica** (RB-5).
- [ ] **No se valida el dígito verificador del RIF** (RB-8), y hay un caso que lo afirma.
- [ ] La máscara de TypeScript **no rechaza nada** (§7), y el censo compartido —si queda alguno— **está probado igual en los dos lados**, con la decisión escrita en la spec.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 10. Reporta al terminar

1. Las 12 variantes de cédula que probaste, y cuál de ellas estuvo más cerca de romperse.
2. Cómo quedaron las dos puertas de §6 y qué devuelve cada una ante `12.345.6`.
3. Qué censo quedó repartido entre PHP y TypeScript, si quedó alguno, cómo lo pruebas y qué escribiste en la spec.
4. Qué dice exactamente el mensaje de rechazo de una cédula de 5 dígitos, y el de `P-123`.
5. Qué queda de `REQ-IDN-007` sin cerrar y de qué tarea es.
6. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
7. Nada más. No propongas siguientes pasos.
