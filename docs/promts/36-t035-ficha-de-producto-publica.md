# PROMPT 36 — `T-035` · Ficha de producto pública

> Hito **H2 · Catálogo** · Tarea `T-035` · Fase `B` · 3 pts
> **Cierra `REQ-CAT-003`.**
> Depende de `T-033` (`f49881d`, la página del catálogo y su ruta), `T-034` (`c09f7e2`, la parrilla con filtros), `T-028` (`a1785c6`, `SpecificationGroups`) y `T-029` (`fa5501f`, `ImageSet`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-035: ficha de producto publica`.

## 1. Cómo correr las pruebas, y qué se hizo mal la vez pasada

La suite entera tarda unos siete minutos. La sesión anterior gastó **una vuelta completa de más** por un aviso de PHPStan **en un test**, que cuesta menos de un minuto descubrir. Así que, en este orden:

1. **Mientras trabajas**, solo lo que tocas: `docker compose exec -T app php vendor/bin/pest tests/Feature/Catalog`.
2. **Antes de la vuelta completa, los baratos**: `composer lint`, `composer analyse` y `composer test:quality`. Los dos primeros fallan por lo que no se ve al ejecutar, y son justo los que corren **primero** dentro de `composer check`: si fallan ahí, la suite no llega a arrancar y has perdido un minuto, no siete.
3. **`composer check`** —que ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio, sin sondear en segundo plano.
5. **Una sola vuelta completa.** Si la primera sale verde, no la repitas para confirmar.

## 2. Qué vas a hacer

La ficha del producto: la página donde el cliente decide. Trae galería, ficha técnica, el desglose de cuánto paga de inicial y de cuotas, y **dónde le paga al negocio**. Es la primera pantalla del proyecto en la que alguien va a comprometer dinero, y la última de la cadena del enlace: `T-033` abrió el catálogo, `T-034` lo hizo navegable, y aquí la card de la parrilla —que hoy **no es un enlace a propósito**— pasa a serlo.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → **`REQ-CAT-003`** | tu requisito, y la nota que lo encabeza dice qué de él **no es tuyo** |
| el mismo fichero → contrato `ProductDetail`, en el bloque de tipos | el contrato exacto, que **extiende `ProductCard`** |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-035`, `T-041`, `T-045`, `T-067`, `T-080`, `T-084` | lo que heredas escrito y lo que se deja marcado para otro |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-007` | las cuentas de cobro: qué son y qué se enseña de ellas |
| `app/Http/Resources/ProductCardResource.php` | **el patrón**: importes por `ProjectsMoney`, cuota por `FinancingPlan`, ni un identificador de base |
| `app/Domain/Catalog/FinancingPlan.php`, `SpecificationGroups.php`, `ImageSet.php` | las tres piezas que ya existen y que aquí solo se llaman |
| `app/Domain/Payment/PaymentAccountView.php`, `resources/js/types/payment.ts` | `PaymentAccountView` **ya está escrito**: úsalo tal cual |
| `routes/catalog.php` | su comentario de cabecera explica dónde entra tu ruta y por qué el tenant sale del catálogo |

## 4. Alcance

**Sí:**

1. **La ruta** `GET /c/{catalog:slug}/p/{product:slug}` dentro del grupo `catalog-tenant` que ya existe.
2. **`ProductShowController`** y **`ProductDetailResource`**.
3. **La página `catalog/ProductShow.tsx`**: galería, precio y desglose en bimoneda, ficha técnica, cuentas de cobro, relacionados, CTA.
4. **Enlazar la card** de la parrilla, que `T-033` dejó sin enlace esperando esta ruta.
5. Sus tests, incluidos el de acotación y el de fuga.

**No:**

- **No** escribas `ScheduleGenerator` ni nada de `app/Domain/Layaway/` (§5).
- **No** emitas `product_viewed` ni midas permanencia (RB-8): es **`T-084`**.
- **No** construyas el formulario de apartado ni su ruta: es **`T-045`** (§7.3).
- **No** llames a `LimitEnforcer` ni lo escribas: es **`T-067`** (§7.2).
- **No** toques el backoffice, ni la parrilla más allá del enlace, ni `CatalogProductsQuery`.

## 5. La cuota ya está repartida, y repartirla otra vez es el error

El gate de `REQ-CAT-003` nombra `ScheduleGenerator` en su superficie esperada, y **el plan lo corrigió**: esa clase es `REQ-LAY-004` y llega en `T-041`. La cuota que enseña esta página la reparte **`FinancingPlan`**, que existe desde `T-027`, usa `Money::allocateEvenly()` y es exactamente la misma cuenta que ya enseñan la previsualización del backoffice y cada card de la parrilla.

Por qué importa: si escribes aquí una tercera división, el cliente verá en la card una cuota y en la ficha otra por un céntimo, y no habrá forma de decir cuál es la buena. **Una sola cuenta, llamada tres veces.** RB-2 pide además que la UI diga «última cuota: $X» cuando el reparto no es exacto, y `FinancingPlan::hasUnevenLastInstallment()` ya responde a eso.

Lo que queda sin probar —y no es tuyo— es que esa cuenta y la del cronograma coincidan. **Deja el caso en rojo diferido**, con el mensaje que le diga a `T-041` qué escribir en su lugar, igual que hizo `T-032` con el cupo del plan (ver la convención en «Cómo leer este plan»).

## 6. El `404` tiene cuatro motivos y una sola respuesta

Un producto puede no verse porque no está en ese catálogo (SP-1), porque es `draft` o está archivado (SP-2), porque su *slug* cambió después de compartirse (SP-9), o porque es de otra organización (test de fuga). **Los cuatro dan `404` y ninguno dice cuál fue.**

No es pereza: decir «existe pero está en borrador» en una superficie pública y sin sesión le cuenta a cualquiera con la URL que el negocio está preparando algo. Y distinguir «no está en este catálogo» de «no existe» permite recorrer el catálogo del vecino a base de probar *slugs*.

El catálogo es el ancla, y eso ya está resuelto: `catalog-tenant` fija la organización desde el *slug* del catálogo **antes** de resolver los *bindings*, así que `{product:slug}` se resuelve ya con el *global scope* puesto. Lo que te toca es **la pertenencia al catálogo**, que el *scope* no cubre: un producto publicado de la misma organización pero de otro catálogo tiene que dar `404` igual. Y SP-3 manda antes que todo esto: catálogo expirado `410`, despublicado `404`, **antes** de mirar el producto.

## 7. Tres piezas que no son tuyas, y cómo se dejan

1. **`product_viewed` y la permanencia** (RB-8) son de **`T-084`**, que trae el bus. Ni emisor silencioso ni clase vacía: un caso que se ponga rojo el día que el bus exista, con el mensaje de qué escribir en su lugar.
2. **El motivo «el negocio no puede recibir apartados ahora»** (RB-7, que es `REQ-SUB-004` RB-4) lo inserta **`T-067`** con los otros doce puntos de límite. `unavailableReason` **nace con ese caso declarado y sin forma de alcanzarlo**, porque el contrato del cliente no puede cambiar de forma cuando llegue el límite. Y su mensaje es neutro: **nunca dice el plan del negocio**, que es asunto del negocio y no de quien mira el producto. Los otros dos motivos —agotado, producto no apartable— sí se alcanzan hoy.
3. **El CTA «Apartar»** abre el formulario de `REQ-LAY-001`, que es **`T-045`**. No inventes la ruta. Se renderiza, se deshabilita con su motivo cuando toca, y cuando no toca se queda sin destino, exactamente como `T-033` dejó la card. `maxQuantity` (RB-10) **sí viaja ya**, porque es parte del contrato: `min(stock, 10)`.

Y una cuarta, del lado del texto: **SP-6** —negocio sin cuentas de cobro activas— manda a «consultar al negocio por el chat», y el chat es **`T-080`**. Texto sin enlace, como el estado vacío que dejó `T-034`.

## 8. Dos redundancias del contrato que tienes que resolver, no arrastrar

El contrato de `REQ-CAT-003` declara props `{ product, related, catalog, business, rate }` **y** un `business` dentro de `ProductDetail`. Son el mismo dato escrito dos veces, y dos sitios para el mismo dato son dos sitios donde puede discrepar. Elige uno, y **escribe en la spec cuál y por qué** ([11](../specs/11-catalogo-y-productos.md), como nota bajo el requisito).

Y `rate`: desde `T-022` la tasa viaja **sola** en cada respuesta, por `HandleInertiaRequests`, como `CurrencyPageProps`. Una página que la declare otra vez en sus props abre la puerta a que el sello diga una tasa y las cifras estén calculadas con otra. Mira cómo lo resolvió `CatalogShowController` y haz lo mismo.

## 9. Cinco cosas que se tuercen solas

1. **Los importes se proyectan por `ProjectsMoney`, todos, siempre.** Es el único sitio por el que un importe cruza al cliente, y es lo que garantiza que el sello de la página y cada cifra se calcularon con **la misma tasa de esta respuesta** (`CurrentRate`). Sin tasa, solo dólares (SP-8, `REQ-FX-005` RB-4).
2. **Al cliente no le llega ninguna cifra con la que dividir.** Es la regla que dejó escrita `T-027`: llega el `MoneyView` ya formateado, no el precio en centavos y el número de cuotas para que el `.tsx` lo reparta. El *toggle* de moneda reproyecta **sin petición** porque las dos monedas ya vienen, no porque el cliente calcule.
3. **Sin ficha técnica la sección se omite entera** (SP-5): sin encabezado vacío, sin «—», sin nada. Un bloque «Especificaciones» con el interior en blanco parece un fallo de carga.
4. **Sin imágenes, placeholder con la inicial** (SP-4). Nunca una imagen rota: una foto rota en la pantalla donde se decide pagar dice del negocio lo que no es verdad.
5. **Relacionados: hasta 4, del mismo catálogo, sin el actual, de su categoría si hay y por orden curado si no** (RB-9). Del mismo catálogo, no de la organización: un producto que el negocio no puso aquí no está aquí. Y sin `count(*)`, como todo lo público.

## 10. Hecho cuando

- [ ] La ruta resuelve **producto dentro de catálogo**, y **fuera del catálogo da `404`** (SP-1) aunque exista y esté publicado.
- [ ] `draft` y `archived` por URL directa dan **`404`** (SP-2); *slug* cambiado, **`404`** (SP-9).
- [ ] **Producto de otra organización da `404`** (test de fuga).
- [ ] Catálogo expirado da `410` y despublicado `404` **antes** de resolver el producto (SP-3).
- [ ] La cuota es la de `FinancingPlan`, **la misma que la card**, con «última cuota» cuando difiere (RB-2), y el acuerdo con el cronograma queda **en rojo diferido** para `T-041`.
- [ ] Las **cuentas de cobro activas** aparecen y **las inactivas no** (RB-5); sin ninguna activa, el texto de SP-6 sin enlace.
- [ ] `canBeReserved` y `unavailableReason` con sus motivos; el de límite de plan **declarado, inalcanzable y sin revelar el plan**.
- [ ] Sin ficha técnica **no se renderiza la sección** (SP-5); sin imágenes, placeholder (SP-4).
- [ ] Relacionados: hasta 4, del mismo catálogo, sin el actual (RB-9).
- [ ] El *toggle* de moneda **reproyecta sin petición**.
- [ ] **La card de la parrilla enlaza** a la ficha.
- [ ] **Ningún emisor de telemetría** y **ninguna llamada a `LimitEnforcer`**.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 11. Reporta al terminar

1. Dónde se decide cada uno de los cuatro `404` de §6, y en qué orden se comprueban.
2. De dónde sale cada importe de la página, y qué garantiza que la cuota de la card y la de la ficha no puedan diferir.
3. Cómo resolviste cada una de las dos redundancias de §8, y qué escribiste en la spec.
4. Qué consultas se ejecutan para pintar la ficha, y cuántas en total.
5. Qué queda de `REQ-CAT-003` sin cerrar y de qué tarea es cada pieza.
6. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste, para seguir la serie.
7. Nada más. No propongas siguientes pasos.
