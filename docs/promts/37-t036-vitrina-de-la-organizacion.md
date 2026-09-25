# PROMPT 37 — `T-036` · Vitrina de la organización

> Hito **H2 · Catálogo** · Tarea `T-036` · Fase `B` · 2 pts
> **Cierra `REQ-ORG-006`** (su última pieza; el resto lo cerró `T-014`) **y la última frase de `REQ-CAT-021` SP-3.**
> Depende de `T-014` (ajustes, identidad y `ReservedSlugs`), `T-032` (`PublishedCatalogs::exist()`) y `T-035` (`ae02650`, la superficie pública tal como está hoy).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-036: vitrina de la organizacion`.

## 1. Cómo correr las pruebas

La sesión anterior lo hizo bien —gates baratos primero y **una sola vuelta completa**— y bajó de nueve minutos de verificación a siete. Mismo orden:

1. **Mientras trabajas**, solo lo que tocas: `docker compose exec -T app php vendor/bin/pest tests/Feature/Catalog`.
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`. Son los que corren primero dentro de `composer check`, y fallar ahí cuesta un minuto en vez de siete.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio, sin sondear en segundo plano.
5. **Una sola vuelta completa.** Si la primera sale verde, no la repitas para confirmar.

## 2. Qué vas a hacer

La vitrina: `apartar.store/ferreteria-el-tornillo`, la página que el negocio pone en su bio de Instagram y que lista sus catálogos. Es corta —una consulta y una página— y **la parte delicada no es ninguna de las dos**: es que la ruta es un **comodín de un solo segmento en la raíz del dominio**, y eso la pone en competencia con todas las rutas literales del proyecto, incluidas las que todavía no existen.

Cierra también un cabo que `T-033` dejó atado a propósito: la página del `410` de un catálogo caducado enlaza la vitrina «si está activada», y no lo hacía porque la vitrina no existía. Hay un test esperándote que se pondrá rojo en cuanto declares la ruta.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-006`** RB-5, RB-5b, RB-6, SP-4, SP-8 | tu requisito; de él **solo queda la vitrina** |
| [docs/specs/12-catalogos-curados.md](../specs/12-catalogos-curados.md) → `REQ-CAT-021` SP-3 | la frase del `410` que cierras |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → §7.2 | la tabla de rutas públicas y la lista cerrada de slugs reservados |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-014`, `T-032`, `T-036`, `T-037` | lo que ya está hecho y lo que llega **justo después** de ti |
| `app/Support/ReservedSlugs.php` | **escrito y probado**: su cabecera explica por qué la lista existe |
| `app/Domain/Catalog/CatalogVisibility.php` → `appearsInStorefront()` | **ya responde a tu pregunta**: no escribas otra condición |
| `app/Domain/Tenancy/TenantSurface.php` → `Storefront` | el caso ya está declarado, esperando su middleware |
| `app/Http/Middleware/ResolveCatalogTenant.php`, `DeclaresTenantSurface.php` | el patrón exacto del que sale tu equivalente |
| `routes/catalog.php` | su comentario de cabecera ya dice dónde va tu ruta y por qué |
| `tests/Feature/Catalog/CatalogShowTest.php` → `test_the_storefront_link_of_the_gone_page_is_still_pending()` | el test diferido que te toca sustituir |

## 4. Alcance

**Sí:**

1. **La ruta** `GET /{organization:slug}`, con el middleware que resuelve el tenant desde ese *slug* y declara `TenantSurface::Storefront`.
2. **`OrganizationStorefrontController`** y su consulta de catálogos.
3. **La página** de la vitrina: identidad del negocio y sus catálogos listados.
4. **Enlazar la vitrina desde `catalog/CatalogExpired`** cuando esté activada, y **borrar el test diferido** sustituyéndolo por el de verdad.
5. **El test de colisión** de §6, que es el que realmente protege esto.

**No:**

- **No** toques ajustes, `SettingKey`, `Settings` ni `UpdateOrganizationAction`: son de `T-014` y están cerrados.
- **No** crees la landing, `/precios` ni las páginas legales: son **`T-037`**, la tarea siguiente.
- **No** escribas un índice de organizaciones ni nada que las liste: RB-6 dice que **no existe**, y es lo mismo que RB-9 de `REQ-CAT-006` para las categorías.
- **No** emitas telemetría.
- **No** toques la ficha, la parrilla ni el backoffice.

## 5. La vitrina desactivada y el negocio que no existe dan la misma respuesta

`storefront_enabled` está **desactivada por defecto** (RB-6). Así que hay tres situaciones y solo dos respuestas:

| Situación | Respuesta |
|---|---|
| *Slug* que no es de nadie | `404` |
| Organización real con la vitrina apagada | **`404`, idéntico** |
| Vitrina activada sin catálogos publicados | **`200` con estado vacío** (SP-4), nunca `404` |

Las dos primeras tienen que ser indistinguibles, y no por elegancia: si la vitrina apagada diera `403` o una página que dice «este negocio no tiene vitrina», cualquiera podría averiguar qué nombres de negocio existen en la plataforma probando *slugs* en la barra de direcciones. El cliente de un negocio no tiene por qué saber quién más está aquí.

La tercera es al revés y por el mismo motivo de fondo: el negocio **activó** la vitrina y compartió el enlace; un `404` le haría creer que su enlace está roto cuando lo que pasa es que aún no ha publicado nada. Es la diferencia entre «no hay nada que enseñar» y «no hay nada aquí».

Y una cuarta que ya está decidida y que **no** es un caso nuevo: una organización suspendida tiene sus catálogos despublicados (`REQ-ORG-008` RB-2), así que su vitrina —si estaba activada— sale **vacía**, por el mismo camino que la de quien no ha publicado. No hay respuesta especial para la suspensión, igual que no la hay en la vista del catálogo, donde `T-033` resolvió que el `404` sale del estado del catálogo y no de un middleware.

## 6. El comodín de un segmento es toda la tarea, y se prueba, no se recuerda

`/{organization:slug}` compite con **todas** las rutas literales de un solo segmento: `/precios`, `/registro`, `/terminos`, `/privacidad`. `ReservedSlugs` impide que un negocio se llame así, y la ruta va **al final** de `routes/catalog.php`, que a su vez se carga el último en `routes/web.php`. Las dos cosas juntas bastan **hoy**.

El problema es mañana, y tiene nombre: **`T-037` es la tarea siguiente y trae la landing, `/precios` y las páginas legales.** Si alguna entra por debajo del comodín, no habrá error en ningún log: `/precios` devolverá `404` o, peor, la vitrina de nadie, y lo descubrirá alguien que quería ver los planes.

Así que lo que cierra esto **no es el orden, es un test**: recorre los doce segmentos de `ReservedSlugs` y afirma que **ninguno resuelve al controlador de la vitrina**. Ese test sobrevive a que alguien reordene los ficheros, y se pone rojo el día que `T-037` registre `/precios` en el sitio equivocado — que es exactamente cuando hace falta que hable. Es la misma forma que el *assert* de rutas de `REQ-CAT-006` RB-9 y que la guarda de `PublishedCatalogs`.

## 7. Cuatro cosas que se tuercen solas

1. **Solo `Listed`, y solo los suyos.** `CatalogVisibility::appearsInStorefront()` **ya existe** y responde a esto: no escribas una segunda condición. Un `Unlisted` está deliberadamente fuera —su dueño lo comparte por enlace y no quiere que aparezca en una lista—, y publicarlo en la vitrina sería revelarlo. `Draft` y `Expired` tampoco.
2. **El tenant sale del *slug* de la URL, como en el catálogo.** Mira `ResolveCatalogTenant`: fija la organización **antes** de que el *binding* resuelva el modelo, para que el *global scope* ya esté puesto. Tu middleware hace lo mismo con `{organization:slug}`, y declara `TenantSurface::Storefront`, que es lo que exige el gate de `REQ-QAX-008`.
3. **Branding: se guarda siempre, se aplica según el plan** (RB-5 y RB-5b). En Free, el logo y el color están en la base pero **no se pintan**, y sale el sello «Hecho con Apartar». `OrganizationBrand` ya resuelve esto y la vista del catálogo ya lo usa: mira cómo, y hazlo igual. No llames a `assertHasFeature()` aquí — la pregunta es una lectura, no una guarda.
4. **Ni un `count(*)`.** Es una superficie pública y la regla de SP-7 de `REQ-CAT-021` no cambia de página. Si la vitrina de un negocio con muchos catálogos necesita paginar, `CatalogCursor` ya sabe hacerlo; si no la necesita, no la inventes.

## 8. Hecho cuando

- [ ] `GET /{organization:slug}` lista **solo los catálogos `Listed` de esa organización** (RB-6).
- [ ] **`Unlisted`, `Draft` y `Expired` no aparecen**, por `appearsInStorefront()` y no por una condición nueva.
- [ ] **Test de fuga**: los catálogos de otra organización no aparecen.
- [ ] **Vitrina desactivada da `404`, igual que un *slug* inexistente** (§5).
- [ ] **Vitrina activada y vacía da `200` con estado vacío** (SP-4).
- [ ] Organización suspendida → vitrina vacía, **sin respuesta especial**.
- [ ] **Ningún *slug* reservado resuelve a la vitrina** (el test de §6, recorriendo `ReservedSlugs`).
- [ ] La ruta declara `TenantSurface::Storefront` y pasa el gate de `REQ-QAX-008`.
- [ ] Branding aplicado según el plan; **sello «Hecho con Apartar» en Free** (RB-5).
- [ ] **`catalog/CatalogExpired` enlaza la vitrina cuando está activada**, y el test diferido de `CatalogShowTest` **queda sustituido por el de verdad**.
- [ ] Ni un `count(*)` en la página.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Dónde se decide el `404` de la vitrina apagada, y cómo garantizas que es **indistinguible** del de un *slug* que no existe.
2. Qué forma tiene el test de colisión de §6 y por qué se pondría rojo si `T-037` registra `/precios` en el sitio equivocado.
3. De dónde sale el tenant en esta superficie y en qué orden ocurre respecto al *binding*.
4. Qué consultas se ejecutan para pintar la vitrina, y cuántas en total.
5. Qué quedó de `REQ-ORG-006` y de `REQ-CAT-021` SP-3, si es que queda algo.
6. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste, para seguir la serie.
7. Nada más. No propongas siguientes pasos.
