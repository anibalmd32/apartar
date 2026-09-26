# PROMPT 44 — `T-043` · Ficha de cliente

> Hito **H3 · Apartado** · Tarea `T-043` · Fase `B` · 2 pts
> **Cierra `REQ-IDN-001`.**
> Depende de `T-039` (`a5dc9ad`, la tabla `customers`), `T-040` (`f400012`, `DocumentNumber` y `PhoneNumber`) y `T-042` (`d626001`, el patrón de modelo y de guarda).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-043: ficha de cliente`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo tuyo. El caso de SP-5 es una carrera real: va en `tests/Feature/Concurrency/` (§7.1).
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio. **Una sola vuelta completa.**
5. Si cambias algo **después** de pasar una suite, esa suite ya no está pasada.

## 2. Qué vas a hacer

La ficha del cliente final: quién es, para este negocio. Es corta —un modelo, un DTO y una acción— y tiene una decisión de producto dentro que conviene entender antes de escribir nada (§5).

El contexto: **no hay endpoint de «crear cliente»** (RB-8) y la ficha **nace dentro de la transacción del apartado** (RB-6). Así que tu acción no tiene quien la llame hasta `T-045`; sus tests la llaman directamente, y eso es lo correcto.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/10-identidad-y-autenticacion.md](../specs/10-identidad-y-autenticacion.md) → **`REQ-IDN-001`** | tu requisito entero; **RB-4, RB-6, SP-3 y SP-5** son los que se tuercen |
| [docs/specs/30-backoffice-accesos-y-usuarios.md](../specs/30-backoffice-accesos-y-usuarios.md) → `REQ-BOF-005` RB-1 y la nota bajo RB-9 | **léela**: la lista cerrada de eventos creció por tu culpa, y ahí está el porqué (§5) |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → **`T-043`**, `T-045`, `T-065` | las dos decisiones que te llegan resueltas y el hueco que cierras tú (§6) |
| `app/Support/DocumentNumber.php`, `PhoneNumber.php` | **escritos y probados en `T-040`**: dos puertas, la que lanza y la que devuelve `null` |
| `app/Models/Layaway.php` | el modelo mínimo de `T-042`, con su guarda `saving`: el patrón de este proyecto |
| `app/Support/Auditing/LogsSensitiveActivity.php` y `app/Domain/Auditing/AuditEvent.php` | cómo se emite un evento auditado y dónde se declara su emisor |
| `app/Actions/Organization/UpsertPaymentAccountAction.php` | una acción de este proyecto: transacción, auditoría, excepciones con nombre |

## 4. Alcance

**Sí:** el modelo `Customer`, el DTO `CustomerContactData`, `ResolveCustomerAction`, el nuevo caso `customer.contact_updated` en `AuditEvent` con su emisor, y sus tests.

**No:**

- **No** abras ninguna ruta ni controlador: RB-8 dice que el backoffice **no crea fichas a mano** en esta iteración, y no hay endpoint público de «crear cliente» (RB-6).
- **No** escribas `CreateLayawayAction` ni el formulario: son **`T-045`**, que es quien te llamará.
- **No** escribas la fusión de fichas ni la vinculación de cuentas: `REQ-IDN-002` y `REQ-ACC-004` son de H6.
- **No** escribas el directorio de clientes del backoffice: es **`T-065`** (`REQ-BOF-040`), que además cierra el último test de `REQ-IDN-007`.

## 5. La reutilización de RB-4 escribe desde una puerta abierta

Esta es la regla que hay que entender antes de escribirla. RB-4: si el documento ya existe en esa organización, **se reutiliza la ficha y se actualizan nombre, teléfono y correo** con los valores nuevos.

Y el formulario que manda esos valores es el del apartado: **público, sin sesión, y donde lo único que acredita la identidad es saberse el número de documento.** Es decir, quien conozca la cédula de un cliente de ese negocio puede reemplazar el teléfono y el correo con los que el negocio lo contacta.

No se bloquea, y es correcto que no se bloquee: SP-3 es explícito en que la venta no se detiene, y un cliente que se mudó o cambió de número es el caso normal, no el raro. **Lo que queda como única mitigación es la auditoría**, y por eso no puede ser silenciosa. La lista cerrada de `REQ-BOF-005` RB-1 pasó de 34 a **35** con `customer.contact_updated` para esto, con su entrada escrita en la spec, que es el procedimiento que la propia RB-1 prescribe.

Dos cosas que se siguen de ahí y conviene no improvisar:

- **`customer.identity_conflict` no se reutiliza para esto.** Ese evento (SP-3) señala un nombre **radicalmente distinto** y tiene que seguir siendo raro; dispararlo en cada cambio de teléfono lo convierte en ruido y deja de avisar de lo que avisa.
- **La auditoría guarda el antes y el después** (RB-2), y se redacta **después de comparar**, nunca antes (RB-5b): redactar primero hace que dos valores distintos se vean iguales y el campo desaparezca del diff por «no cambió».

## 6. El consentimiento no cabe en el DTO, y eso lo decides tú

El *happy path* dice que la ficha se crea «con el consentimiento de términos y su IP». `CustomerContactData` declara **cuatro campos** y ninguno es ese, y la tabla tiene `terms_accepted_at` y `terms_ip_address`.

Decide la forma —el DTO crece, o la acción los recibe aparte— y **escríbela en la spec**, bajo `REQ-IDN-001`. Lo que la decisión tiene que resolver, y decir:

1. **Qué pasa en la reutilización.** Un cliente que vuelve a apartar acepta los términos otra vez: ¿se actualiza la marca, o se conserva la primera? Elige y justifícalo, porque esa columna es **evidencia legal** y las dos respuestas son defendibles por motivos distintos.
2. **Que no se pierda en silencio.** Sea cual sea la forma, un camino que cree o reutilice una ficha sin evidencia de consentimiento tiene que ser imposible de escribir, no solo desaconsejado.

Ten presente el estado real: `/terminos` y `/privacidad` **existen y no están redactadas** (`T-037` las dejó marcadas con `drafted: false`). Quien decide qué hacer con la casilla mientras tanto es `T-045`; tú solo tienes que dejar el sitio donde la evidencia se guarda.

## 7. Cinco cosas que se tuercen solas

1. **La carrera de SP-5 se prueba de verdad.** Dos peticiones simultáneas con el mismo documento nuevo colisionan contra `UNIQUE(organization_id, document_number)`; la acción **captura y reintenta la lectura una vez**. Ese caso va en `tests/Feature/Concurrency/`, que corre en serie: una colisión de índice único solo se observa entre transacciones confirmadas, y dentro de `RefreshDatabase` no hay colisión que observar. Es la convención de «Cómo leer este plan», y `T-042` acaba de dejarte el patrón exacto en `LayawayStateMachineConcurrencyTest`.
2. **Sin tenant fijado se lanza** (SP-8): jamás se crea una ficha sin organización. `BelongsToOrganization` ya lo hace; el test afirma que sigue haciéndolo.
3. **El mismo documento en dos organizaciones son dos fichas** (RB-1, SP-4), con balances y chats separados, y **ninguna sabe de la otra**. Es el test de fuga de esta tarea, y es la decisión `Q1` del producto: no hay registro global de personas.
4. **`email` es opcional y no es único** (RB-3). Dos fichas del mismo negocio pueden compartir correo —el caso familiar es real— y una ficha sin correo es **normal**, no degradada (SP-7): al portal se entra por documento + código. Si te descubres poniendo un índice único ahí, es esta regla.
5. **El teléfono es obligatorio** (RB-2) y **el documento se normaliza antes de buscar**: la clave es la forma canónica de `T-040`, y por eso `12.345.678` y `V12345678` encuentran la misma ficha. Usa la puerta que lanza en la acción; la que devuelve `null` es para el buscador de `T-065`.

## 8. Hecho cuando

- [ ] **El mismo documento en dos organizaciones crea dos fichas aisladas** (test de fuga, RB-1).
- [ ] **Teléfono obligatorio** (RB-2, SP-2); **email opcional y no único**, con dos fichas compartiéndolo (RB-3).
- [ ] **RIF aceptado** además de cédula (RB-7).
- [ ] **La reutilización actualiza nombre, teléfono y correo y emite `customer.contact_updated`** con antes y después (RB-4, §5).
- [ ] **Un nombre radicalmente distinto emite `customer.identity_conflict` y no bloquea la venta** (SP-3), y **ese evento no se dispara en un cambio ordinario**.
- [ ] **Concurrencia con el mismo documento nuevo produce una sola ficha** (SP-5), en `tests/Feature/Concurrency/`.
- [ ] **Sin tenant fijado lanza** (SP-8).
- [ ] **El consentimiento tiene sitio y no se puede perder** (§6), con la decisión **escrita en la spec**.
- [ ] `customer.contact_updated` declara su emisor y **`AuditEventTest` sigue en verde** con 35 casos.
- [ ] **No existe ruta ni controlador de cliente** (RB-6, RB-8).
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Qué emite exactamente la acción cuando vuelve un cliente con el mismo nombre y otro teléfono, y qué emite cuando vuelve con un nombre distinto.
2. Cómo resolviste el consentimiento de §6, qué pasa en la reutilización y qué escribiste en la spec.
3. Cómo probaste la carrera de SP-5 y qué hace la acción exactamente al capturar la colisión.
4. Qué queda de `REQ-IDN-001` sin cerrar y de qué tarea es.
5. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
6. Nada más. No propongas siguientes pasos.
