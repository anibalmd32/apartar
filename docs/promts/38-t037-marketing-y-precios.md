# PROMPT 38 — `T-037` · Marketing y precios

> Hito **H2 · Catálogo** · Tarea `T-037` · Fase `B` · 2 pts
> **Cierra `REQ-SUB-001`.**
> Depende de `T-011` (el modelo `Plan`, `PlanFeature`, `UsageMetric` y `PlanSeeder`, todo escrito) y de `T-036` (`c856b29`, que dejó la raíz del dominio protegida).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-037: marketing y precios`.

## 1. Cómo correr las pruebas

Las tres últimas sesiones lo hicieron bien y la verificación se estabilizó en siete minutos. Mismo orden:

1. **Mientras trabajas**, solo lo que tocas.
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`. Son los que corren primero dentro de `composer check`, y fallar ahí cuesta un minuto en vez de siete.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio, sin sondear en segundo plano.
5. **Una sola vuelta completa.** Si la primera sale verde, no la repitas para confirmar.

## 2. Qué vas a hacer

La cara de la plataforma: la portada, la página de precios y las dos legales. Es la última tarea pequeña de H2 y la primera que le habla a alguien que **todavía no es cliente**.

El trabajo de dominio ya está hecho: `Plan`, `PlanFeature`, `UsageMetric` y el seeder los escribió `T-011`, con `limitFor()`, `hasFeature()`, `enabledFeatures()` y `scopePublic()`. Lo tuyo es leerlo y pintarlo. Si te encuentras añadiendo un método al modelo, párate: casi seguro ya existe.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/50-planes-y-suscripciones.md](../specs/50-planes-y-suscripciones.md) → **`REQ-SUB-001`** | tu requisito; **RB-1, RB-2, RB-6, RB-8, SP-2 y SP-3** son los que se tuercen |
| el mismo requisito → **RB-3b** | **léelo dos veces**: es §5 de este prompt |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → §7.1 | la tabla de rutas de marketing, con sus cuatro controladores |
| [docs/specs/10-identidad-y-autenticacion.md](../specs/10-identidad-y-autenticacion.md) → `REQ-IDN-005` | quién va a **consumir** tus páginas legales, y cuándo |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-011`, `T-036`, `T-037`, `T-045`, `T-067` | lo que ya existe y lo que te va a llamar |
| `app/Models/Plan.php` | **escrito y probado**: `limitFor()`, `hasFeature()`, `enabledFeatures()`, `scopePublic()` |
| `database/seeders/PlanSeeder.php` | la matriz sembrada, y su cabecera explica lo de §5 |
| `app/Support/ReservedSlugs.php`, `tests/Feature/Catalog/StorefrontRoutingTest.php` | por qué tus rutas ya no compiten con la vitrina (§7) |

## 4. Alcance

**Sí:**

1. **`Marketing\HomeController`** y la portada, sustituyendo el *closure* que hay hoy en `routes/web.php`.
2. **`Marketing\PricingController`** y `/precios`, **renderizado desde `plans`** (RB-8).
3. **`Marketing\LegalController`** y las dos páginas `/terminos` y `/privacidad` (§6).
4. Sus tests, incluido el de §5.

**No:**

- **No** toques `Plan`, `PlanFeature`, `UsageMetric` ni el seeder: son de `T-011` y están cerrados. En particular, **no cambies ningún precio** (§5).
- **No** escribas `/backoffice/suscripcion`: esa ruta de `REQ-SUB-001` es de **`T-067`**, con la UI de límites.
- **No** construyas registro ni login: ya existen.
- **No** inventes texto legal (§6).
- **No** emitas telemetría.

## 5. La página de precios va a decir que Pro es gratis, y eso no es un fallo tuyo

**RB-3b: los precios de Pro y Business están sin decidir.** Ni la matriz de RB-3 ni §5 de [03](../specs/03-pivote-saas-impacto.md) fijan cifra, y `T-011` sembró los tres planes a `price_cents = 0` en lugar de inventarla —que fue lo correcto—. Se fijan en H5, con `REQ-SUB-006`, y entonces es un `UPDATE` sobre `plans`, que es justo lo que RB-1 promete.

Así que tu página, hecha bien, va a mostrar **«Gratis» en los tres planes**. No lo arregles inventando un precio, y no lo arregles metiendo una excepción en el `.tsx`: RB-8 dice que la página se renderiza desde `plans` y **sin tabla escrita a mano en el frontend**, y una cifra escrita en el código sería exactamente eso, además de dejar dos verdades sobre el precio en dos sitios.

Lo que sí te toca es que esto **no se pueda desplegar en silencio**. Hoy da igual, porque no hay producción; el día que la haya, una página pública que dice que el plan de pago es gratis no es un detalle cosmético, es una oferta. Usa la convención de siempre —«lo aplazado se marca con un test que se pone rojo el día que llega»—, pero **al revés**: un caso que afirma el estado de hoy —los planes públicos de pago están a `0`— y cuyo mensaje de fallo diga que los precios ya se fijaron y que hay que revisar qué enseña `/precios` y cómo lo dice. Se pondrá rojo con el `UPDATE` de H5, que es cuando alguien tiene que mirar esa página.

## 6. Tres cosas de la página de precios, y una de las legales

1. **`NULL` es «ilimitado», y nunca «0»** (RB-2, SP-2). Son la misma columna con dos significados opuestos: `max_catalogs = 1` en Free es un tope, y `NULL` en Business es que no lo hay. Pintar `NULL` como `0` convierte el plan más caro en el más restringido. `limitFor()` devuelve `?int` justamente para que la distinción llegue entera hasta la vista.
2. **Solo los planes `is_public`** (RB-6), con `scopePublic()`, que además los ordena por `position`. Un plan negociado existe, tiene organizaciones dentro y **no sale aquí** (SP-3); si lo despublican, los suyos siguen exactamente igual. Un test de eso.
3. **Sin `features` no es un error** (SP-1): es un plan sin funciones de pago. `enabledFeatures()` ya devuelve la lista vacía; lo que no puede pasar es un `500` ni una sección en blanco con su encabezado.

Y las legales: **no escribas condiciones de servicio ni política de privacidad inventadas.** Las va a consumir `REQ-IDN-005` en `T-045`, donde el cliente marca una casilla de aceptación que se persiste con su `ip_address` como evidencia — es decir, alguien va a **aceptar** lo que pongas ahí. Monta la ruta, el controlador y la página con su estructura y un contenido **marcado sin ambigüedad como pendiente de redacción**, y déjalo dicho en el informe. Un texto legal plausible pero falso es peor que un hueco visible: el hueco se rellena, y el plausible se despliega.

## 7. Tus rutas ya no compiten con la vitrina, y conviene que sepas por qué

`T-036` puso la vitrina en `/{organization:slug}`, un comodín de un segmento en la raíz del dominio. Antes de ella, registrar `/precios` en el sitio equivocado lo habría hecho desaparecer sin un solo error en ningún log.

Eso ya está resuelto y **no depende de dónde registres tus rutas**: el parámetro de la vitrina lleva `->where('organization', ReservedSlugs::routePattern())`, y `precios`, `terminos` y `privacidad` están en esa lista. `StorefrontRoutingTest` incluso ensaya tu caso registrando un `/precios` detrás de la vitrina. Lo que sí te toca: **tus cuatro rutas van en `routes/web.php`**, como dice §7.1, y **declaran su superficie de tenancy** —marketing opera **sin** organización— o la suite de `REQ-QAX-008` falla, que es lo que existe para que ninguna ruta se quede sin declarar.

## 8. Hecho cuando

- [ ] **Los tres planes se leen de `plans`** y la página no tiene ninguna cifra ni ninguna tabla escrita a mano (RB-8).
- [ ] **`NULL` se muestra como «ilimitado»**, nunca como «0» (SP-2).
- [ ] **Solo los `is_public`**, ordenados por `position`; un plan despublicado **no sale y sus organizaciones siguen igual** (RB-6, SP-3).
- [ ] Un plan **sin `features`** se pinta como sin funciones de pago, sin error ni sección vacía (SP-1).
- [ ] El caso de §5 está escrito y **se pondrá rojo cuando se fijen los precios**.
- [ ] Portada, `/precios`, `/terminos` y `/privacidad` responden, **todas declarando su superficie de tenancy**.
- [ ] Las legales llevan su contenido **marcado como pendiente de redacción**, sin texto legal inventado.
- [ ] «Empezar gratis» lleva al registro (*happy path* 2).
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Qué enseña hoy `/precios` en la fila del precio de cada plan, con las palabras exactas, y qué forma tiene el caso de §5.
2. Cómo viaja la diferencia entre `NULL` y `0` desde la columna hasta la pantalla.
3. Qué consultas se ejecutan para pintar `/precios`, y cuántas en total.
4. Qué pusiste en las páginas legales y cómo queda marcado que falta redactarlas.
5. Qué queda de `REQ-SUB-001` sin cerrar y de qué tarea es.
6. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste, para seguir la serie.
7. Nada más. No propongas siguientes pasos.
