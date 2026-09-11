# PROMPT 05 — `T-005` · `TenantContext` + `BelongsToOrganization`

> Hito **H0 · Cimientos** · Tarea `T-005` · Fase `AB` · 3 pts
> Cierra parte de `REQ-ORG-002`.
> Depende de `T-004` (`036303e`): las seis tablas de plataforma, el modelo `Organization`, su factory y `CreatesNeighbourOrganization` a medio camino. 192 tests en verde.

## 0. Qué vas a hacer

Los **dos colaboradores más importantes del sistema**. `TenantContext` decide en qué organización estamos; `BelongsToOrganization` hace que ninguna consulta pueda olvidarlo.

Esto no es una tarea de infraestructura más. `REQ-QAX-008` RB-1 lo dice sin rodeos: **una fuga entre organizaciones no es un bug grave, es el fin del producto**. Todo el diseño de aislamiento se apoya en que estas dos clases sean correctas y en que nadie tenga que acordarse de usarlas.

## 1. Lee primero, en este orden

| Documento | Para qué |
|---|---|
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-005` | alcance, "hecho cuando" y los **pendientes que `T-004` dejó para aquí** |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → **`REQ-ORG-002`** | el requisito completo. Es el que más pesa del módulo: léelo entero, incluidos los siete `SP-` |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§5 (1 y 2) y §5.1** | la **firma exacta** de las dos clases y el orden en que se llaman |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§4** | las tres excepciones de unicidad global y las dos tablas puente exentas de `organization_id` |

## 2. Alcance

**Sí:**

1. `app/Domain/Tenancy/TenantContext.php` con **los seis métodos de §5**, firma exacta: `organization()`, `organizationId()`, `hasOrganization()`, `setOrganization()`, `forget()`, `runFor()`. Singleton por ciclo de vida.
2. `app/Domain/Tenancy/BelongsToOrganization.php` con `bootBelongsToOrganization()`, `organization()` y `scopeWithoutTenantScope()`.
3. `app/Domain/Tenancy/TenantResolver.php`.
4. `MissingTenantContextException`, `TenantAlreadySetException`, `CrossTenantRelationException`.
5. `GlobalUniqueExceptions` con **las tres excepciones y las dos tablas exentas**, como lista blanca consultable por código (el gate de `T-007` la va a leer).
6. Completar `CreatesNeighbourOrganization`: `actingAsOrganization()` y `withinOrganization(Organization, Closure)`; quitar el `withoutGlobalScopes()` defensivo de `hasNeighbourOrganization()`.
7. Sus tests.

**No:**

- **No** escribas los middlewares. `ResolveTenant`, `ResolveCatalogTenant`, `ResolvePortalTenant` y `RunsInTenantContext` son `T-006`. `TenantResolver` sí es de aquí; quien lo invoca, no.
- **No** implementes `tenancy:audit` ni las cuatro suites de aislamiento: son `T-007`.
- **No** apliques el trait a ningún modelo todavía: el primer modelo del tenant (`User`, `PaymentAccount`) es `T-008`. Para probar el trait **usa un modelo de prueba definido en el propio test**, como dice el "hecho cuando" del plan.

## 3. Las cuatro decisiones de diseño que hay que acertar

Estas son las que distinguen un aislamiento real de uno aparente.

**1. El trait rellena `organization_id` al crear, y nadie más lo escribe** (`REQ-ORG-002` RB-2). No es una comodidad: es lo que hace **imposible** equivocarse. Si el código de aplicación pudiera asignarlo, tarde o temprano alguien lo asignaría mal, y el error sería silencioso porque el registro quedaría en la organización equivocada sin lanzar nada.

**2. `setOrganization()` es idempotente para el mismo valor y lanza si intentas cambiar a otro** (`SP-6`). No es rigidez: un cambio de tenant a mitad de petición significa que algo se resolvió mal antes, y las consultas ya ejecutadas salieron con el tenant anterior. Fallar es lo correcto; "reajustar" convertiría un error de resolución en una fuga parcial e indetectable.

**3. Un job sin tenant fijado que toca una tabla del tenant debe fallar, no operar sin filtro** (RB-5, SP-3). Es explícito en la spec: **es preferible un job caído a una fuga**. El global scope no puede "no filtrar" cuando no hay tenant; tiene que lanzar `MissingTenantContextException`.

**4. `forget()` es obligatorio en el `finally` de todo job** (SP-7). Una cola compartida procesa jobs de dos organizaciones seguidas; sin limpiar, el segundo hereda el tenant del primero. El mecanismo se usará en `T-006`, pero `forget()` y su semántica se definen aquí.

## 4. La lista blanca, que es más delicada de lo que parece

`GlobalUniqueExceptions` declara **tres** índices únicos que legítimamente no incluyen `organization_id`, y **dos** tablas puente que legítimamente no llevan la columna. Ni una más.

Cada excepción tiene un motivo que conviene que esté escrito en el código, porque dentro de seis meses parecerán descuidos:

- `users.email` — el login ocurre **antes** de saber la organización, así que el correo tiene que ser único en toda la plataforma.
- `catalogs.slug` — la URL `/c/{slug}` es pública y global; dos negocios no pueden reclamar el mismo slug.
- `layaways.code` — es la prueba de acceso al portal del cliente; su unicidad global es lo que la hace servir de credencial.

Que la lista sea **consultable por código y no un comentario** es el punto: `T-007` deriva de ella su comprobación estática, y una lista blanca que hay que mantener en dos sitios se desincroniza.

## 5. Hecho cuando

- [ ] El trait **filtra al leer** y **rellena al crear**, probado con un modelo de prueba definido en el test.
- [ ] Leer una tabla del tenant **sin tenant fijado lanza** `MissingTenantContextException`, no devuelve todo.
- [ ] `setOrganization()` con la misma organización es idempotente; con otra lanza `TenantAlreadySetException`.
- [ ] `forget()` limpia; `runFor()` fija, ejecuta y **restaura el estado anterior incluso si el callback lanza**.
- [ ] `withoutTenantScope()` devuelve las filas de todas las organizaciones.
- [ ] `GlobalUniqueExceptions` expone exactamente las tres excepciones y las dos tablas, consultables por código.
- [ ] `actingAsOrganization()` y `withinOrganization()` funcionan; el `withoutGlobalScopes()` redundante está fuera.
- [ ] Un test de dos organizaciones demuestra que el modelo de prueba de una no es visible desde la otra.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

> Recuerda la convención que `T-004` estableció: un test que ejercite métodos de instancia del caso base se escribe como **clase PHPUnit**, no con la sintaxis de Pest — PHPStan nivel 6 no resuelve el `$this` de las closures.

## 6. Reporta al terminar

1. Cómo implementaste el global scope y **qué hace exactamente cuando no hay tenant** (la decisión de §3.3).
2. Cómo se comporta `runFor()` si el callback lanza una excepción.
3. Qué forma tomó `GlobalUniqueExceptions` y cómo la va a consumir `T-007`.
4. Si algo de la firma de §5 no encajó con Laravel 12 y qué ajustaste.
5. Nada más. No propongas siguientes pasos.
