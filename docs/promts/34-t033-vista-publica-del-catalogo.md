# PROMPT 34 — `T-033` · Vista pública del catálogo

> Hito **H2 · Catálogo** · Tarea `T-033` · Fase `B` · 5 pts
> **Cierra `REQ-CAT-021`** y salda media deuda de `REQ-ORG-002` (§5).
> Depende de `T-032` (`48ce899`, catálogos publicados con su slug), de `T-030` (`ef4ee33`, `scopePublished()`) y de `T-022` (`0913525`, la proyección bimoneda que por fin tiene dónde vivir).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-033: vista publica del catalogo`.

## 1. Cómo correr las pruebas

La suite corre en paralelo desde `T-003b` y una verificación completa son unos seis minutos. Respeta el orden, que está medido:

1. **Mientras trabajas**, solo lo que tocas: `docker compose exec -T app php vendor/bin/pest tests/Feature/Catalog`.
2. **`composer test:quality`** antes de la vuelta completa: medio minuto, y contiene reglas estructurales que descubiertas al final cuestan la pasada entera.
3. **`composer check`** (incluye el gate de cobertura: **no lo lances aparte**) y después **`npm run check`**. **Nunca los dos a la vez**: `vite build` escribe en `public/` mientras la suite falsea ese disco, y salen veinticinco fallos que no son tuyos.
4. En primer plano y con *timeout* amplio, sin sondear en segundo plano.

Si escribes un test que abra **dos conexiones** para provocar un bloqueo, va en `tests/Feature/Concurrency/` — y si lo dejas fuera, hay un test que te lo dirá.

## 2. Qué vas a hacer

**El enlace que un desconocido abre.** Es el objetivo demostrable de H2 y la primera superficie pública del proyecto: sin sesión, sin backoffice, con el tenant resuelto **desde el catálogo** y no desde quien mira.

Es también la primera página con precios de verdad, así que todo lo que H1 dejó escrito —`ProjectsMoney`, `MoneyLabel`, `CurrencyToggle`, `RateStamp`— deja hoy de estar esperando.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/12-catalogos-curados.md](../specs/12-catalogos-curados.md) → **`REQ-CAT-021`** | tus doce reglas y nueve `SP-`; RB-3 explica por qué esta página sostiene el modelo entero |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-002` RB-4 | las cuatro vías de resolución del tenant; hoy enrutas la segunda |
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → `REQ-FX-004`, `REQ-FX-005` | cómo se proyecta y qué pasa sin tasa — ya está escrito, solo hay que montarlo |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-033`, `T-034`, `T-035`, `T-073`, `T-084` | lo que **no** es tuyo, que es bastante (§6) |
| `app/Http/Middleware/ResolveTenant.php`, `app/Domain/Tenancy/TenantResolver.php` | el hermano que ya existe y el resolutor que ya sabe hacerlo |
| `app/Models/Catalog.php` → `visibility()`, `app/Http/Resources/Concerns/ProjectsMoney.php` | el estado derivado que decide tu respuesta, y el único sitio por el que sale un importe |

## 4. Alcance

**Sí:**

1. **`ResolveCatalogTenant`** y `routes/catalog.php`, con la declaración de superficie que el gate exige.
2. **`CatalogShowController`**, **`CatalogViewResource`**, la página `catalog/CatalogShow.tsx` y `CatalogLayout`.
3. Las **tres respuestas** de visibilidad: `404`, `410` y render (§5).
4. **Paginación por cursor**, 12 por lote, **sin `count(*)`** (RB-6, SP-7).
5. **Las cards en bimoneda** con su sello de tasa, montando lo de `T-022` (§7).
6. **Open Graph** (RB-10) y el **sello de plataforma** en Free (RB-9).
7. **El refresco de cinco minutos de la tasa** contra `GET /api/tasa`, que el plan dejó asignado aquí.
8. `CatalogFacetsQuery` con sus tests (§6.1).

**No:**

- **No** implementes los filtros, el buscador ni el orden: son **`T-034`**. Ver §6.1.
- **No** escribas la ficha de producto: es **`T-035`**.
- **No** emitas `catalog_viewed` (RB-11) ni cablees `AssignVisitorId`: son **`T-084`**, y no se finge un emisor.
- **No** implementes «Seguir este catálogo» (RB-8): `catalog_subscriptions` llega con **`T-073`**, en H6.
- **No** toques el backoffice.

## 5. El tenant sale del catálogo, y esa es la pieza que sostiene el modelo

RB-3 lo dice sin rodeos: **de este catálogo se deriva todo el contexto** —la organización, su taxonomía, su tasa, sus cuentas de cobro, su *branding*—. Hasta hoy el tenant venía de la sesión de alguien que había iniciado sesión; aquí viene de un *slug* en la URL que escribe cualquiera.

Dos consecuencias que no son detalles:

- **El orden de los middlewares importa igual que en el backoffice.** `ResolveTenant` corre antes que `SubstituteBindings` con prioridad explícita desde `T-006`, porque al revés el binding de un modelo del tenant se resuelve sin organización fijada. Tu middleware tiene el mismo problema y la misma solución; hay un test que fija ese orden y conviene que cubra también el tuyo.
- **`REQ-ORG-002` no está cerrado y hoy se salda la mitad.** El plan lo dice bajo `T-006`: faltaban por enrutar `ResolveCatalogTenant` (este) y `ResolvePortalTenant` (H3). Cuando termines, queda uno.

Y las **tres respuestas**, que salen de `Catalog::visibility()` y no de una consulta nueva:

| Estado | Respuesta | Por qué |
|---|---|---|
| *slug* inexistente o `Draft` | `404` | nunca existió para el público (SP-1, SP-2) |
| `Expired` | **`410 Gone`** | «existió» no es lo mismo que «no existe» (SP-3) |
| `Listed` / `Unlisted` | render | `is_listed` **no** controla el acceso |

Un catálogo de una organización suspendida está despublicado, así que cae solo en el `404` (SP-5, RB-12): no escribas una cuarta rama para eso.

## 6. Lo que no es tuyo, y un reparto que conviene acordar

1. **Los filtros y el buscador son `T-034`.** Tu tarea entrega `CatalogFacetsQuery` —la consulta que cuenta categorías y etiquetas **presentes en ese catálogo**— con sus tests, y la parrilla en el **orden curado por defecto**. Lo que **no** conviene es dejar un panel de filtros pintado que no filtre: si te sale más limpio entregar la consulta probada sin panel, hazlo y dilo. Un control que no hace nada es peor que un control que todavía no está.
2. **`catalog_viewed` no se emite** (RB-11). El bus de telemetría es `T-084`, y este proyecto ya decidió en `T-022` que **no se deja un emisor que trague eventos en silencio**: es indistinguible de uno conectado.
3. **El CTA de seguir** (RB-8) necesita cuentas de consumidor y suscripciones, que son H6. No lo pintes deshabilitado «para que se vea»: enseña una función que no existe.

## 7. La bimoneda ya está escrita: móntala, no la reescribas

Esto es lo que `T-022` dejó preparado y hoy estrena consumidor:

- **`ProjectsMoney`** es el **único** sitio por el que un importe sale al cliente. Compone el `MoneyView` con las dos cadenas ya formateadas.
- **`CurrentRate`** resuelve la tasa **una vez por respuesta**, y de ahí salen tanto el sello como la proyección de cada card. Si tu controlador pide la tasa por su cuenta, rompes justo la garantía por la que existe.
- **Sin tasa no hay bolívares**: `ves` es `null`, la card enseña solo USD y el *toggle* se deshabilita (`REQ-FX-005` RB-4). **Nunca** `Bs. 0,00` ni un guion.
- **El refresco de cinco minutos** contra `GET /api/tasa` es tuyo —el endpoint existe desde `T-020` sin llamante—. Actualiza **el sello**, no los importes: vienen formateados del servidor y se reproyectan en la siguiente navegación Inertia (`REQ-FX-004` SP-4). Si te ves multiplicando en el `.tsx`, la cuenta está en el lado equivocado.

## 8. Cinco cosas que se tuercen solas

1. **La paginación por cursor no cuenta filas** (SP-7, RB-6). Un `count(*)` sobre un catálogo de 500 productos lo paga cada visita para pintar un número que nadie mira; y el cursor manipulado **se reinicia en la primera página**, no falla (SP-9).
2. **Los `draft` y `archived` se omiten en silencio y conservan su relación** (RB-4). El mecanismo ya existe —`scopePublished()`, de `T-030`— y la relación no filtra por estado a propósito, desde `T-025`. Filtrar en la relación desactivaría `REQ-CAT-007` RB-7 sin que nada fallara.
3. **Un catálogo publicado con todos sus productos despublicados se renderiza vacío** (SP-4), con un estado que invita a escribir al negocio. No es un `404`: el enlace es válido y el negocio sigue ahí.
4. **El sello de plataforma sale de `Plan::showsPlatformBadge()`**, que ya existe: es el complemento exacto de la función `branding` y no una fila aparte de la matriz de planes.
5. **Esta es una superficie pública**: todo lo que viaje en las props lo verá cualquiera. Nada de `account_id`, nada de datos de otros negocios, nada del backoffice. El gate de aislamiento tiene un test de fuga con dos organizaciones con catálogos distintos, y aquí es donde de verdad importa.

## 9. Hecho cuando

- [ ] `GET /c/{catalog:slug}` funciona **sin sesión**, con el tenant resuelto desde el catálogo y su **superficie declarada**.
- [ ] **`unlisted` accesible**, **`draft` → `404`**, **`expired` → `410`**, suspendida → `404` (§5).
- [ ] **El tenant se resuelve del catálogo y todas las consultas quedan filtradas**, con test de fuga entre dos organizaciones.
- [ ] Productos `draft` y `archived` **omitidos conservando su relación**.
- [ ] **Orden curado por defecto** y **paginación por cursor sin `count(*)`**; cursor manipulado reinicia (SP-9).
- [ ] Las cards muestran **precio, inicial y cuota en bimoneda** con el sello de la tasa, y **sin tasa solo USD** (§7).
- [ ] El **refresco de cinco minutos** actualiza el sello y no los importes.
- [ ] **Open Graph** presente; **sello de plataforma** en Free.
- [ ] `CatalogFacetsQuery` entregada con tests (§6.1).
- [ ] **Ningún emisor de telemetría**, **ningún CTA de seguir** (§6).
- [ ] `tenancy:audit` y la suite de tenancy en verde, con el caso público nuevo.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro.
- [ ] **Commit hecho.**

## 10. Reporta al terminar

1. Cómo se fija el tenant desde el *slug* y qué pasa con el orden de los middlewares; qué test lo sostiene.
2. Qué consulta exactamente la parrilla y cómo demuestras que no hay `count(*)` ni `N+1`.
3. Qué hiciste con las facetas (§6.1) y por qué.
4. Cómo se comporta la página **sin ninguna tasa** en el histórico: qué ve el cliente, campo por campo.
5. Qué queda de `REQ-CAT-021` sin cerrar y de qué tarea es. Y si al montar lo de `T-022` encontraste que algo no encajaba, qué. **No lo reescribas en silencio.**
6. Cuánto tardó la verificación final, para seguir la serie que empezó en `T-032`.
7. Nada más. No propongas siguientes pasos.
