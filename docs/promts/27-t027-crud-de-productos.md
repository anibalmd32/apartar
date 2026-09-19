# PROMPT 27 — `T-027` · CRUD de productos

> Hito **H2 · Catálogo** · Tarea `T-027` · Fase `B` · 5 pts
> **Cierra `REQ-BOF-020`**, salvo lo que la propia spec reparte a otras tareas (§4).
> Depende de `T-025` (`53dbf4f`, `Product` con `MoneyCast` y `ProductStatus`) y de `T-026` (`bf15d5a`, la taxonomía y `TaxonomyByName`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-027: crud de productos`.

## 0. Qué vas a hacer

El producto: lo que el negocio vende y con qué condiciones lo financia. Es la tarea más grande de H2 y la que sostiene todo lo que viene detrás — la ficha técnica, las imágenes, los catálogos y, en H3, el apartado que se calcula sobre estas mismas cifras.

Dos cosas la hacen distinta de los CRUD que ya has visto: **las seis reglas de coherencia financiera**, que son el contrato que hace que un plan de pago tenga sentido, y **la previsualización en vivo**, que tiene que calcular con el mismo dominio que generará el cronograma real y no con una copia.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → **`REQ-BOF-020`** | tus once reglas y once `SP-`; RB-1 y RB-7 son el corazón |
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → `REQ-CAT-007` | qué significa cada estado, y por qué archivar no rompe nada |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-026`…`T-030`, `T-038`, `T-067` | **léelo antes que nada**: cuatro piezas de `REQ-BOF-020` tienen dueño en otra tarea (§4) |
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → `REQ-FX-001` | los importes entran en dólares y se guardan en centavos; `Money` ya sabe hacerlo |
| `app/Domain/Catalog/TaxonomyByName.php` | **la escribió `T-026` para ti**: la creación al vuelo de categorías y etiquetas |
| `app/Domain/Auditing/AuditEvent.php`, `app/Support/Auditing/LogsSensitiveActivity.php` | el enum te nombra: `ProductPricingChanged` declara como emisor a tu acción |
| `app/Actions/Organization/UpsertPaymentAccountAction.php` y su `…Data` | el patrón de acción + objeto de datos que sigues |

## 2. Alcance

**Sí:**

1. **`ProductData`**, **`UpsertProductAction`**, `StoreProductRequest` / `UpdateProductRequest`, `ProductController` y las **seis rutas** del contrato, con sus dos declaraciones cada una.
2. **`CoherentFinancingTerms`** con las seis reglas cruzadas de RB-1.
3. El **formulario** con la previsualización en vivo (RB-7) y el **listado** simple con sus filtros.
4. La **auditoría** de `ProductPricingChanged` (RB-6).
5. El **archivado** (RB-10) y, para él, `ChangeProductStatusAction` **en su forma mínima** (§4).
6. Sus tests, incluidos los de `tests/Feature/Tenancy`.

**No:**

- **No** escribas la ficha técnica (`T-028`), las imágenes (`T-029`) ni la composición de catálogos (`T-031`).
- **No** escribas la validación de completitud para publicar (RB-5): es **`T-030`**, entera y en un solo sitio. Ver §4.
- **No** llames a `LimitEnforcer` (RB-8): las llamadas las inserta **`T-067`**, y así está escrito desde `T-011`. Hoy la métrica `products` ni siquiera se mide.
- **No** conviertas el listado en la tabla server-driven de `REQ-BOF-045`: es **`T-038`**.
- **No** hagas aritmética de dinero en el cliente ni formatees importes a mano.

## 3. Las seis reglas de RB-1 son un contrato, no una lista de validaciones

Están escritas en `REQ-BOF-020` RB-1 y conviene entender qué protege cada una, porque la que se relaje deja un producto que **no se puede financiar** y el síntoma aparece en H3, cuando el cronograma se genere:

- `down_payment` **estrictamente menor** que `price`: si fueran iguales no hay nada que financiar, y el apartado sería una compra al contado disfrazada de plan de pago.
- `compare_at_price` **mayor** que `price`: es el precio tachado; menor o igual convierte una oferta en un engaño visible en la pantalla del cliente.
- `installments_count` entre 1 y 36 y `frequency_days` entre 1 y 90: los dos extremos del cronograma que `ScheduleGenerator` va a recorrer en `T-041`.
- `price > 0` y ≤ $1.000.000, `stock >= 0`: los límites que hacen que el resto de las cuentas quepan donde tienen que caber.

Y la que se tuerce sola: **SP-8, más de dos decimales se rechaza y no se redondea**. Redondear en silencio es decidir por el negocio el precio de lo que vende; `Money::fromDecimalString()` ya distingue los dos casos.

## 4. Cuatro piezas de `REQ-BOF-020` que **no** son tuyas

Léelo antes de empezar, porque son justo las que parecen faltar cuando terminas:

1. **RB-5, publicar exige completitud.** Imagen, categoría, descripción y condiciones válidas se comprueban **enteras** en la transición a `published`, que escribe `T-030`. Aquí no hay media validación: una segunda lista de requisitos para publicar es la que se queda corta y publica de más sin que nada falle.
2. **RB-8, el límite del plan.** `LimitEnforcer` existe desde `T-011` con su firma definitiva, pero **`T-067` es quien inserta las doce llamadas** en las acciones, y `T-066` quien añade la métrica `products` a `measure()`. Hoy esa métrica devuelve «sin límite» a propósito. No adelantes ninguna de las dos.
3. **RB-9, la tabla server-driven.** El listado que escribes es una lista normal con sus filtros y su columna de «en cuántos catálogos está» (`withCount`); `T-038` la convierte en la tabla genérica de `REQ-BOF-045`.
4. **El archivado sí es tuyo, y por eso empiezas `ChangeProductStatusAction`.** RB-10 pone `POST /productos/{product}/archivar` en este requisito, pero cambiar `status` por dos caminos distintos es exactamente lo que este proyecto lleva seis tareas evitando — y además el enum de auditoría ya declara a `ChangeProductStatusAction` como emisor de `ProductStatusChanged`. Escríbela **solo con el archivado**, con su evento, y `T-030` le añade la matriz completa. Es el mismo relevo que `T-024` dejó con el modelo `Catalog`.

## 5. La previsualización calcula con el dominio, no con una copia

RB-7 pide que el formulario muestre en vivo «inicial $50 + 5 cuotas de $30 cada 15 días» **usando el mismo cálculo del dominio que generará el cronograma real**.

El cronograma real lo genera `ScheduleGenerator`, que es de `T-041`, en H3. Así que aquí hay una decisión que tomar y que explicar, no una obviedad: lo que **no** vale es escribir la división en el `.tsx`. Si el cliente reparte 150 entre 5 con aritmética de coma flotante y el servidor reparte con `Money::allocateEvenly()`, el negocio verá una cuota en el formulario y su cliente verá otra en el catálogo — por un céntimo, que es suficiente para que nadie vuelva a fiarse de la cifra.

`Money::allocateEvenly()` ya existe y ya está probado con 500 casos aleatorios (`T-018`): el residuo va a la última cuota. Resuélvelo con eso, del lado del servidor, y cuenta en el reporte por dónde viaja el resultado hasta la pantalla.

## 6. Seis cosas que se tuercen solas

1. **El `slug` se autogenera del nombre y es editable mientras el producto no esté publicado** (RB-3, SP-5). Es la misma familia de reglas que el `slug` de la categoría y el de la organización, y **cada una tiene su condición**: aquí es «no publicado», en la categoría es «sin productos publicados», en la organización es «sin catálogos publicados». No unifiques lo que solo se parece.
2. **El SKU duplicado en otra organización se permite** (SP-4). El índice ya lo garantiza desde `T-024`; lo que tiene que existir es el test de tenancy que lo afirma, porque es la clase de regla que alguien «arregla» con un índice global.
3. **Los defaults de RB-4 salen de `organization_settings`**, no de constantes: `SettingKey::DefaultInstallmentsCount` y `DefaultFrequencyDays` ya existen con su alcance declarado. Y salen **al abrir el formulario**, no al guardar: un default aplicado en el servidor sobre un campo que la persona dejó vacío a propósito es un valor que nadie eligió.
4. **Cambiar el precio no toca los apartados existentes** (RB-6), y en H3 eso lo garantizará el *snapshot* del apartado. Hoy no hay apartados, así que lo que te toca es **no** escribir ningún recorrido que los busque: si te ves haciéndolo, es `REQ-LAY-002` y no es tuyo.
5. **La auditoría es de las condiciones de pago, no de todo el producto.** `ProductPricingChanged` cubre precio, inicial, número de cuotas y frecuencia (RB-6). Cambiar la descripción o el nombre no es un evento de la lista cerrada de `REQ-BOF-005` RB-1. Y `properties` lleva el antes y el después, **redactando después de comparar** (RB-5b).
6. **Las etiquetas que adjuntas tienen que ser de la misma organización.** `product_tag` no lleva `organization_id` —está declarado así desde `T-024`— y el trait del tenant **no** lo comprueba: lanza cuando alguien asigna una organización ajena a mano, no cuando una clave foránea apunta a otra. La guarda va en tu acción, que es donde `REQ-ORG-002` RB-7 la pone, con su test de relación cruzada.

## 7. Hecho cuando

- [ ] Las **seis rutas** del contrato existen, con **superficie** y **supervivencia a suspensión** declaradas; `suspended` bloquea crear y editar (RB-11).
- [ ] **Matriz de las seis reglas de coherencia** con sus casos límite, incluidos `down_payment == price` y `compare_at_price == price`.
- [ ] Importe con **más de dos decimales rechazado**, no redondeado (SP-8).
- [ ] **SKU duplicado en otra organización permitido** (test de tenancy) y en la misma `422` (SP-4).
- [ ] El `slug` se autogenera y es **inmutable tras publicar** (SP-5).
- [ ] Los **defaults de la organización** llegan al formulario (RB-4).
- [ ] La **previsualización** usa el cálculo del dominio (§5), no una división escrita en el cliente.
- [ ] `ProductPricingChanged` se audita con antes y después; ningún otro evento nuevo.
- [ ] El archivado funciona por `ChangeProductStatusAction` **en su forma mínima**, con `ProductStatusChanged` (§4.4).
- [ ] Las **etiquetas ajenas se rechazan** en la acción, con test (§6.6).
- [ ] Producto de otra organización → `404` (SP-11).
- [ ] `tenancy:audit` y la suite de tenancy en verde, con los casos nuevos.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Por dónde viaja la previsualización del plan desde el dominio hasta la pantalla, y qué impide que alguien reimplemente la división en el `.tsx` mañana.
2. Cómo dejaste `ChangeProductStatusAction` para que `T-030` la complete sin reescribirla, y qué hace hoy exactamente.
3. Qué pasa cuando alguien guarda un producto con una categoría nueva y una etiqueta nueva a la vez, y cómo interviene `TaxonomyByName`.
4. Qué decidiste con `stock` y el estado (RB-4 de `REQ-CAT-007`: agotado **no** cambia el estado), y dónde queda escrito.
5. Si alguna regla de `REQ-BOF-020` te pidió algo que hoy no se puede comprobar, cuál y a qué tarea pertenece. **No la des por hecha ni la tapes.**
6. Nada más. No propongas siguientes pasos.
