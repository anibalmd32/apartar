# PROMPT 07 — `T-006` · Resolución del tenant: middlewares y jobs

> Hito **H0 · Cimientos** · Tarea `T-006` · Fase `AB` · 2 pts
> Continúa `REQ-ORG-002`, que **no se cierra aquí**.
> Depende de `T-005` (`93fca2b`) y `T-008` (`0ba84c9`): `TenantContext`, `TenantResolver`, `TenantSurface`, y `User`/`PaymentAccount` como primeros modelos del tenant. 310 tests en verde.

## 0. Qué vas a hacer

Poner en funcionamiento lo que `T-005` dejó construido: **quién fija el tenant, y cuándo**. Hasta ahora el tenant se fija a mano en los tests; a partir de aquí lo fija la infraestructura.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-006` | alcance y, sobre todo, la nota **"Alcance real tras la reordenación"** |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-002` | **RB-4** (la tabla de las cuatro vías), **RB-5** (jobs), **SP-1**, **SP-3**, **SP-7** |
| [docs/specs/90-infraestructura-y-entorno.md](../specs/90-infraestructura-y-entorno.md) → `REQ-INF-003` | **RB-1b**: cómo recibe el tenant un job, y qué hacen los que recorren varias organizaciones |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§5.1 y §7** | orden de llamada de los colaboradores, y la tabla de rutas que dice qué superficie es cada una |

## 2. Alcance, que es menor de lo que dice el plan

El plan pedía cuatro middlewares. **Solo dos se pueden escribir hoy**, y la diferencia está razonada en el plan: `ResolveCatalogTenant` necesita `Catalog` (H2) y `ResolvePortalTenant` necesita `Customer` y `PortalContext` (H3). Escribirlos ahora produciría adaptadores que no se pueden enrutar a ninguna ruta existente — código muerto que nadie ejercita y que envejece hasta que alguien lo reescribe.

**Sí:**

1. **`ResolveTenant`** — fija el tenant desde `auth()->user()->organization_id` para `/backoffice/*`.
2. **`RunsInTenantContext`** (`app/Jobs/Concerns/`) — el tenant llega **como parámetro explícito** del job, se fija al arrancar y se limpia en el `finally`.
3. La guarda de la **superficie de plataforma**: `/plataforma/*` opera **sin** tenant, y eso debe ser una decisión explícita y comprobable, no la ausencia de un middleware.
4. Registro de los middlewares en `bootstrap/app.php` con sus alias.
5. Sus tests.

**No:**

- **No** escribas `ResolveCatalogTenant` ni `ResolvePortalTenant`. Se escriben con su hito.
- **No** implementes `tenancy:audit` ni las suites de fuga: son `T-007`.
- **No** crees rutas de negocio. Para probar los middlewares basta con rutas de prueba definidas en el propio test.

## 3. Las tres decisiones que importan

**1. Un job nunca hereda el tenant del proceso que lo encoló** (`REQ-ORG-002` RB-5). Lo recibe como parámetro y lo fija al arrancar. Esto suena redundante —"si lo encolé desde la organización A, ¿no es obvio que va para A?"— y no lo es: entre encolar y ejecutar pasan minutos, el worker es un proceso distinto que ya procesó otros jobs, y el estado del encolador no viaja. Si el job "heredase" algo, heredaría lo que quedó del job anterior.

**2. `forget()` en el `finally`, sin excepción** (`SP-7`). Una cola compartida procesa jobs de dos organizaciones seguidas. Un job que lanza sin limpiar deja el tenant puesto para el siguiente, y el siguiente lee y escribe en la organización equivocada **sin error visible**. Es la fuga más difícil de detectar de todas las que el diseño contempla, porque solo aparece cuando algo falla antes.

**3. Sesión sin tenant resoluble → cerrar sesión y redirigir al login, nunca consultar sin filtro** (`SP-1`). Si `ResolveTenant` no puede resolver —sesión corrupta, usuario cuya organización se cerró—, la respuesta correcta es echar al usuario, no seguir adelante.

Y la cuarta, que es de forma: **`/plataforma/*` sin tenant tiene que ser afirmativo**. Un middleware que declara "esta superficie opera sin tenant" es comprobable; la ausencia de middleware es indistinguible de un olvido. `T-007` va a derivar tests de la tabla de rutas y necesita saber qué superficie es cada una.

## 4. Los jobs que recorren varias organizaciones

`REQ-INF-003` RB-1b describe un caso que `RunsInTenantContext` **no** cubre y conviene que el diseño lo contemple desde ahora: siete jobs (`ExpireLayawaysJob`, `DetectOverdueLayawaysJob`, `DispatchReminders`, `ReconcileLayaways`, `ProcessTelemetryBatch`, `RecalculateUsage`, `DispatchCatalogDigests`) recorren **todas** las organizaciones.

Esos usan `withoutTenantScope()` para enumerar y fijan el tenant **por elemento o por lote**, nunca uno para toda la ejecución. La herramienta para eso ya existe: `TenantContext::runFor()`, con su restauración en el `finally`.

No implementes esos jobs aquí —cada uno llega con su tarea—, pero deja claro en el código qué mecanismo usa cada clase de job, para que el primero que se escriba no invente un tercer camino.

## 5. Hecho cuando

- [ ] `ResolveTenant` fija el tenant desde la sesión y las consultas quedan filtradas, demostrado con **dos organizaciones**.
- [ ] Sesión sin tenant resoluble → cierre de sesión y redirección, **sin** ninguna consulta sin filtro.
- [ ] Un job **sin tenant fijado** que toca una tabla del tenant **falla** con `MissingTenantContextException`.
- [ ] El tenant **se limpia entre jobs**: dos jobs de organizaciones distintas seguidos, y el segundo no ve nada del primero.
- [ ] El tenant se limpia también cuando el job **lanza una excepción**.
- [ ] La superficie de plataforma opera sin tenant de forma explícita y comprobable.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 6. Reporta al terminar

1. Cómo recibe un job su organización y qué pasa exactamente si el payload no la trae.
2. Cómo probaste que el tenant se limpia entre jobs — el mecanismo del test, no solo que pasa.
3. Qué forma tomó la guarda de la superficie de plataforma.
4. Si algo de `TenantResolver` o del enum `TenantSurface` que `T-005` dejó no encajó al cablearlo de verdad, qué y por qué.
5. Nada más. No propongas siguientes pasos.
