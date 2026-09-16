# PROMPT 16 — `T-015` · Cuentas de cobro

> Hito **H0 · Cimientos** · Tarea `T-015` · Fase `AB` · 2 pts
> Cierra `REQ-ORG-007`.
> Depende de `T-014` (`3b827c1`, ajustes e identidad), `T-013` (`62db6b7`, `LimitEnforcer`) y `T-008` (`0ba84c9`, la tabla `payment_accounts` ya existe).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-015: cuentas de cobro`.

## 0. Qué vas a hacer

Dónde le pagan al negocio. Es un concepto que no existía antes del pivote (`D-7`) y es la pieza que hace posible todo H4: sin una cuenta de cobro declarada, un cliente que quiere apartar **no sabe a dónde transferir**, y un comprobante no tiene a qué cuenta apuntar.

Es también la **segunda vez que se aplica un límite de plan** (`max_payment_accounts`), y esa fue la razón exacta por la que `LimitEnforcer` se adelantó a `T-013` en vez de esperar a H5.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-007`** | el requisito completo, con sus seis `SP-` |
| [docs/specs/22-comprobantes-y-conciliacion.md](../specs/22-comprobantes-y-conciliacion.md) → **contratos compartidos** | la definición canónica de `PaymentMethod`: **seis casos**, no tres |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-015` y `T-014` | el reparto del enum con H1/H4, y el patrón del colaborador sustituible |
| `app/Domain/Subscription/LimitEnforcer.php` | **léelo entero.** Su contrato es estricto y te va a hacer falta |

## 2. Alcance

**Sí:**

1. **`PaymentMethod`** con sus **seis** casos y `identifierLabel()` (ver §3).
2. **`PaymentAccount`** (el modelo; la tabla ya existe desde `T-008`) y **`PaymentAccountView`**.
3. **`ValidPaymentIdentifier`**, con el formato de cada método.
4. **`UpsertPaymentAccountAction`** y **`PaymentAccountInUseException`**.
5. **`PaymentAccountController`** con su CRUD y su página, declarando **superficie** y **si sobrevive a la suspensión**.
6. Sus tests: un caso por método con identificador válido e inválido, el límite de plan, y la degradación del borrado.

**No:**

- **No** implementes la regla de publicar catálogo sin cuenta activa (RB-4, SP-1): es de **`T-032`**, que es quien publica catálogos. Aquí no hay nada que publicar.
- **No** implementes la alerta del dashboard de SP-4: el dashboard está vacío a propósito hasta que haya de qué avisar.
- **No** implementes la auditoría de RB-8: `LogsSensitiveActivity` es de **`T-016`**, la tarea siguiente.
- **No** escribas `requiresReference()` ni `defaultCurrency()` en el enum (ver §3).

## 3. El enum es compartido, y por eso no se escribe entero

`PaymentMethod` lo comparten esta tarea y `REQ-PAY-001` (comprobantes, H4). Sus seis casos son normativos y salen de [22](../specs/22-comprobantes-y-conciliacion.md): `pago_movil`, `transferencia`, `zelle`, `binance_pay`, `efectivo_usd`, `efectivo_ves`.

De sus tres métodos, aquí solo se escribe **`identifierLabel()`**, que es el que esta tarea consume. `requiresReference()` y `defaultCurrency()` llegan con la tarea de comprobantes: **`defaultCurrency()` devuelve `Currency`, que nace en `T-017`**. Escribir un `Currency` provisional para poder completar el enum sería fabricar exactamente la segunda fuente de verdad que `T-017` tendría que desmontar.

Ojo con el plan: su línea de `T-015` decía «3 métodos», y era un error de redacción ya corregido. Son seis.

## 4. `LimitEnforcer`, por segunda vez

`REQ-ORG-007` RB-5 limita las cuentas por plan, y esa es la métrica `payment_accounts` — que `LimitEnforcer::measure()` ya sabe contar desde `T-013`. No cuentes filas a mano: [05 §5] dice que **ninguna Action comprueba límites por su cuenta**, y esta tarea es la razón por la que esa pieza existe ya.

Su contrato tiene dos exigencias que te van a morder si las ignoras:

- **`assertCanCreate()` tiene que llamarse dentro de una transacción abierta**, y lanza si no la hay. No es una comodidad: el contador se lee bloqueado con `FOR UPDATE`, y un `lockForUpdate()` en *autocommit* no serializa nada.
- **`synchronise()` se llama al terminar de mutar, en esa misma transacción.** Crear, desactivar y reactivar mueven el consumo.

`T-013` dejó el patrón escrito en sus tres acciones de equipo. Cópialo.

## 5. Borrar no siempre borra

RB-6 y SP-3: **una cuenta con comprobantes no se borra, se desactiva**, y se informa de que ha ocurrido eso. El motivo es el mismo que el de la baja lógica del equipo: conservar la trazabilidad de **a dónde se pagó**. Una cuenta borrada deja comprobantes apuntando al vacío, en un producto cuya función es conciliar dinero.

**Y aquí tienes el mismo problema que `T-014` con los catálogos: `receipts` no existe hasta H4.**

Resuélvelo igual, porque el patrón ya está probado y hay una lección que aprender de él. `T-014` creó `PublishedCatalogs::exist()` con su forma definitiva, sustituible en el contenedor para poder probar el `422`. Estuvo bien. Lo que estuvo mal fue **cómo se comporta mientras tanto**: devolvía `false` comprobando que la tabla no existiera, y `T-024` crea esa tabla ocho tareas antes de que alguien rellene el cuerpo — con lo que la regla habría quedado desactivada en silencio, sin un solo test en rojo.

Así que el tuyo **falla ruidosamente**: si la tabla `receipts` existe y el cuerpo no se ha sustituido, **lanza**. Es la convención de fallo cerrado que ya rige las declaraciones de ruta y el `match` sin `default` de `LimitEnforcer::measure()`. Deja escrito en el plan qué tarea lo rellena.

## 6. Las tres reglas que parecen detalles y no lo son

**1. `holder_name` y `holder_document` son obligatorios** (RB-3). No es burocracia: en pago móvil venezolano **el titular y su cédula son parte del dato de pago**, no metadatos. Un cliente que recibe un número sin titular no puede completar la transferencia.

**2. Dos cuentas idénticas se avisan pero se permiten** (SP-6). Hay negocios con dos cuentas en el mismo banco. Bloquearlo sería imponer una regla que el mundo real no cumple.

**3. Desactivar la última cuenta activa se permite, con advertencia** (SP-4). **No se despublican catálogos.** Un ajuste de configuración que tumba la vitrina del negocio sería un castigo desproporcionado por una operación rutinaria; se avisa de que los clientes no verán dónde pagar y se deja decidir.

Y `identifier` cambia de significado según el método (RB-2): teléfono, número de cuenta, correo, wallet, o la dirección de retiro en efectivo. `identifierLabel()` es lo que evita que la pantalla diga "identificador" cinco veces.

## 7. Hecho cuando

- [ ] Los **seis** métodos, cada uno con un caso de identificador válido y uno inválido (`422`).
- [ ] `holder_name` y `holder_document` obligatorios en todos.
- [ ] `instructions` acotado a **300 caracteres**.
- [ ] **Límite de plan** aplicado con `LimitEnforcer`, dentro de transacción, con `synchronise()` al mutar.
- [ ] Borrar una cuenta con comprobantes **se degrada a desactivación**, informándolo; con el colaborador sustituible que **falla ruidosamente** cuando exista `receipts`.
- [ ] **Desactivar la última activa se permite con advertencia**, sin despublicar nada.
- [ ] Dos cuentas idénticas: advertencia, permitido.
- [ ] `position` ordena, y el orden se conserva.
- [ ] Solo el `owner`; un `member` recibe `403`.
- [ ] Toda ruta nueva declara **superficie** y **si sobrevive a la suspensión**; `tenancy:audit` y la suite en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Los seis métodos con la regla de formato de su `identifier`, y de dónde sacaste cada una.
2. Cómo integraste `LimitEnforcer` y qué te obligó a cambiar su contrato, si algo.
3. Qué forma le diste al colaborador de comprobantes y **cómo falla** cuando llegue la tabla.
4. Qué decidiste con el reparto de `PaymentMethod`, y qué queda escrito para H4.
5. Si algo de `REQ-ORG-007` depende de algo que aún no existe, qué y de qué tarea es.
6. Nada más. No propongas siguientes pasos.
