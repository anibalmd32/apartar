# PROMPT 32 — `T-032` · Publicación de catálogos

> Hito **H2 · Catálogo** · Tarea `T-032` · Fase `B` · 3 pts
> **Cierra `REQ-CAT-022` y `REQ-BOF-024`** salvo lo que depende de H5 y H6 (§5).
> Depende de `T-031` (`7e48a8d`, la composición y `CatalogProductSorter` sin pantalla), de `T-030` (`ef4ee33`, los productos publicados) y de `T-025` (`53dbf4f`, `Catalog::visibility()`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-032: publicacion de catalogos`.

## 0. Qué vas a hacer

El enlace. Todo H2 apuntaba aquí: un negocio arma un catálogo, pulsa publicar y se lleva una URL que puede pegar en WhatsApp. Esta tarea escribe el CRUD del catálogo, los **seis requisitos** que se verifican antes de dejar publicar, el slug global que no colisiona entre negocios y la expiración que funciona sin que corra nada.

Y cierra un relevo abierto desde `T-014`: **en cuanto exista el primer catálogo publicado, el `slug` del negocio deja de poder cambiarse** (§6).

## 1. Cómo correr las pruebas sin alargar la sesión

Esto no es opcional y es nuevo: la suite pasa de 1900 tests y recorrerla entera varias veces es lo que está haciendo largas las sesiones.

- **Mientras trabajas**, solo lo que tocas: `docker compose exec -T app php vendor/bin/pest tests/Feature/Catalog`.
- **Al final, una sola vez**: `composer check`, `composer tenancy` y `npm run check`.
- **`composer check` ya incluye el gate de cobertura** (`lint + analyse + test:coverage`). **No lances `test:coverage` aparte**: sería recorrer la suite dos veces, la segunda instrumentada, que es exactamente el gasto que se acaba de eliminar.
- **En primer plano y con timeout amplio.** No la lances en segundo plano para sondearla después: cada sondeo que vuelve vacío es una vuelta perdida.
- Si ves **dos o tres fallos con `deadlock` al sembrar planes**, repite antes de investigar: es una inestabilidad conocida de los tests de concurrencia, anotada en [91](../specs/91-calidad-y-gates.md). Si persiste al repetir, entonces sí es tuyo.

## 2. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/12-catalogos-curados.md](../specs/12-catalogos-curados.md) → **`REQ-CAT-022`** | los seis requisitos de RB-4 y las diez `SP-`; RB-1 y SP-7 son las del slug |
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → **`REQ-BOF-024`** | el CRUD, los badges y lo que la UI tiene que explicar **antes** de publicar |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-006` SP-5 | el relevo que cierras sin escribir una línea (§6) |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-032`, `T-066`, `T-067`, `T-073` | lo que no es tuyo y por qué |
| `app/Domain/Catalog/TaxonomyByName.php` | **el patrón del slug**: índice + reintento, sin leer lo del vecino (§4) |
| `app/Models/Catalog.php`, `app/Domain/Catalog/PublishedCatalogs.php` | la visibilidad derivada y el relevo de §6 |
| `app/Domain/Auditing/AuditEvent.php` | tus tres eventos ya están declarados con tu acción como emisora |

## 3. Alcance

**Sí:**

1. **CRUD de `/backoffice/catalogos`** (RB-1 de `REQ-BOF-024`) con sus rutas y sus dos declaraciones.
2. **`PublishCatalogAction`** con **cinco de los seis requisitos** de RB-4, y **`UnpublishCatalogAction`**.
3. **`UniqueCatalogSlug`** con la desambiguación por sufijo, y la inmutabilidad tras publicar.
4. **`ShareLinkField`** y el listado con badge de visibilidad, la URL completa y la explicación de `Listed` vs `Unlisted`.
5. **La auditoría** de los tres eventos ya declarados.
6. **Montar `CatalogProductSorter`**, que `T-031` dejó escrito y probado sin pantalla.
7. Sus tests.

**No:**

- **No** implementes el cupo `catalogs` del plan (RB-4.4, SP-3): es de **`T-067`**, como los otros once puntos.
- **No** implementes el conteo de suscriptores ni el «cuántos avisos habrías enviado» (RB-7 de `REQ-BOF-024`): `catalog_subscriptions` llega con **`T-073`**, en H6.
- **No** escribas la vista pública ni el `410`: es **`T-033`**. Tú dejas el estado; ella responde.
- **No** añadas enlaces con contraseña ni publicación programada: fuera de alcance explícito.

## 4. El slug es global, y aquí no se puede mirar al vecino

RB-1: el `slug` es único **en toda la plataforma**, porque es la URL pública y dos negocios no pueden compartirla. Y eso choca de frente con la regla más dura del proyecto: **`withoutTenantScope()` solo está permitido en `app/Queries/Platform` y `app/Actions/Platform`** (`REQ-ORG-002` RB-1). Un `Catalog::withoutTenantScope()->where('slug', …)` escrito aquí sería la primera grieta en el aislamiento, y encima para leer datos de otro negocio.

**No hace falta.** El índice `UNIQUE(slug)` ya está en la base desde `T-024` y es la autoridad; lo que tú necesitas no es «saber si está libre» sino **acabar con uno libre**. El patrón ya está escrito en este proyecto: `TaxonomyByName` (`T-026`) intenta, choca con el índice, y en la vuelta siguiente desambigua. Aquí es igual, con `-2`, `-3`… como manda RB-1.

Dos consecuencias que la spec fija y conviene no inventar:

- **Los slugs no se reciclan ni se liberan al despublicar** (SP-7). Un catálogo despublicado conserva el suyo: su URL circuló y volver a darla a otro sería mandar a la gente al sitio equivocado.
- **La desambiguación es silenciosa y se muestra**: el negocio tiene que ver el slug que le quedó antes de difundirlo, no descubrirlo después.

## 5. Uno de los seis requisitos no es tuyo, y hay que decirlo

RB-4 lista seis condiciones para publicar. Cinco son tuyas y se verifican **en la `Action`**:

1. al menos un producto **publicado** en el catálogo (SP-1);
2. al menos una **cuenta de cobro activa** en la organización (SP-2) — existen desde `T-015`;
3. `expires_at` vacío o **futuro** (SP-5), y republicar uno expirado sin moverla es `422` (SP-8);
4. ~~cupo del plan~~ → **`T-067`**;
5. **email del `owner` verificado** (SP-4);
6. organización **no suspendida** (SP-9) — que además es lo que la declaración de ruta ya hace.

El cuarto no lo implementes ni lo simules: `LimitEnforcer` responde «sin límite» hoy a propósito. Lo que sí conviene es que tu acción quede escrita de forma que insertar esa comprobación después sea una línea, y que **el test de los seis** exista con cinco verdes y el sexto nombrado como pendiente en el plan, no inventado.

## 6. El relevo que cierras sin escribir una línea

`PublishedCatalogs::exist()` consulta de verdad desde `T-024`, y `UpdateOrganizationAction` la usa desde `T-014` para bloquear el cambio de `slug` del negocio (`REQ-ORG-006` SP-5). Pero **hasta hoy nadie podía publicar un catálogo**, así que esa regla nunca había devuelto `true` en una ruta real.

Con tu `PublishCatalogAction` pasa a ser alcanzable de punta a punta, y eso merece **un test que recorra el camino entero**: publicar un catálogo y comprobar que después el negocio recibe `422` al intentar cambiar su enlace. Es la primera vez que se puede probar como lo vivirá alguien, y es justo la regla que existía para evitar que a un negocio dejen de llegarle clientes sin que nada se lo explique.

## 7. Seis cosas que se tuercen solas

1. **`is_listed` no controla el acceso** (RB-3): un `Unlisted` es accesible por enlace. La UI lo dice **con esas palabras** —«cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina»— porque es la confusión que hace que alguien comparta lo que creía privado.
2. **La expiración no lleva job** (RB-6): `Catalog::visibility()` ya la deriva. Si te ves programando algo que expire catálogos, párate — además, un job que no corra dejaría campañas vendiendo después de su fecha.
3. **El `slug` es inmutable tras la primera publicación** (RB-2, SP-6), y la UI lo explica **antes** de publicar (RB-4 de `REQ-BOF-024`), no después. Ya hay tres reglas de este tipo con condiciones distintas —organización, categoría, producto— y `SlugLockedException` existe con el texto de la organización: decide si la generalizas o si sigues el precedente de `T-027`, que resolvió la del producto con un mensaje de validación. Lo que no vale es un mensaje que hable de catálogos publicados cuando lo que bloquea es otra cosa.
4. **Despublicar devuelve a `Draft` y no borra nada** (RB-7, RB-9): ni suscripciones —que no existen todavía— ni apartados —que tampoco—. Lo que te toca es no escribir ningún recorrido que los busque, y la advertencia explícita antes de confirmar.
5. **Los borradores no consumen cupo** (RB-8 de `REQ-BOF-024`): la métrica `catalogs` cuenta solo publicados. No lo implementas tú, pero es la definición que `T-066` usará, así que no dejes escrito lo contrario en ningún sitio.
6. **Tus tres eventos de auditoría ya están declarados** con tu acción como emisora. En cuanto las clases existan, el test que recorre el enum exigirá que auditen: no son opcionales ni se pueden posponer.

## 8. Hecho cuando

- [ ] CRUD de catálogos completo, con **superficie** y **supervivencia a suspensión** en cada ruta.
- [ ] **Un test por cada uno de los requisitos de publicación**, con el del cupo declarado pendiente (§5).
- [ ] **Slug único global con desambiguación por sufijo**, resuelto con el índice y sin `withoutTenantScope()` (§4).
- [ ] **Slug inmutable tras publicar** (SP-6) y explicado en la UI **antes** (RB-4 de `REQ-BOF-024`).
- [ ] Los slugs **no se reciclan** al despublicar (SP-7).
- [ ] Despublicar devuelve a `Draft` con su advertencia (RB-7, RB-9).
- [ ] `expires_at` pasada rechazada (SP-5); republicar un expirado sin moverla, `422` (SP-8).
- [ ] La expiración se deriva **sin job** (RB-6).
- [ ] **Publicar un catálogo bloquea el cambio de `slug` del negocio**, con test de punta a punta (§6).
- [ ] Los **tres eventos** se auditan.
- [ ] `CatalogProductSorter` queda montado en el formulario.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, **una sola pasada** (§1), sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Cómo resuelves la colisión de slug entre dos organizaciones sin leer los catálogos ajenos, y qué pasa exactamente si dos negocios publican «ofertas-septiembre» a la vez.
2. Qué hiciste con `SlugLockedException` y por qué (§7.3).
3. Qué ve el negocio, palabra por palabra, al publicar sin cuenta de cobro activa y al publicar sin email verificado.
4. Cómo quedó el test de §6 y qué demuestra que no demostrara ya el de `T-014`.
5. Qué partes de `REQ-CAT-022` y `REQ-BOF-024` quedan sin cerrar y de qué tarea son. **No las des por hechas.**
6. Cuánto tardó la verificación final y si la disciplina de §1 se notó. Es información que estoy recogiendo para decidir si hace falta una tarea de infraestructura de la suite.
7. Nada más. No propongas siguientes pasos.
