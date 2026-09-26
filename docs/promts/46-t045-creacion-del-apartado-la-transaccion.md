# PROMPT 46 — `T-045` · Creación del apartado: la transacción

> Hito **H3 · Apartado** · Tarea `T-045` · Fase `B` · 3 pts
> **Cierra `REQ-LAY-002`** y **`REQ-LAY-001` salvo su superficie**, que es `T-045b`.
> Depende de `T-041` (`ef24c7e`, `LayawayTerms`), `T-042` (`d626001`, la máquina y el modelo), `T-043` (`7457a58`, `ResolveCustomerAction`) y `T-044` (`f240b32`, `ProductLock` y la reserva).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 90 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-045: creacion del apartado, la transaccion`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo tuyo. Hay carreras reales: van en `tests/Feature/Concurrency/`.
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`. Si tocas algún `.tsx` o `.ts`, pasa además `npx prettier --check` sobre **los ficheros que tocaste** —son segundos— antes de la vuelta de `npm`: la vez pasada una vuelta entera se perdió por un formato.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio.
5. **Una vuelta completa si pasa.** Si la primera te descubre trabajo real —como le pasó a `T-044` con tres huecos de cobertura— arreglarlo y repetir es lo correcto, no un desperdicio. Lo que se evita es repetir para confirmar algo que ya salió verde.
6. **Si falta cobertura y lo que falta es el camino de una carrera, déjalo y dilo.** La ronda de concurrencia corre sin instrumentar; taparlo con un caso no concurrente sube el número y no prueba nada ([91](../specs/91-calidad-y-gates.md)).

## 2. Qué vas a hacer

La transacción que crea un apartado: ocho pasos, dos bloqueos, cinco precondiciones y una clave de idempotencia. **Es el código más delicado del proyecto** —es donde el dinero, el inventario y la identidad se tocan por primera vez— y por eso esta tarea se partió en dos: aquí no hay ni una pantalla, y la superficie entera (rutas, formulario, página de gracias, correo) es `T-045b`.

Trabajas sin `LayawayCode` y sin nada de esto escrito, pero **con todas las piezas alrededor ya hechas y probadas**: la máquina de estados, la reserva de stock con su bloqueo canónico, la resolución de la ficha con su reintento, el cronograma y el cupo del plan. Tu trabajo es ordenarlas.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → **§5.1** | **la secuencia canónica, literal**, con el orden de bloqueo anotado en el diagrama |
| [docs/specs/20-apartado-y-cronograma.md](../specs/20-apartado-y-cronograma.md) → **`REQ-LAY-001`** y **`REQ-LAY-002`** | tus dos requisitos; RB-6, RB-9 y RB-12 de `001`, y **todo** `002` |
| el mismo fichero → la nota bajo el paso 4 de `REQ-LAY-001` | por qué el orden de bloqueo no se negocia (§5) |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → **`T-045`** y `T-045b` | **léelo entero**: llegas con cinco encargos heredados (§7) |
| `app/Actions/Customer/ResolveCustomerAction.php` | la acción de referencia: transacción, colisión, auditoría, consentimiento |
| `app/Actions/Layaway/ReserveStockAction.php` y `app/Domain/Catalog/ProductLock.php` | el bloqueo que tienes que tomar tú primero |
| `app/Domain/Layaway/LayawayTerms.php` y `LayawayStateMachine.php` | las condiciones que congelas y la única puerta al estado |
| `app/Domain/Subscription/LimitEnforcer.php` | `assertCanCreate()` y `adjust()`, y su guarda de transacción |
| `tests/Concerns/AssertsStockLockOrder.php` | **te lo dejó `T-044`** para aplicarlo aquí |

## 4. Alcance

**Sí:** `LayawayCode`, `CreateLayawayData`, `CreateLayawayAction` con la secuencia de §5.1, el congelado de `REQ-LAY-002`, la idempotencia de RB-12, y sus tests.

**No:**

- **No** abras rutas ni controladores, **no** escribas `StoreLayawayRequest`, el formulario, la página de gracias ni el correo: todo eso es **`T-045b`**.
- **No** emitas `layaway_created` (RB-11): es **`T-084`**, con su caso en rojo diferido.
- **No** generes cronograma (RB-8): se genera al activar, en **`T-046`**.
- **No** escribas `LinkAccountAction` ni `SubscribeToCatalogAction` (RB-4): son de **H6**. Deja el punto de enganche nombrado y su caso diferido.

## 5. El orden de bloqueo, y por qué su fallo no sale en la suite

[05 §5.1] lo anota en el propio diagrama: **`lockForUpdate()` sobre el `Product` va antes que `ResolveCustomerAction`**. El texto del paso 4 de `REQ-LAY-001` decía lo contrario y se corrigió antes de `T-044`.

El motivo: `ResolveCustomerAction` toma un `FOR UPDATE` sobre la ficha al reintentar tras una colisión. Dos transacciones que tomen producto y cliente en orden distinto se abrazan. Y **ese fallo pasa toda la suite** —un abrazo mortal solo se observa entre transacciones confirmadas— para aparecer en producción, bajo carga, como un `1213` intermitente sin causa aparente.

`T-044` te dejó las dos herramientas: `ProductLock::acquire()`, que es el único sitio que toma ese bloqueo, y `AssertsStockLockOrder`, que mira el registro de consultas y exige que la primera sentencia que bloquee o escriba una fila del tenant sea el `FOR UPDATE` de `products`. **Aplícalo a `CreateLayawayAction`.**

Y el caso que `T-043` dejó anotado —tres peticiones simultáneas, con un abrazo posible entre las dos perdedoras— se resuelve aquí, no dentro de la resolución del cliente: con el producto bloqueado primero, las tres se serializan antes de llegar a la ficha.

## 6. Congelar es copiar, y después no volver a mirar

`REQ-LAY-002` es corto y es la promesa central del producto: **el precio y el plan que el cliente aceptó no cambian después.**

- **RB-1: a partir de la creación, el apartado no vuelve a leer `products` para nada financiero.** Ni el precio, ni la inicial, ni las cuotas, ni la frecuencia. `Layaway::terms()` reconstruye `LayawayTerms` **desde la fila**, y esa es la única fuente.
- **RB-3: `installments_count` y `frequency_days` no se multiplican por la cantidad.** Dos unidades no son diez cuotas, son las mismas cinco por el doble de importe.
- **RB-5: se copian `product_name_snapshot` y `product_sku_snapshot`**, para que el historial siga siendo legible cuando el producto se renombre o se archive.
- **El test que lo prueba** es el del *happy path* de RB-4: crear un apartado, **subir el precio del producto y archivarlo**, y comprobar que el apartado sigue diciendo exactamente lo mismo. Si ese test pasa, el requisito está.

Y recuerda lo que fijó `T-041`: **reparte el total, nunca multipliques un reparto.** `LayawayTerms::financingPlan()` construye el plan del apartado **entero**; de ahí salen el financiado y las cuotas. Una división escrita aquí sería la cuarta versión de la misma cifra.

## 7. Cinco encargos que llegan de las tareas anteriores

Están en el plan, bajo `T-045`, con su razonamiento. En resumen:

1. **De `T-040`:** `PhoneNumber` deja de validar contra el censo de prefijos y valida **la forma** (`+58`, diez dígitos, código empezando por `2` o `4`). `MOBILE_PREFIXES` y los códigos geográficos se quedan para `isMobile()`. Entran `0422` y `0415` al censo. El aviso que no bloquea lo pinta `T-045b`.
2. **De `T-041`:** séptima regla en `CoherentFinancingTerms` — **un financiado menor que el número de cuotas deja cuotas de $0,00**, que la cobranza trataría como vencidas. Va donde ya vive `down_payment >= price`, para que el rechazo lo vea el negocio al guardar su producto. `LayawayTerms` la repite como guarda.
3. **De `T-042`:** `CreatesLayaways` sube el contador al sembrar, igual que haces tú. Hoy cada liberación registra `usage_counter.below_zero` y ese ruido es esperado; **después de esta tarea, un `below_zero` en la suite es una deriva real**.
4. **De `T-043`:** `contactAttributes()` deja de escribir un `email` nulo encima del almacenado. **Ausente no es vacío**, y borrarlo le quita al cliente el enlace de acceso de `REQ-IDN-003` sin que él lo haya pedido.
5. **De `T-044`:** `AssertsStockLockOrder` aplicado a tu acción (§5).

## 8. Cinco cosas que se tuercen solas

1. **Las cinco precondiciones se comprueban en el orden de RB-6**, y el cupo del plan **después** del stock: un `422` por límite del plan cuando además no había stock cuenta al negocio una cosa que no es. El mensaje del cupo es **neutro y no expone el plan del negocio** (SP-2, `REQ-SUB-004` RB-4); al negocio se le avisa por su dashboard, que es de `T-065`.
2. **`code` es único global, legible y no adivinable** (RB-9): `AP-` + 8 caracteres en base32 **sin `0`, `O`, `1` ni `I`**, porque alguien lo va a leer por teléfono y a copiarlo a mano. Con reintento por colisión, y es una de las tres excepciones declaradas en `GlobalUniqueExceptions` — no la amplíes.
3. **La idempotencia devuelve el apartado, no un error** (RB-12, SP-7). Un segundo `POST` con la misma clave devuelve el mismo `code`. Un cliente que pulsa dos veces no puede acabar con dos apartados ni con un `422`.
4. **Stock insuficiente no persiste nada** (SP-1): ni ficha, ni apartado, ni contador. Es el test de rollback de esta acción, y este proyecto exige uno por cada acción transaccional (`REQ-QAX-001`).
5. **La organización sale del catálogo, nunca del payload** (RB-1). Es la misma regla que gobierna toda la superficie pública desde `T-033`, y aquí importa más que en ninguna parte: un `organization_id` que viaje en el formulario es un apartado que se puede crear en el negocio del vecino.

## 9. Hecho cuando

- [ ] **Crea ficha + apartado + reserva en una sola transacción**, con la secuencia de [05 §5.1].
- [ ] **El apartado hereda la organización del catálogo** (RB-1), nunca del payload.
- [ ] **Producto de otro catálogo rechazado**; catálogo inaccesible rechazado (RB-6, SP-3, SP-4).
- [ ] **Stock insuficiente no persiste nada** (SP-1) — test de rollback completo.
- [ ] **Cupo agotado da un mensaje neutro** que no expone el plan (SP-2).
- [ ] **Doble creación con la misma `idempotencyKey` devuelve el mismo `code`** (RB-12).
- [ ] **`code` sin caracteres ambiguos**, único global, con reintento por colisión (RB-9).
- [ ] **Sin email funciona** (RB-3 de `REQ-IDN-001`), y el correo omitido **no borra** el almacenado (§7.4).
- [ ] **Subir el precio y archivar el producto no altera el apartado** (RB-4 de `REQ-LAY-002`), con su test.
- [ ] **`installments_count` y `frequency_days` no se multiplican** por la cantidad (RB-3).
- [ ] **El orden de bloqueo se afirma con `AssertsStockLockOrder`** (§5).
- [ ] **El contador sube aquí y `CreatesLayaways` también**, y `below_zero` deja de aparecer en la suite (§7.3).
- [ ] **La séptima regla de `CoherentFinancingTerms`** rechaza el financiado menor que las cuotas (§7.2).
- [ ] **`PhoneNumber` valida forma, no censo** (§7.1).
- [ ] **Ningún emisor de telemetría** y **ninguna ruta ni pantalla** (§4).
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro.
- [ ] **Commit hecho.**

## 10. Reporta al terminar

1. La secuencia exacta de tu transacción, paso a paso, y dónde cae cada bloqueo.
2. Qué hace la acción ante un segundo envío con la misma clave, y qué ante dos envíos simultáneos con la misma clave.
3. Qué comprueba el test de `REQ-LAY-002` RB-4 y qué valores afirma después de subir el precio.
4. Cómo quedaron los cinco encargos de §7, uno por uno.
5. Qué queda de `REQ-LAY-001` para `T-045b`, y qué enganches dejaste nombrados para H6 y para `T-084`.
6. Cuánto tardó la verificación final, **cuántas vueltas completas** hiciste y por qué, y si dejaste huecos de cobertura por camino de concurrencia.
7. Nada más. No propongas siguientes pasos.
