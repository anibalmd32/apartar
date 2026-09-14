# PASO 4 — Plan de tareas

> **Estado:** ✅ Listo · **Fecha:** 2026-09-10 · **Sin código todavía.**
> Padre: [05-design-tecnico.md](05-design-tecnico.md) · Cierra los **118 requisitos** de los 19 módulos.
> Siguiente: empezar por `T-001`.

## Cómo leer este plan

**88 tareas** agrupadas en **10 hitos** (`H0`…`H9`). Cada tarea declara:

- **ID** `T-NNN`, estable: no se renumera. Una tarea que aparece después de empezar se intercala con sufijo (`T-013b`) en lugar de correr los ochenta y tantos IDs siguientes: los commits, los prompts y las notas ya cerrados los citan por nombre, y renumerar convertiría cada una de esas referencias en una mentira.
- **Requisitos** que cierra, por ID. Es la trazabilidad que sustituye a `indexion` (`REQ-QAX-006` RB-7): el PR de la tarea declara estos IDs.
- **Fase** — `A` (tipos y estructuras) o `B` (lógica), según [02 §8.2](02-spec-consolidada.md). `AB` significa que la tarea es tan pequeña que no vale partirla.
- **Depende de** — tareas que deben estar cerradas antes.
- **Hecho cuando** — el criterio concreto, tomado del *Gate de validación* del requisito.

Convenciones del plan:

- **Toda tarea de Fase B cierra con sus tests en verde.** Una tarea sin tests no está hecha.
- **Toda tarea que crea una consulta del tenant añade su caso a `tests/Feature/Tenancy`** (`REQ-QAX-008` RB-2). No es opcional ni se deja para el final.
- El estimado es en **puntos relativos** (1 = media jornada, 2 = jornada, 3 = dos jornadas, 5 = una semana corta). Sirve para ordenar, no para prometer fechas.
- **Un test que ejercite métodos de instancia del caso base se escribe como clase PHPUnit, no con la sintaxis de Pest.** PHPStan no resuelve el `$this` de las closures de Pest, y nivel 6 lo rechaza. Descubierto en `T-004`; aplica a todo test que use helpers como `CreatesNeighbourOrganization`.
- **Toda ruta nueva declara su superficie.** Desde `T-007`, `CrossTenantAccessTest` recorre el router y exige que cada ruta nuestra lleve un middleware que implemente `DeclaresTenantSurface` (con su `TenantSurface`) o `DeclaresNoTenantSurface` (`OutsideTenancy`, alias `outside-tenancy`). Una ruta sin declarar **hace fallar la suite de tenancy**, no se salta. No hay lista de exentas escrita a mano: eso es lo que `REQ-QAX-008` SP-1 prohíbe.
- **`ResolveTenant` corre antes que `SubstituteBindings`,** fijado con `prependToPriorityList` en `bootstrap/app.php`. Al revés, el binding de un modelo del tenant se resuelve sin organización fijada y la respuesta es `500` en lugar del `404` que exige `REQ-ORG-002` RB-6. Hay un test que fija el orden; si alguien reordena los middlewares, ese test es el que avisa.
- **Toda ruta del backoffice declara si sobrevive a la suspensión.** Desde `T-012`, `EnsureOrganizationIsActive` pregunta por el marcador `during-suspension` (`AllowedDuringSuspension`) y **falla cerrado**: una ruta que no declara nada queda bloqueada con la organización `suspended`. Es la segunda declaración obligatoria por ruta, junto a la de superficie. Una lista central de excepciones se habría quedado vieja en silencio, que es el lado equivocado en el que equivocarse.

| Hito | Tareas | Puntos | Cierra |
|---|---|---|---|
| H0 · Cimientos | T-001…T-016 (+ `T-013b`) | 41 | 16 req. |
| H1 · Aritmética y tasa | T-017…T-023 | 15 | 6 req. |
| H2 · Catálogo | T-024…T-038 | 43 | 19 req. |
| H3 · Apartado | T-039…T-053 | 41 | 23 req. |
| H4 · Cobranza | T-054…T-065 | 36 | 15 req. |
| H5 · Planes | T-066…T-072 | 20 | 8 req. |
| H6 · Cuentas y feed | T-073…T-078 | 18 | 7 req. |
| H7 · Comunicación | T-079…T-083 | 17 | 10 req. |
| H8 · Datos y plataforma | T-084…T-086 | 16 | 11 req. |
| H9 · Producción | T-087 | 5 | 3 req. |
| | **88** | **252** | **118** |

---

## H0 · Cimientos

> **Objetivo demostrable:** el stack corre en local, hay dos organizaciones sembradas, un negocio puede registrarse y entrar a su backoffice vacío, y el gate de aislamiento existe y pasa.
>
> Se hace de **una sola pasada** (sin dividir A/B): sin migraciones ni tenancy no hay nada que tipar.

### Andamiaje

**T-001 · Proyecto Laravel + starter kit react** · `AB` · 2 pts
Crear el proyecto con el starter kit `react` (Inertia 2 + React 19 + TS + Vite + Tailwind 4), añadir daisyUI 5, instalar los paquetes de [05 §3.2 y §3.3](05-design-tecnico.md), commit inicial.
**Hecho cuando:** `npm run build` y `php artisan --version` funcionan; el listado de paquetes coincide con §3.2/§3.3.

**T-002 · Entorno local dockerizado** · `AB` · 3 pts · *Requisitos: `REQ-INF-001`, `REQ-INF-003`, `REQ-INF-004`*
`Dockerfile` multi-stage, `docker-compose.yml` + `override.yml` con los 7 servicios y puertos de [05 §9.1](05-design-tecnico.md), `docker/entrypoint.sh`, `nginx.conf`, `.env.example` completo, `Makefile` con los 6 comandos.
**Hecho cuando:** `make up` levanta todo; `http://localhost:8080` responde; healthchecks declarados; `migrate` corre solo en `app`; test que compara `.env.example` con las variables usadas en `config/`.
**Nota:** `docker-compose.prod.yml` **no** se crea aquí (diferido a T-087).
Sobre `REQ-INF-003`: aquí se crean el servicio `queue` con las **cuatro colas priorizadas**, `RunsInTenantContext` (en T-006) y el `routes/console.php` vacío. Las **catorce entradas del schedule se añaden en la tarea de cada job**, no todas de golpe: la entrada de `layaways:expire` entra con T-047, la de `notifications:dispatch-reminders` con T-083, y así. Cierra al final de H8, cuando las catorce existen.

**Hallazgos de `T-001` que se resuelven aquí:** el `Dockerfile` debe habilitar `pdo_mysql`, `bcmath` (`REQ-FX-004` RB-5 usa `bcmul`), `exif` y `gd` (medialibrary), `zip` (excel), `intl`, `pcntl` (señales de `queue:work`), `opcache` y la extensión `redis`. Se **elimina** `laravel/sail` (no está en [05 §3.2](05-design-tecnico.md) y su stack Docker queda sustituido por el nuestro). Se publica `config/broadcasting.php` y se fijan las variables `REVERB_*` y `VITE_REVERB_*`: `T-001` no lo hizo porque escriben en `.env`, y el `.env` es alcance de esta tarea.

**T-003 · Calidad y CI** · `AB` · 3 pts · *Requisitos: `REQ-QAX-001`, `REQ-QAX-002`, `REQ-QAX-003`, `REQ-QAX-004`, `REQ-QAX-007`*
`pint.json`, `phpstan.neon` (Larastan nivel 6), `eslint.config.js` con `max-lines: 200` como **error**, `tsconfig.json` estricto con `noUncheckedIndexedAccess`, `vitest.config.ts`, `phpunit.xml` contra MySQL, `.github/workflows/ci.yml` con los **tres jobs** (`backend`, `frontend`, `tenancy`), plantilla de PR con el campo "requisitos que cierra".
**Hecho cuando:** `make check` pasa en un proyecto vacío; los tres jobs existen sin `continue-on-error`; `declare(strict_types=1)` verificado; `CreatesNeighbourOrganization` existe y el umbral de cobertura del dominio está configurado (`REQ-QAX-003`); la plantilla de PR recuerda PHPDoc y TSDoc (`REQ-QAX-007`).
Sobre `REQ-QAX-003` y `REQ-QAX-007`: son **normativos y transversales**, no funcionalidad. Aquí se instala su infraestructura (umbrales, helpers, reglas de lint, plantilla de PR) y **se cumplen en cada tarea posterior**: ninguna tarea de Fase B cierra sin tests, y ninguna clase se acepta sin PHPDoc.

**Hallazgos de `T-001` que se resuelven aquí:**
- **`resources/js/components/ui/` llega ocupado por shadcn/Radix**, y [05 §3.1](05-design-tecnico.md) reserva ese directorio para los envoltorios de daisyUI (`REQ-QAX-001` RB-5). Decisión: los primitivos heredados se **mueven** a `resources/js/components/vendor/` con sus imports actualizados, dejando `components/ui/` libre. No se reescriben las páginas de autenticación aquí: siguen funcionando desde `vendor/`, y `T-010`/`T-012` las migran a daisyUI al adaptarlas, tras lo cual `vendor/` se encoge hasta poder borrarse. Mover es barato; reescribir ahora no aporta nada y arriesga el andamiaje que `REQ-BOF-001` reutiliza.
- `phpunit/phpunit` está en `^12.5` porque Pest 4 (la última rama compatible con PHP 8.3) lo exige. `phpunit.xml` se escribe para PHPUnit 12.
- Se **reemplazan** `.github/workflows/lint.yml` y `tests.yml` del starter kit por el único `ci.yml` con los tres jobs.
- Las seis migraciones publicadas por paquetes no llevan `declare(strict_types=1)`. El gate de `REQ-QAX-001` **excluye `database/migrations` de terceros**: no son código nuestro y republicarlas las revertiría.
- **`make` no está instalado en el host de desarrollo (Windows).** Decisión: las recetas se escriben **una sola vez** como scripts de `composer` y de `npm` (`composer lint`/`analyse`/`test`, `npm run lint`/`types`/`test`), que es lo que [91 §REQ-QAX-002](91-calidad-y-gates.md) ya daba por supuesto al citar `composer analyse`. El `Makefile` de §9.4 queda como **alias de una línea** sobre esos scripts, y CI invoca los scripts, no `make`. Así `make` es una comodidad opcional y nadie queda bloqueado por no tenerlo; y sobre todo, no hay dos definiciones de la misma receta divergiendo.
- **Sin baseline de PHPStan.** `REQ-QAX-002` SP-5 solo lo admite para una dependencia sin tipos, con fecha y motivo. El código es todavía pequeño: lo que no tipe hoy queda tipado, no archivado.

### Tenancy

**T-004 · Migraciones bloque 1: plataforma** · `AB` · 2 pts
Las 6 tablas de [05 §4 bloque 1](05-design-tecnico.md): `platform_users`, `plans`, `organizations`, `exchange_rates`, `accounts`, `platform_settings`.
**Hecho cuando:** `migrate:fresh` corre; ningún `FLOAT`/`DOUBLE` en el esquema.

**Ajuste sobre el plan original:** el modelo `Organization` y su factory se crean **aquí**, no en `T-008`. `Organization` **es** el tenant, no está sujeta a él, así que no necesita `BelongsToOrganization` y no hay dependencia circular con `T-005`. Adelantarla desbloquea el cuerpo real de `CreatesNeighbourOrganization` en `T-007` en lugar de dejar el gate de aislamiento a medias hasta `T-008`. `User` y `PaymentAccount` siguen en `T-008` porque sí llevan el trait.

**T-005 · `TenantContext` + `BelongsToOrganization`** · `AB` · 3 pts · *Requisitos: `REQ-ORG-002`*
Los dos primeros colaboradores de [05 §5](05-design-tecnico.md) con su firma exacta, `TenantResolver`, `MissingTenantContextException`, `TenantAlreadySetException`, `GlobalUniqueExceptions` con las tres excepciones y las dos tablas exentas.
**Hecho cuando:** el trait filtra al leer y rellena al crear (test con un modelo de prueba); cambiar de tenant en la misma petición lanza excepción; `forget()` limpia.

**Pendientes que `T-004` dejó para aquí:** completar `CreatesNeighbourOrganization` con `actingAsOrganization()` y `withinOrganization(Organization, Closure)` —hoy el helper crea dos tenants pero no puede situarse en uno— y eliminar el `withoutGlobalScopes()` defensivo de `hasNeighbourOrganization()`, redundante porque `organizations` nunca llevará global scope.

**Reordenación decidida tras `T-005`: `T-008` se adelanta y pasa a ejecutarse antes que `T-006` y `T-007`.** Motivo: las cuatro vías de resolución de `REQ-ORG-002` RB-4 necesitan un modelo del tenant real del que leer el `organization_id` —la del backoffice necesita `User`— y `tenancy:audit` necesita tablas del tenant que auditar. Con solo las seis tablas de plataforma, `T-006` y `T-007` solo podrían escribir andamiaje contra dobles de prueba y quedarían a medias. `T-008` depende únicamente de `T-004` y `T-005`, ambas cerradas, así que el adelanto no arrastra nada. Orden efectivo de H0: `T-001` … `T-005` → **`T-008`** → `T-006` → `T-007` → `T-009` … `T-016`. Las suites de fuga derivadas de la tabla de rutas siguen completándose al final de H0, cuando existan las rutas.

**T-006 · Middlewares de resolución de tenant** · `AB` · 2 pts · *Requisitos: `REQ-ORG-002`*
`ResolveTenant` (desde sesión), `ResolveCatalogTenant` (desde slug), `ResolvePortalTenant` (desde ficha), `RunsInTenantContext` para jobs.
**Hecho cuando:** las cuatro vías de `REQ-ORG-002` RB-4 resuelven; un job sin tenant que toca una tabla del tenant **falla**; el tenant se limpia entre jobs.

**Alcance real tras la reordenación:** solo se escriben `ResolveTenant` (sesión) y `RunsInTenantContext`, más la guarda de la superficie de plataforma. `ResolveCatalogTenant` necesita `Catalog` (H2) y `ResolvePortalTenant` necesita `Customer` y `PortalContext` (H3): son adaptadores finos y se escriben **con su hito**, no antes, para no dejar código muerto imposible de enrutar. Las cuatro vías de RB-4 ya están cubiertas lógicamente por `TenantResolver` y el enum `TenantSurface` de `T-005`; lo que falta es el cableado a rutas que aún no existen. `REQ-ORG-002` **no se marca cerrado** hasta que los dos middlewares restantes estén enrutados.

**T-007 · `tenancy:audit` + suite de aislamiento** · `AB` · 3 pts · *Requisitos: `REQ-QAX-008`*
Comando con las 4 comprobaciones estáticas, `CreatesNeighbourOrganization`, y los 4 archivos de `tests/Feature/Tenancy` **con la parametrización derivada de la tabla de rutas** (aunque de momento haya pocas rutas).
**Hecho cuando:** el comando detecta las 4 clases de violación con casos inyectados; la lista blanca tiene exactamente las 3 excepciones + 2 tablas; `make tenancy` pasa.
**Por qué aquí y no al final:** si la parametrización se escribe cuando ya hay 80 endpoints, ninguno estará cubierto retroactivamente por costumbre. Escrita ahora, cada endpoint nuevo entra solo.

**Hallazgos de `T-008` que este gate debe recoger:**
- **El test de arquitectura de `REQ-ORG-002` SP-4 tiene que buscar las dos grafías.** `TenantAwareUserProvider` escribe `withoutGlobalScope(TenantScope::class)` y no `withoutTenantScope()`, porque es genérico sobre el modelo del guard y PHPStan nivel 6 rechaza el scope local sobre un `Builder<Model>`. Buscar solo la grafía corta deja ese archivo fuera del radar.
- **`app/Auth/TenantAwareUserProvider` es una ruta permitida más**, junto a `app/Queries/Platform`, `app/Actions/Platform` y los comandos de mantenimiento. Su motivo: el login ocurre antes de conocer la organización y `users.email` es único global precisamente para que una búsqueda sin filtro no pueda devolver el usuario de otro negocio.
- **Comprobación nueva, barata y valiosa:** todo modelo con `BelongsToOrganization` debe tener una factory con `CreatesWithinTenant`. La guarda de creación de `T-005` obliga a ello y hoy depende de que cada tarea se acuerde.

**Decisiones tomadas al cerrar `T-007`:**
- **Toda ruta declara su superficie, incluidas las que no tienen ninguna.** La suite recorre el router y una ruta sin declarar **hace fallar el gate**, así que marketing y acceso necesitaban una forma de decir "aquí no hay tenant": es `DeclaresNoTenantSurface` y su middleware `OutsideTenancy` (alias `outside-tenancy`). La alternativa era una lista de rutas exentas escrita a mano en la suite, que es justo lo que `REQ-QAX-008` SP-1 prohíbe. `TenantSurface` **no cambia**: sigue siendo las cinco superficies de `REQ-ORG-002` RB-4.
- **`ResolveTenant` entra en la lista de prioridad de middlewares, antes de `SubstituteBindings`.** Sin eso, un middleware de ruta queda detrás del grupo `web` y el *binding* de un modelo del tenant se resolvería sin organización fijada: el global scope lanzaría y la respuesta sería un `500` en lugar del `404` que exige RB-6 —y un `500` confirma que el recurso ajeno existe igual que un `403`—.
- **La auditoría es estática de verdad: lee archivos, no el esquema migrado.** Es lo que permite inyectarle violaciones sobre un árbol de mentira (`--root`) sin un `CREATE TABLE` a mitad de test, que en MySQL provoca un *commit* implícito y se lleva por delante el aislamiento de `RefreshDatabase`. El esquema real lo siguen comprobando `TenantSchemaTest` y `PlatformSchemaTest`.
- **La presunción de RB-4.4 es la contraria a la cómoda:** toda tabla creada es del tenant salvo que esté en la lista de tablas de plataforma y de framework del comando, con su motivo escrito. Una lista de tablas *del tenant* se olvidaría de actualizar el día que se añade una nueva, que es el día en que hace falta.
- **Las cinco migraciones publicadas por paquetes nombran su tabla con una expresión** (`Schema::connection(config(…))->create(config(…))`) y quedan fuera del alcance estático. El comando **lo imprime** en cada ejecución en lugar de callárselo.
- **`process-timeout: 0` en `composer.json`.** La suite completa pasó de los 300 s que Composer da por defecto a un script, y el síntoma no es un test rojo sino el proceso muerto a mitad con un mensaje de uso de `run-script`. El límite real de una ejecución lo pone `timeout-minutes` de cada job de CI, que es donde tiene que estar.

### Organización y equipo

**T-008 · Migraciones bloque 3 + modelos** · `AB` · 2 pts
`users` (sustituyendo la de Laravel), `organization_settings`, `payment_accounts`; modelos `Organization`, `User`, `PaymentAccount` con el trait.
**Hecho cuando:** `UNIQUE(email)` global; `UNIQUE(organization_id, key)` en ajustes.

**T-009 · Roles y policies** · `AB` · 2 pts · *Requisitos: `REQ-ORG-003`*
`OrganizationRole` con sus 3 métodos, `OrganizationStatus`, `EnsureIsOwner`, `OrganizationPolicy`, `UserPolicy`, `LastOwnerException`.
**Hecho cuando:** matriz de 2 roles × 6 capacidades; el rol se lee de BD y no de la sesión.

**Decisiones tomadas al cerrar `T-009`:**
- **`OrganizationCapability`**, enum nuevo con las doce áreas de `REQ-ORG-003` RB-1 —las seis de gobierno y las seis de operación diaria— y su `requiresOwner()`. `canManageBilling()`, `canManageTeam()` y `canManageSettings()` se responden desde él en vez de tener cada uno su propia condición, para que la frontera esté declarada una vez. El `match` es exhaustivo y sin `default`: un área nueva sin decidir de qué lado cae lanza, en lugar de caer por descuido en el lado permisivo.
- **`users.role` se castea al enum.** Era `string`, y dejarlo así invitaba a comparar contra literales repartidos por la aplicación. Cambia dos aserciones de `TenantFactoriesTest` (`T-008`), que ahora esperan el enum.
- **`SubscriptionPolicy` se registra como *gates* con nombre** (`subscription.view`, `billing.view-invoices`, …) en `AppServiceProvider`, porque el descubrimiento automático necesita `App\Models\Subscription` y ese modelo llega con `T-011`. Sus métodos reciben solo al usuario, así que la regla —solo el `owner`— queda vigente y probada desde hoy. Lo que depende del **estado** de la suscripción (`REQ-ORG-008` RB-3, `REQ-SUB-008`) no es rol y queda para `T-011`.
- **`LastOwnerGuard`** aloja el mecanismo de RB-2 y lo comparten sus dos usos de `T-013`: degradar (`REQ-ORG-004`) y desactivar (`REQ-ORG-005`). Bloquea con `SELECT … FOR UPDATE` las filas de los `owner` activos y ejecuta la escritura **dentro** de esa transacción; comprobar en una transacción y escribir en otra deja exactamente el hueco que el bloqueo cierra.
- **La concurrencia se prueba con dos conexiones y el orden forzado a mano** (`LastOwnerConcurrencyTest`), no con hilos: la conexión rival bloquea **solo su propia fila** para que lo único que pueda esperar sea el conteo. Bloqueando todas las filas, una guarda sin `FOR UPDATE` también acabaría esperando —al escribir— y el test pasaría midiendo el bloqueo equivocado. Ese archivo no usa `RefreshDatabase` —un bloqueo entre conexiones solo es observable sobre filas confirmadas— y barre lo que siembra en `setUp` y en `tearDown`.
- **`OrganizationStatus` no se tocó.** Su docblock atribuye a `T-009` los métodos `allowsTeamAccess()` y `allowsCustomerAccess()`, pero son la matriz de `REQ-ORG-008` —suspensión y cierre—, no la de `REQ-ORG-003`, y llegan con la tarea que cierre ese requisito.
- **`REQ-ORG-003` no quedó cerrado aquí, aunque su gate sí.** Falta la vía por la que un dueño cambia un rol en el producto: RB-3 la enuncia y SP-2/SP-4 la suponen. Se detectó al cerrar `T-013` y la recoge **`T-013b`**.

**T-010 · Registro de organización** · `AB` · 3 pts · *Requisitos: `REQ-ORG-001`*
`RegisterOrganizationAction` (4 creaciones en una transacción), `RegisterOrganizationRequest`, `RegisterController`, página `marketing/Register.tsx`, `ReservedSlugs`, `SlugTakenException`.
**Hecho cuando:** crea org + owner + suscripción Free + ajustes en una transacción; rollback total ante fallo; slug duplicado sugiere variante; slug reservado rechazado; email de equipo duplicado rechazado; rate limit al 6.º registro.
**Depende de:** T-011 (necesita el plan Free existiendo).

**Reparto con `T-014` decidido:** `T-010` crea el enum `SettingKey` con sus claves y valores por defecto, porque `RegisterOrganizationAction` tiene que sembrar los ajustes en la misma transacción y no puede hacerlo contra un array escrito a mano. `T-014` añade lo demás: la clase `Settings` con su caché por organización, las reglas de validación por clave, `UpdateSettingsAction` y la UI.

**Primera superficie visual del proyecto.** `marketing/Register.tsx` es la primera página propia que se escribe, así que fija el patrón: daisyUI envuelto en `resources/js/components/ui/` ([05 §3.1](05-design-tecnico.md)), nunca los primitivos de `components/vendor/`, que son el shadcn heredado en retirada desde `T-003`.

**Decisiones tomadas al cerrar `T-010`:**
- **El `phone` del contrato de `REQ-ORG-001` no tiene columna.** Ni `organizations` ni `users` la declaran en [05 §4], y el teléfono no aparece en ningún otro punto del módulo. La propiedad se quedó en `RegisterOrganizationData` sin destino y **el formulario no lo pide**: recoger un dato que se pierde sin que nadie lo note es peor que no recogerlo. Resuelto en la decisión siguiente.
- **`storefront_enabled` resuelto: manda la columna.** Ver la nota bajo [02 §9.1](02-spec-consolidada.md). `T-014` retira la clave del enum `SettingKey`, borra las filas ya sembradas por `T-010` y deja el enum en **diez** claves.
- **`organizations.phone` decidido: se añade, nullable, y se captura en `T-014`, no en el registro.** `RegisterOrganizationData` declaraba un `phone` que no tenía dónde guardarse, y `T-010` dejó la propiedad huérfana con buen criterio. La columna se añade porque en este mercado el teléfono es el canal real para hablar con un negocio —una suscripción vencida no se cobra por correo— y una columna nullable hoy es más barata que añadirla con quinientas organizaciones dentro. No entra en el formulario de registro: `REQ-ORG-001` RB-8 quiere un alta sin fricción, y el teléfono pertenece al perfil del negocio. `T-014` añade la migración, el campo en `UpdateOrganizationAction` y retira la propiedad huérfana de `RegisterOrganizationData`.
- **`ReservedSlugs` añade `up` y `storage` a la lista cerrada de [05 §7.2]**, en un grupo aparte y con su motivo. No son rutas nuestras: son las que Laravel registra por su cuenta —el *health check* y el disco público— y tienen el mismo efecto que las doce de la lista, porque la vitrina `/{organization:slug}` cede ante toda ruta literal. Un negocio llamado «Up» vería su web mostrando una respuesta de salud, sin un solo error en ningún log.
- **`OrganizationSetting` serializa su valor con un `Attribute` y no con el cast `json`.** El cast de Laravel no serializa los nulos —los guarda como `NULL` de SQL— y la columna es `JSON NOT NULL`, así que `exchange_rate_manual` no se podía sembrar. Se escribe el literal JSON `null`, que es lo que significa: el ajuste existe y su valor es nulo, no que falte la fila.
- **La unicidad del slug no se valida en el `FormRequest`.** Entre un `unique:` y el `INSERT` cabe otro registro, así que la comprobación vive en la acción con el índice detrás, y el mensaje sale con la variante libre que pide SP-1 —que es lo único accionable que se le puede decir a quien choca—. El slug **reservado** sí se valida en el request cuando se escribió a mano, porque ahí el remedio es corregir el campo.
- **El límite de registros es un `RateLimiter` con nombre** (`registro`) y no un `throttle:5,60` suelto: el número vive junto a su motivo (RB-8) y un test lo puede citar.
- **La página se llama `Register.tsx`, con mayúscula**, como la nombra la superficie esperada de `REQ-ORG-001`. Las páginas heredadas del starter kit están en minúscula; las nuestras siguen el árbol de [05 §8].

**T-011 · Planes y suscripción Free** · `AB` · 2 pts · *Requisitos: `REQ-SUB-001`, `REQ-SUB-002`*
`Plan`, `PlanFeature`, `UsageMetric` con sus mapas, `PlanSeeder` con la matriz exacta de la §5 de [03](03-pivote-saas-impacto.md), `Subscription`, `SubscriptionStatus`, `StartFreeSubscriptionAction`, migraciones bloque 2 (parcial: `subscriptions`, `usage_counters`).
**Hecho cuando:** el seeder crea los 3 planes con los límites exactos; `NULL` = ilimitado; una organización tiene exactamente 1 suscripción; sin plan Free el registro falla.

**Decisiones tomadas al cerrar `T-011`:**
- **`UsageMetric` tiene seis casos y `plans` siete columnas de límite, y la asimetría es de la spec.** La que sobra es `max_products_per_catalog`: un contador vive en `usage_counters` con `UNIQUE(organization_id, metric)` —un valor por organización— y «productos en este catálogo» es un valor por catálogo. El contrato del módulo en [50](50-planes-y-suscripciones.md) declara seis casos, `REQ-SUB-003` RB-2 define seis métricas y `REQ-SUB-004` RB-3 nombra esa fila **por su columna** y no por una métrica. `Plan::maxProductsPerCatalog()` la expone aparte.
- **El sello «Hecho con Apartar» no es una función de plan.** La matriz de [03 §5] lo lista como fila propia, pero `REQ-CAT-022` RB-5 dice que se oculta **con `branding`**: es su complemento exacto. Guardarlo aparte permitiría sembrar un plan con marca propia y sello a la vez, que no es un estado admitido. Se deriva en `Plan::showsPlatformBadge()`.
- **`PlanAllowance`**, enum nuevo para las dos cifras de la matriz que no son techos de consumo: historial de chat (`REQ-CHT-002` RB-8) y retención de telemetría (`REQ-TLM-005` RB-6). Viven en el mismo `features` JSON que las funciones —son lo mismo para el esquema: algo que el plan declara y que no se compara contra ningún contador—, y el enum evita leerlas con una cadena escrita a mano. `null` sigue significando ilimitado.
- **Los tres planes se siembran a `price_cents = 0`.** La matriz aprobada fija límites y funciones pero **no fija precios**: no hay ninguna cifra en [03 §5] ni en `REQ-SUB-001` RB-3. Ponerla cuando exista es un `UPDATE` (RB-1) y no altera facturas emitidas (RB-7).
- **`SubscriptionStatus::allowsTeamAccess()` es `true` en `suspended`.** Es el único caso en que los dos métodos difieren, y es lo que les da sentido por separado: `REQ-ORG-008` RB-3 conserva ahí la lectura de la cobranza y la aprobación de comprobantes, mientras `REQ-SUB-004` RB-8 deja `allowsCreation()` en `false` para todo.
- **`SubscriptionPolicy` pasa a descubrirse por convención** y sus métodos reciben la fila, lo que añade la comprobación de pertenencia que antes no cabía. Desaparecen los cuatro `Gate::define` de `AppServiceProvider`; el único test de `T-009` que los usaba —`RoleMatrixTest`— autoriza ahora sobre la suscripción de la organización.
- **Las dos migraciones del bloque 2 corren después de las del bloque 3**, por número de fichero. Dependen solo de `organizations` y `plans` (bloque 1), así que el orden de [05 §4] se respeta donde importa: en las dependencias.
- **`DatabaseSeeder` llama a `PlanSeeder`.** Una base migrada y sin planes es una base en la que el registro falla (`REQ-SUB-002` SP-1). El escenario de desarrollo con datos de mentira sigue siendo `DevSeeder`, de `T-016`.

**T-012 · Login del backoffice** · `AB` · 3 pts · *Requisitos: `REQ-BOF-001`*
`LoginController` (sin selector de organización), recuperación de contraseña, `EnsureAdminIsActive`, `EnsureOrganizationIsActive`, `EnsurePasswordIsChanged`, `BackofficeLayout`, dashboard vacío.
**Hecho cuando:** el tenant se fija desde el usuario y las consultas quedan filtradas (test con 2 orgs); mensaje genérico en credenciales inválidas; `429` al 6.º intento; desactivado pierde la sesión; `suspended` restringe y `closed` rechaza.

**Decisiones tomadas al cerrar `T-012`:**
- **El modo restringido de `suspended` se declara ruta a ruta**, con el middleware marcador `AllowedDuringSuspension` (alias `during-suspension`), y `EnsureOrganizationIsActive` lo lee. Falla cerrado, igual que las superficies de tenancy de `T-007`: lo que no declara nada queda bloqueado. Una lista central de rutas permitidas se quedaría vieja en silencio, que es el lado equivocado en el que equivocarse. Hoy lo declaran el panel, el cierre de sesión y el cambio obligatorio; la cobranza y los comprobantes lo declararán al llegar (H4 y H5).
- **Los nombres de ruta `login`, `logout`, `password.*` y `dashboard` se conservan** aunque las URI se muevan bajo `/backoffice`. Los usan el redirector de invitados de Laravel (`redirectGuestsTo(route('login'))`), `ResolveTenant` al abandonar una sesión y las páginas heredadas; renombrarlos habría sido churn sin ganancia.
- **Se retira el andamiaje que esta tarea sustituye** —`AuthenticatedSessionController`, `NewPasswordController`, `PasswordResetLinkController`, `ConfirmablePasswordController`, `Auth\LoginRequest`, sus cuatro páginas y sus tres tests— y **se conserva la verificación de correo**: `REQ-ORG-001` RB-5 la exige antes de publicar un catálogo, y borrarla hoy significaría reescribirla igual dentro de dos hitos. `confirm-password` sí se borró: era el andamiaje del middleware `password.confirm`, que este producto no usa en ninguna operación.
- **`AuthenticatedTeamMember` viaja como prop compartida `teamMember`, no en lugar de `auth.user`.** `auth.user` es la fila cruda que consumen las pantallas heredadas de perfil; reescribirla habría obligado a tocarlas sin necesidad.
- **De `OrganizationBrand` solo se comparte lo que hay quien pinte**: nombre, slug y estado. El logo, el color y el sello son de `REQ-ORG-006` y llegan con `T-014`; `brandingEnabled` además exige leer el plan en cada petición, y hacerlo para nadie sería pagar por adelantado.
- **`ExpiredInertiaSession`** es una clase invocable y no un *closure* en `bootstrap/app.php`: ese archivo no tiene `namespace` y no puede importar `Throwable` sin que PHP avise en cada petición.
- **El enlace «Regularizar el pago» del aviso de suspensión apunta a `/backoffice/suscripcion`, que todavía no existe** (`REQ-SUB-005`, H5). Se deja apuntando a su URL definitiva para que el aviso esté completo el día que la pantalla llegue.

**T-013 · Equipo: invitación y desactivación** · `AB` · 3 pts · *Requisitos: `REQ-ORG-004`, `REQ-ORG-005`*
`InviteTeamMemberAction`, `DisableTeamMemberAction`, `EnableTeamMemberAction`, `AcceptInvitationController`, `TeamInvitationNotification`, páginas de equipo.
**Hecho cuando:** solo `owner` invita; invitación pendiente cuenta para el límite y revocarla lo libera; token 48 h de un solo uso; último `owner` protegido con concurrencia.

**Adelanto de alcance decidido: `LimitEnforcer` nace aquí, no en `T-067`.** `REQ-ORG-004` RB-2 exige que una invitación pendiente consuma cupo del límite `users`, y `REQ-ORG-005` RB-5 que desactivar lo libere: eso es aplicación de límites, y [05 §5](05-design-tecnico.md) dice que **ninguna Action comprueba límites por su cuenta**. Contar a mano aquí y sustituirlo en H5 dejaría esa invariante rota durante cincuenta tareas, con `T-015` (`max_payment_accounts`) repitiendo el mismo apaño.

Se construye con **la firma definitiva de §5** y soportando las métricas cuyas tablas ya existen (`users`, `payment_accounts`); el contador se mueve **en la misma transacción** que la creación, como exigirá `REQ-SUB-003`. `T-066` añade las cuatro métricas restantes y el job de reconciliación; `T-067` inserta las llamadas en las doce acciones de `REQ-SUB-004` RB-3 y aporta la UI (`LimitBanner`, `UpgradePrompt`, `usePlanLimits`). Ninguno de los dos reescribe lo que se haga aquí.

**Decisiones tomadas al cerrar `T-013`:**
- **El punto de serialización de `assertCanCreate()` es la fila de `usage_counters`, no las filas que se cuentan.** Es la misma para las dos peticiones rivales, cualesquiera que sean las filas que cada una vaya a crear, y no depende de la forma de ningún índice. De ahí que llamar sin transacción abierta **lance**: un `lockForUpdate()` en *autocommit* no bloquea nada más allá de su propia consulta, y comprobar en una transacción y escribir en otra deja justo el hueco que el bloqueo cierra.
- **El enforcer vuelve a medir la métrica en vez de fiarse del contador**, tanto al decidir como al pintar, y deja escrito lo que encuentra. Hoy el contador solo lo mueve esta tarea —el recálculo diario es `T-066` y las otras once llamadas son `T-067`—, así que arrastra deriva que nadie cuadra: el propio registro de una organización deja los seis en cero con el `owner` ya creado. Hacer cumplir un techo contra una cifra que nadie mantiene es hacer cumplir una ficción, y pintarla junto a la lista que la contradice es peor. Cuando `T-066` mantenga los contadores, el valor materializado pasa a ser el camino único y la medida se muda a `UsageCalculator`; el `match` de `measure()` es literalmente lo que `T-066` amplía.
- **`LimitEnforcer` no recibe la organización: la toma del global scope.** `Subscription::query()`, `User::query()` y `UsageCounter::query()` ya filtran, así que llamarlo sin tenant fijado lanza `MissingTenantContextException` en vez de aplicar el plan de otro.
- **`assertCanCreate` con techo `NULL` ni siquiera coge el contador.** Sin techo contra el que comparar, serializar dos creaciones sería pagar un bloqueo por una pregunta ya respondida. Hay un test que lo fija por el contador intacto.
- **Tres excepciones nuevas y tres códigos distintos, que es lo que los diferencia:** `PlanLimitReachedException` es `422` —falta sitio, y el cuerpo lleva `metric`, `used`, `limit`, `minimumPlan` y el enlace a Suscripción—; `PlanFeatureUnavailableException` es `403` y nunca `404` (RB-7: hay que **ver** lo que uno se pierde); `CreationSuspendedException` es `403` y existe aparte para no mandar a comparar planes a quien lo que tiene que hacer es pagar.
- **El plan mínimo se resuelve recorriendo `Plan::public()` por `position` y comparando techos**, nunca códigos (`REQ-SUB-001` RB-1). Un plan fuera del escaparate no se ofrece como salida, y hay test.
- **El token de invitación vive en `users`, en dos columnas nuevas, y no en una tabla `team_invitations`.** RB-3 crea al invitado como fila de `users` desde el primer instante y RB-4 deja vivo un solo enlace por persona, así que la relación es uno a uno y sin historia: una tabla aparte sería una partición vertical con un `JOIN` obligatorio y ninguna pregunta que respondiera mejor. [05 §4] bloque 12 no las declara; se añaden porque el enlace de 48 h de RB-4 no se sostiene sin guardar cuál vale y hasta cuándo.
- **El token lleva delante el identificador de su organización** (`7.a8f3…`), y eso es lo que permite resolver el tenant **antes** de consultar `users`, que es una tabla del tenant y cuyo global scope lanza sin organización fijada. La alternativa era un `UNIQUE(invitation_token_hash)` global —la misma forma que `layaways.code`— y habría exigido una **cuarta** entrada en `GlobalUniqueExceptions`, que es una lista cerrada. Va el identificador y no el `slug` porque el `slug` es editable (`REQ-ORG-006`) y un renombrado no puede romper las invitaciones en vuelo. El índice queda en `(organization_id, invitation_token_hash)`.
- **`/invitacion/{token}` se declara `outside-tenancy`, no abre una sexta superficie en `REQ-ORG-002` RB-4.** Quien llega no tiene sesión, ni catálogo, ni ficha: no pertenece a ninguna superficie. La organización sale del token y se fija **dentro** del controlador con `runFor()`, exactamente como `RegisterOrganizationAction` hace con la organización recién creada.
- **`GET /invitacion/{token}` no consume un enlace vivo; solo marca el caducado.** Si consumiera, el primer antivirus de correo que visita los enlaces para comprobarlos quemaría la invitación antes de que la persona la viera. Lo que gasta el enlace es aceptar.
- **La pantalla de enlace vencido no lleva un botón que reenvíe por sí solo.** Sería un endpoint sin autenticar capaz de disparar correos a partir de un token cualquiera, y quien decide si esa persona sigue entrando es el `owner`. Tampoco se muestra el correo del `owner` —sería revelar un dato de la organización a quien todavía no está dentro—: se le dice que se lo pida a quien le invitó.
- **Revocar una invitación pendiente sí borra la fila**, y no contradice a `REQ-ORG-005` RB-1: esa regla protege `reviewed_by`, `registered_by` y la auditoría, y quien no ha entrado nunca no ha aprobado nada. Con baja lógica, en cambio, el correo quedaría ocupado **para siempre en toda la plataforma** (`REQ-ORG-001` RB-3) por haberse equivocado de dirección al invitar.
- **Aceptar marca el correo como verificado.** Abrir un enlace que solo llegó a ese buzón es la prueba de control de la dirección; pedir después un segundo correo de verificación sería exigir dos veces lo mismo.
- **Invitar, reenviar y revocar viven en `InviteTeamMemberAction`**, que es el único archivo que `REQ-ORG-004` declara en su superficie esperada. Comparten la emisión del enlace y el ajuste del cupo; repartidos en tres acciones, la segunda que alguien tocara dejaría de ajustar el contador.
- **`LastOwnerException` no se alcanza desactivando por HTTP en un solo hilo**, y merece la pena saberlo: para desactivar hay que ser `owner`, y si el sujeto es el último `owner` activo, quien lo intenta o es él mismo —y salta antes RB-4— o no es `owner` —y salta la policy—. Quedan la vía concurrente (SP-3, que prueba `LastOwnerConcurrencyTest`) y la de un `owner` ya desactivado, que conserva su rol; el test de la acción usa esa segunda.
- **`LimitEnforcerConcurrencyTest` aloja también el caso de «sin transacción, lanza»**, porque `RefreshDatabase` envuelve cada test en una transacción y en los demás archivos el nivel nunca es cero. Sigue la receta de `LastOwnerConcurrencyTest`: dos conexiones, orden forzado a mano, `innodb_lock_wait_timeout` a un segundo y la rival bloqueando **solo** el contador, para que lo único que pueda esperar sea la comprobación del cupo.
- **Ninguna ruta de equipo declara `during-suspension`.** Lo que sobrevive a una suspensión es cobrar y aprobar comprobantes (`REQ-ORG-008` RB-3); ampliar el equipo mientras se le debe dinero a la plataforma es lo contrario. La omisión es la declaración, por el fallo cerrado de `T-012`.
- **`UsageState.percentage` pasa a `number | null` en el espejo de TypeScript** (`REQ-QAX-006` RB-7). Decía `number` sin nadie que lo produjera, y `lib/plan.ts` ya devolvía la unión por el mismo motivo: sin techo no hay porcentaje, y un `0` o un `100` mienten en direcciones opuestas.
- **`UsageMetric::label()`**, método nuevo, porque el mensaje de `PlanLimitReachedException` lo lee el dueño de un negocio y «tu plan permite 1 users» es el nombre de una columna, no un mensaje. Concuerda con la cantidad, que es justo lo que hace falta en Free, cuyo techo es uno.
- **Dos átomos nuevos en `components/ui/`:** `select-field.tsx` —con las opciones como datos tipados y no como JSX, para que nadie cuele un `<option>` con un valor que el servidor no admite— y `badge.tsx`, donde el color nunca es lo único que distingue un estado. El listado genérico con filtros y orden **no** se anticipó: tiene su sitio en `features/data-table/` ([05 §8]).
- **El cambio de rol (`REQ-ORG-003` RB-3) no entra.** El alcance de este prompt lo deja fuera y su «hecho cuando» tampoco lo pide. El uso «degradar» de `LastOwnerGuard` ya lo ejercita `LastOwnerConcurrencyTest` desde `T-009`, así que el mecanismo está probado; lo que falta es la acción, su ruta y el control en la pantalla de Equipo. **Ninguna tarea posterior lo recoge**: `T-013` era su hueco natural. **Resuelto abriendo `T-013b`, justo debajo.**

**T-013b · Cambio de rol del equipo** · `AB` · 1 pt · *Requisitos: `REQ-ORG-003` (RB-3, SP-2, SP-4)*
`ChangeTeamMemberRoleAction`, la ruta `POST equipo/{member}/rol` y el control en la pantalla de Equipo.
**Hecho cuando:** un `owner` promueve a un `member` y se degrada a sí mismo; el **último `owner` activo no puede degradarse** (`422`, vía `LastOwnerGuard`); un `member` promovido con la sesión abierta tiene el permiso **en su siguiente petición** (SP-4, por HTTP y no tocando la BD a mano); la ruta declara superficie y **no** sobrevive a la suspensión; recetas en verde.

**Por qué existe esta tarea.** `T-009` cerró `REQ-ORG-003` contra su gate —la matriz de dos roles por seis capacidades, el `403` del `member`, el bloqueo del último `owner` y el rol leído de BD— y el gate estaba bien pasado. Lo que el gate no pedía es la **vía por la que un dueño cambia un rol en el producto**: RB-3 la enuncia, SP-2 y SP-4 la suponen, y no existe. Se detectó al cerrar `T-013`, que construyó la pantalla donde ese control tiene que vivir.

Se abre como tarea propia y no se anexa a `T-014` por dos razones. La primera, que `T-014` es ajustes, identidad y vitrina: meterle el control de roles la obligaría a tocar `TeamController` y la pantalla de Equipo, que no son suyos, y una tarea que toca dos superficies se revisa peor. La segunda, y es la que pesa: **un requisito que se dio por cerrado y no lo estaba merece su propia línea en el plan**, con su commit y su número, en lugar de colarse como apéndice de otra cosa. Si esto vuelve a pasar, quiero poder encontrarlo.

Es barata porque el mecanismo ya está: `LastOwnerGuard` con su bloqueo transaccional y su test de concurrencia (`T-009`), `OrganizationCapability` con la frontera declarada, y la pantalla de Equipo con su listado (`T-013`). Lo que falta es la acción, la ruta y el `select` de rol.

**T-014 · Ajustes, identidad y vitrina** · `AB` · 3 pts · *Requisitos: `REQ-ORG-006`*
`SettingKey` reducido a **10** claves (retirando `storefront_enabled`, ver arriba) con sus reglas, migración de `organizations.phone`, `Settings` con caché **por organización**, `UpdateSettingsAction`, `UpdateOrganizationAction`, `OrganizationStorefrontController`, página de ajustes.
**Hecho cuando:** cada clave valida su rango; **caché aislada entre organizaciones** (test de fuga); clave desconocida ignorada; Redis caído lee de BD; slug bloqueado con catálogos publicados.

**T-015 · Cuentas de cobro** · `AB` · 2 pts · *Requisitos: `REQ-ORG-007`*
`PaymentAccount`, `PaymentMethod` (enum completo con sus 3 métodos), `ValidPaymentIdentifier`, `UpsertPaymentAccountAction`, CRUD.
**Hecho cuando:** un caso por método con identificador válido e inválido; borrado con comprobantes se degrada a desactivación.

**T-016 · Auditoría** · `AB` · 2 pts · *Requisitos: `REQ-BOF-005`*
`activity_log` con `organization_id`, `AuditEvent` con la lista cerrada, `LogsSensitiveActivity`, `AuditLogController`.
**Hecho cuando:** `properties` con antes y después; job sin actor deja `causer` nulo; fallo de auditoría no revierte la operación; una organización no ve la de otra (fuga); `member` recibe `403`.

**Cierre de H0:** `make check` y `make tenancy` verdes · un negocio se registra, entra y configura ajustes y cuentas de cobro · 16 requisitos cerrados.

---

## H1 · Aritmética y tasa

> **Objetivo demostrable:** `Money` y el cronograma pasan sus tests de propiedad con 500 casos, y la tasa se ingesta de DolarApi.

**T-017 · `Money` y `Currency`** · `A` · 2 pts · *Requisitos: `REQ-FX-001`*
`Money` con sus 8 métodos, `Currency`, `MoneyFormatter`, `MoneyCast`, `CurrencyMismatchException`, `InvalidRateException`; tipos TS `CentsUsd`, `MoneyView`, `resources/js/lib/money.ts`.

**T-018 · Lógica y tests de `Money`** · `B` · 3 pts · *Requisitos: `REQ-FX-001`*
**Hecho cuando:** `sum(allocateEvenly(n)) === cents` con **500 casos aleatorios**; residuo en la última parte; mezcla de monedas lanza; formateo `$1,234.56` y `Bs. 1.234,56` exactos; cast con `null` devuelve `null`; conversión con tasa ≤ 0 lanza.
**Por qué es su propia tarea:** es la base de todo el dinero del sistema. Si esto tiene un bug, lo tienen los 118 requisitos.

**T-019 · Contratos de tasa** · `A` · 1 pt · *Requisitos: `REQ-FX-002`, `REQ-FX-003`*
`RateSource`, `RateSnapshot` (con `publishedAt` **y** `fetchedAt`), `ExchangeRateProvider`, `ExchangeRate`, `ExchangeRateUnavailableException`, `MissingExchangeRateException`, `RateView`.

**T-020 · `DolarApiProvider` + ingesta** · `B` · 3 pts · *Requisitos: `REQ-FX-002`*
Provider según [05 §6](05-design-tecnico.md), `ManualRateProvider`, `ExchangeRateService`, `FetchExchangeRate`, `ExchangeServiceProvider`, `ExchangeRateSeeder`, endpoint `/api/tasa`.
**Hecho cuando:** **una sola petición** HTTP trae oficial y paralelo (assert con N organizaciones); prioridad `promedio > venta > compra`; rechaza variación > 30 % y `rate <= 0`; no inserta si nada cambió; fixture de contrato del payload real; `WithoutOverlapping`.

**T-021 · Fuente de tasa por organización** · `B` · 2 pts · *Requisitos: `REQ-FX-003`*
`UpdateExchangeRateSettingsAction`, UI en ajustes con las fuentes disponibles y su antigüedad.
**Hecho cuando:** **dos organizaciones con fuentes distintas proyectan importes distintos** para el mismo producto; `manual` no inserta en `exchange_rates`; `member` recibe `403`.

**T-022 · Proyección bimoneda y toggle** · `B` · 3 pts · *Requisitos: `REQ-FX-004`, `REQ-FX-005`*
`ProjectsMoney`, `useCurrencyDisplay`, `MoneyLabel`, `CurrencyToggle`, `RateStamp`.
**Hecho cuando:** los dos modos con formato exacto; **sin tasa muestra solo USD y deshabilita el toggle**; `localStorage` inaccesible no rompe; cookie corrupta cae al default; la preferencia sobrevive al cambio de organización.

**T-023 · Snapshot inmutable de tasa** · `A` · 1 pt · *Requisitos: `REQ-FX-006`*
Columnas de snapshot en la migración de `payments` (se completa en H4).
**Hecho cuando:** las 6 columnas existen; `rate_applied` es `DECIMAL(18,8)`.

**Cierre de H1:** la aritmética monetaria está probada y la tasa entra sola · 6 requisitos cerrados.

---

## H2 · Catálogo

> **Objetivo demostrable:** un negocio carga productos, arma un catálogo y **el enlace público funciona**: un desconocido lo abre, busca, filtra y ve precios en bimoneda.

**T-024 · Migraciones bloque 5** · `A` · 2 pts
Las 8 tablas de catálogo, con `FULLTEXT` y el `UNIQUE(slug)` global de `catalogs`.

**T-025 · Modelos y enums de catálogo** · `A` · 2 pts · *Requisitos: `REQ-CAT-007`*
`Category`, `Tag`, `Product`, `ProductSpecification`, `Catalog` con el trait, `ProductStatus`, `CatalogVisibility` con sus métodos derivados.

**T-026 · Taxonomía** · `B` · 2 pts · *Requisitos: `REQ-BOF-023`*
CRUD de categorías y etiquetas, `ReassignCategoryProductsAction`, `CategoryInUseException`.
**Hecho cuando:** borrado con productos rechazado; **mismo nombre en dos organizaciones permitido**; creación inline reutiliza; orden persistido.

**T-027 · CRUD de productos** · `B` · 5 pts · *Requisitos: `REQ-BOF-020`*
`ProductData`, `UpsertProductAction`, `CoherentFinancingTerms`, requests, formulario con previsualización del plan en vivo.
**Hecho cuando:** matriz de las **6 reglas de coherencia** con casos límite; **SKU duplicado en otra organización permitido**; slug inmutable tras publicar; más de 2 decimales rechazado; cambio de precio auditado; defaults de la organización aplicados.

**T-028 · Ficha técnica dinámica** · `B` · 3 pts · *Requisitos: `REQ-BOF-021`, `REQ-CAT-004`*
`SyncSpecificationsAction`, `SpecificationBuilder`, `useSpecificationRows`, `SpecificationTable`, `SpecificationGroupsQuery`.
**Hecho cuando:** posiciones contiguas tras reordenar; reemplazo transaccional con rollback; 51 filas rechazadas; **autocompletado de grupos acotado a la organización** (fuga); escapado de HTML verificado.

**T-029 · Imágenes de producto** · `B` · 3 pts · *Requisitos: `REQ-BOF-022`, `REQ-CAT-005`*
medialibrary con las 3 conversiones, `TranscodeProductImage`, `SyncImageOrderAction`, `RealMimeType`, `ImageUploader`, `ProductGallery`.
**Hecho cuando:** 3 conversiones en cola; MIME falso rechazado; borrar la principal promueve la siguiente; **la ruta física incluye el `organization_id`**; publicado sin imágenes rechazado.

**T-030 · Estados de publicación** · `B` · 2 pts · *Requisitos: `REQ-CAT-007`*
`ChangeProductStatusAction`, scopes, `ProductNotAvailableException`.
**Hecho cuando:** matriz de transiciones; `draft`/`archived` dan `404`; **la relación con el catálogo se conserva al archivar**; `published_at` futuro no se lista; publicado en 0 catálogos no es error.

**T-031 · Composición de catálogos** · `B` · 3 pts · *Requisitos: `REQ-CAT-023`*
`SyncCatalogProductsAction`, `CatalogProductSorter`, `useSortableProducts`.
**Hecho cuando:** posiciones contiguas desde 1; **`created_at` del pivote NO cambia al reordenar**; deduplicación; producto de otra organización rechazado (fuga); vaciar un catálogo publicado rechazado.

**T-032 · Publicación de catálogos** · `B` · 3 pts · *Requisitos: `REQ-CAT-022`, `REQ-BOF-024`*
`PublishCatalogAction`, `UnpublishCatalogAction`, `UniqueCatalogSlug`, `SlugLockedException`, `ShareLinkField`, CRUD y listado con badge de visibilidad.
**Hecho cuando:** un test por cada uno de los **6 requisitos de publicación**; slug único global con desambiguación entre organizaciones; slug inmutable tras publicar; despublicar da `404` y expirado `410`; sello de plataforma presente en Free.

**T-033 · Vista pública del catálogo** · `B` · 5 pts · *Requisitos: `REQ-CAT-021`*
`CatalogShowController`, `CatalogViewResource`, `CatalogFacetsQuery`, página `catalog/CatalogShow.tsx`, `CatalogLayout`, paginación por cursor, OG tags.
**Hecho cuando:** `unlisted` accesible, `draft` → `404`, `expired` → `410`; **el tenant se resuelve del catálogo y todas las consultas quedan filtradas** (fuga con 2 orgs); productos `draft` omitidos; orden curado por defecto; sin `count(*)` en la paginación.

**T-034 · Filtros y búsqueda del catálogo** · `B` · 3 pts · *Requisitos: `REQ-CAT-002`, `REQ-CAT-006`*
`FullTextTerm`, scopes `scopeSearch`/`scopeInCatalog`, `useCatalogFilters`, `SearchInput`.
**Hecho cuando:** las facetas son solo las presentes en el catálogo; filtro OR de etiquetas; escapa operadores booleanos; **un producto de otro catálogo no aparece**; `sort` inválido cae a `curated`; **no existe ruta de categoría fuera del catálogo** (assert de rutas).

**T-035 · Ficha de producto pública** · `B` · 3 pts · *Requisitos: `REQ-CAT-003`*
`ProductShowController`, `ProductDetailResource`, página `catalog/ProductShow.tsx` con galería, ficha técnica, desglose del plan y **cuentas de cobro**.
**Hecho cuando:** la cuota mostrada **coincide con el cronograma real que generaría el apartado**; producto fuera del catálogo `404`; cuentas de cobro activas visibles y las inactivas no; sin ficha técnica no se renderiza la sección.
**Depende de:** T-041 (`ScheduleGenerator`, para que el cálculo mostrado sea el real y no una copia).

**T-036 · Vitrina de la organización** · `B` · 2 pts · *Requisitos: `REQ-ORG-006`*
`OrganizationStorefrontController`, página con los catálogos listados.
**Hecho cuando:** lista solo los propios y solo `Listed`; desactivada por defecto; slug reservado no colisiona.

**T-037 · Marketing y precios** · `B` · 2 pts · *Requisitos: `REQ-SUB-001`*
Landing, `/precios` renderizado **desde la BD**, páginas legales.
**Hecho cuando:** los 3 planes se leen de `plans`; `NULL` se muestra como "ilimitado".

**T-038 · Tablas server-driven** · `B` · 3 pts · *Requisitos: `REQ-BOF-045`*
`useServerTable`, `DataTable` (+ 4 subcomponentes), `ResolvesTableState`, `SortWhitelist`.
**Hecho cuando:** `sort` no permitido cae al default; `perPage` fuera de lista cae a 25; `page` desbordada no da error; recarga parcial pide solo `rows` y `meta`; **URL compartida entre organizaciones muestra los datos de cada una**; ningún componente supera 200 líneas.

**Cierre de H2:** el enlace de un catálogo funciona de punta a punta · 19 requisitos cerrados.

---

## H3 · Apartado

> **Objetivo demostrable:** un cliente **aparta** desde el catálogo sin registrarse y ve su apartado en el portal con documento + código.

**T-039 · Migraciones bloque 4 y 7** · `A` · 2 pts
`customers`, `customer_login_tokens`, `layaways`, `installments`; verificar que `customers` **no tiene `password`**.

**T-040 · Identidad: VOs y validación** · `B` · 3 pts · *Requisitos: `REQ-IDN-007`*
`DocumentNumber`, `DocumentKind`, `PhoneNumber`, sus reglas y `identity-format.ts`.
**Hecho cuando:** **12 variantes de escritura de cédula** → misma forma canónica; RIF con y sin prefijo con el `kind` correcto; **ceros a la izquierda preservados**; 6 variantes de teléfono → E.164; rechazos esperados.

**T-041 · Dominio del cronograma** · `B` · 3 pts · *Requisitos: `REQ-LAY-004`*
`LayawayTerms`, `ScheduledInstallment`, `AmortizationSchedule`, `ScheduleGenerator`, `InvalidScheduleException`.
**Hecho cuando:** invariante `sum(cuotas) === financiado` con **500 combinaciones aleatorias**; residuo en la última cuota; fechas exactas con frecuencia 7/15/30; **dos organizaciones con zonas distintas producen fechas distintas**; financiado 0 no genera cuotas; rangos inválidos rechazados.

**T-042 · Máquina de estados** · `B` · 3 pts · *Requisitos: `REQ-LAY-005`*
`LayawayStatus` con sus 4 métodos, `InstallmentStatus`, `LayawayStateMachine`, eventos de dominio, `IllegalLayawayTransitionException`.
**Hecho cuando:** **matriz 6×6** de transiciones válidas e inválidas; `acceptsPayments()` y `countsTowardPlanLimit()` por estado; concurrencia bloquea; `completed` prevalece sobre `overdue`; fallo de listener no revierte.

**T-043 · Ficha de cliente** · `B` · 2 pts · *Requisitos: `REQ-IDN-001`*
`Customer`, `CustomerContactData`, `ResolveCustomerAction`.
**Hecho cuando:** **el mismo documento en dos organizaciones crea dos fichas aisladas** (fuga); teléfono obligatorio; email opcional y no único; reutilización actualiza con auditoría; concurrencia produce una sola ficha; sin tenant lanza excepción.

**T-044 · Reserva de stock** · `B` · 2 pts · *Requisitos: `REQ-LAY-003`*
`ReserveStockAction`, `ReleaseStockAction`, `InsufficientStockException`.
**Hecho cuando:** **concurrencia real con dos transacciones sobre la última unidad**; stock nunca negativo; liberación idempotente; el bloqueo no alcanza productos de otra organización; orden canónico de bloqueo.

**T-045 · Creación del apartado** · `B` · 5 pts · *Requisitos: `REQ-LAY-001`, `REQ-LAY-002`, `REQ-IDN-005`*
`CreateLayawayAction` con la secuencia de [05 §5.1](05-design-tecnico.md), `LayawayCode`, `StoreLayawayRequest`, formulario con `react-form`, página de gracias con el código visible, email de confirmación, `TermsConsentField`.
**Hecho cuando:** crea cliente + apartado + reserva en una transacción; **el apartado hereda la organización del catálogo**; producto de otro catálogo rechazado; stock insuficiente no persiste nada; **doble POST con la misma `idempotencyKey` devuelve el mismo `code`**; sin email funciona; rollback completo; cambiar el precio del producto no altera el apartado; `code` sin caracteres ambiguos.

**T-046 · Activación y cronograma materializado** · `B` · 2 pts · *Requisitos: `REQ-LAY-004`*
`ActivateLayawayAction`.
**Hecho cuando:** genera N cuotas con `next_due_date` correcto; idempotente por `UNIQUE(layaway_id, number)`; cronograma existente aborta sin duplicar.

**T-047 · Expiración por falta de inicial** · `B` · 2 pts · *Requisitos: `REQ-LAY-006`*
`ExpireLayawaysAction`, `ExpireLayawaysJob`, `LayawayExpiringNotification`.
**Hecho cuando:** expira sin comprobantes; **no expira con comprobante en revisión**; rechazo tardío da 24 h una sola vez; libera stock idempotentemente; **cada apartado usa la ventana de su organización**; el tenant se limpia entre iteraciones.

**T-048 · Detección de mora** · `B` · 2 pts · *Requisitos: `REQ-LAY-007`*
`DetectOverdueAction`, `DetectOverdueLayawaysJob`, scopes.
**Hecho cuando:** gracia respetada al día exacto; pago parcial sigue vencido; ampliar la gracia devuelve cuotas a `pending`; **dos organizaciones con gracias y zonas distintas se evalúan por separado**.

**T-049 · Acceso por documento + código** · `B` · 3 pts · *Requisitos: `REQ-IDN-008`, `REQ-IDN-004`*
`StartScopedSessionAction`, guard `customer`, `EnsurePortalSession`, `AccessController`, `PortalLayout`.
**Hecho cuando:** documento + código abren sesión acotada; **código de otra persona falla**; respuesta indistinguible en los 3 modos de fallo; rate limit al 6.º intento; da acceso a **todos** los apartados de la ficha; funciona con apartados terminales y organización suspendida.

**T-050 · Magic link por email** · `B` · 2 pts · *Requisitos: `REQ-IDN-003`*
`IssueLoginLinkAction`, `ConsumeLoginTokenAction`, `CustomerLoginLinkNotification`.
**Hecho cuando:** token de un solo uso con TTL 30 min; **documento en tres organizaciones genera tres enlaces acotados**; datos no coincidentes no envían y responden igual; ficha sin email no ofrece la vía; el `GET` no consume.

**T-051 · Portal: portada y detalle** · `B` · 5 pts · *Requisitos: `REQ-LAY-020`, `REQ-LAY-021`*
`PortalContext` (parcial, solo modo acotado), `CustomerBalanceQuery`, `DashboardController`, `LayawayShowController`, `InstallmentRowResource`, páginas y `BusinessBlock`, `ScheduleTable`.
**Hecho cuando:** **los apartados se agrupan por negocio y nunca se mezclan**; una consulta agregada por organización; `code` ajeno da `404`; `pending_down_payment` muestra instrucciones y cuentas de cobro en lugar de cronograma; **el motivo de cancelación no se filtra al cliente**; `daysUntilDue` correcto en el límite de medianoche local.

**T-052 · Alertas del portal** · `B` · 2 pts · *Requisitos: `REQ-LAY-023`*
`PortalAlertsQuery`, `PortalAlert`, `PortalAlerts`.
**Hecho cuando:** prioridad respetada; umbral de 3 días coincide con `reminder_offsets_days`; agrupación consolidada con 3 negocios; ventana vencida con comprobante muestra `receipt_pending`; **`claim_available` no revela si existe una ficha**.

**T-053 · Cancelación, entrega y reprogramación** · `B` · 3 pts · *Requisitos: `REQ-LAY-009`, `REQ-LAY-010`, `REQ-LAY-011`, `REQ-LAY-008`*
`CancelLayawayAction`, `MarkDeliveredAction`, `RescheduleInstallmentAction`, diálogos.
**Hecho cuando:** cancela desde los 3 estados válidos y falla en terminales; motivo obligatorio; **rechaza comprobantes pendientes al cancelar**; solo `completed` permite marcar entrega, idempotente; ventana válida de reprogramación entre cuotas contiguas; apartado ajeno da `404`.

**Cierre de H3:** un cliente aparta y consulta su apartado sin registrarse · 23 requisitos cerrados.

---

## H4 · Cobranza — *línea de flotación*

> **Objetivo demostrable:** el cliente **sube un comprobante**, el negocio lo aprueba y el balance cuadra al céntimo. **Al cerrar este hito la plataforma ya hace lo que promete.**

**T-054 · Migraciones de cobranza** · `A` · 2 pts
`receipts`, `payments`, `payment_allocations`; verificar que la unicidad de `receipts` es **por organización**.

**T-055 · Enums y contratos de pago** · `A` · 2 pts · *Requisitos: `REQ-PAY-001`*
`ReceiptStatus`, `RejectionReasonCode`, `AllocationPlan`, `SubmitReceiptData`, `ApproveReceiptData`, `ManualPaymentData`, `Receipt`, `Payment`, `PaymentAllocation`.

**T-056 · `FifoAllocator`** · `B` · 3 pts · *Requisitos: `REQ-PAY-005`*
**Hecho cuando:** cubre en cascada la cuota más antigua; el excedente queda en `unallocatedCents`; invariante `sum(allocations) === importe − unallocated` con casos aleatorios; nunca sobrepaga una cuota.
**Por qué separada:** es dominio puro y se puede probar sin BD. Un bug aquí descuadra el dinero de todos los clientes.

**T-057 · `LayawayBalanceRecalculator`** · `B` · 3 pts · *Requisitos: `REQ-PAY-008`*
**Hecho cuando:** las **cuatro invariantes** tras una secuencia de 10 pagos aleatorios; `remaining_cents` nunca negativo; `next_due_date` correcto tras pagar la cuota intermedia; **solo esta clase escribe los 4 campos** (test de arquitectura).

**T-058 · `PaymentApplier`** · `B` · 3 pts · *Requisitos: `REQ-PAY-005`, `REQ-PAY-007`*
El colaborador compartido con la firma de [05 §5](05-design-tecnico.md).
**Hecho cuando:** crea `Payment` con el snapshot de tasa; asigna FIFO; recalcula balance; transiciona estado; ajusta el cupo del plan; todo en la transacción del llamador.
**Por qué separada de T-059 y T-062:** es lo que garantiza que aprobar un comprobante y registrar un pago manual den **resultados idénticos**. Escribirla una vez y probarla sola es lo que hace posible el test de equivalencia.

**T-059 · Envío de comprobante** · `B` · 5 pts · *Requisitos: `REQ-PAY-001`, `REQ-PAY-002`, `REQ-PAY-003`*
`SubmitReceiptAction`, `SignedFileUrl`, `ReceiptFileController`, `ReceiptPolicy`, `RealMimeType`, `CompressReceiptImage`, `UniqueReceiptFingerprint`, `ReceiptCreate.tsx` con selección de cuenta de cobro y vista previa.
**Hecho cuando:** crea en `pending_review` **sin tocar el balance**; exige referencia según método; **la misma referencia en otra organización se acepta**; MIME falso rechazado; **URL firmada válida de otra organización da `403`**; la ruta física incluye el `organization_id`; rollback elimina el archivo; **se acepta con la cuota de almacenamiento agotada**.

**T-060 · Bandeja de conciliación** · `B` · 3 pts · *Requisitos: `REQ-PAY-004`*
`ReceiptInboxController`, `ReceiptSubmitted`, canal `organization.{id}.receipts`, `Inbox.tsx`, `useReceiptInbox`.
**Hecho cuando:** **el canal rechaza a un usuario de otra organización y a un cliente**; orden FIFO de pendientes; marca de atrasado a las 48 h; fallo de broadcast no revierte; payload sin datos personales.
**Depende de:** T-079 (Reverb) para el tiempo real; sin él, el fallback de polling ya cubre la función.

**T-061 · Aprobación de comprobante e historial** · `B` · 3 pts · *Requisitos: `REQ-PAY-005`, `REQ-LAY-022`*
`ApproveReceiptAction`, pantalla de revisión con cronograma y previsualización FIFO.
**Hecho cuando:** FIFO en cascada; **excedente exige confirmación explícita**; `pending_down_payment` activa y genera cronograma; `overdue` vuelve a `active`; tolerancia completa y libera cupo; doble aprobación falla; **rollback total ante fallo**; snapshot de tasa con la fuente de la organización; comprobante ajeno da `404`.

**T-062 · Rechazo y pago manual** · `B` · 3 pts · *Requisitos: `REQ-PAY-006`, `REQ-PAY-007`*
`RejectReceiptAction`, `RegisterManualPaymentAction`, `ReceiptRejectedNotification`.
**Hecho cuando:** motivo obligatorio y archivo conservado; **prórroga solo si la ventana venció**; rechazado no se puede aprobar; pago manual sin `receipt_id` con `registered_by`; **produce el mismo resultado que la aprobación para el mismo importe** (test de equivalencia).

**T-063 · Reasignación de pagos** · `B` · 2 pts · *Requisitos: `REQ-PAY-009`*
`ReallocatePaymentAction`, `AllocationEditor`.
**Hecho cuando:** suma distinta rechazada; cuota ajena rechazada; sobrepago de cuota rechazado; **balance total invariante**; salir de `completed` con el plan en el techo se permite.

**T-064 · Comando de reconciliación** · `B` · 2 pts · *Requisitos: `REQ-PAY-008`*
`ReconcileLayaways` (comando + job) con `--fix` y `--organization`.
**Hecho cuando:** detecta y corrige una discrepancia inyectada; `--organization` acota; sin `N+1`; **el tenant se limpia entre lotes**; terminal no se corrige sin `--fix`.

**T-065 · Cobranza, clientes y dashboard** · `B` · 5 pts · *Requisitos: `REQ-BOF-040`, `REQ-BOF-041`, `REQ-BOF-042`, `REQ-BOF-044`*
`CollectionFilters`, `CollectionPreset`, `CustomerDirectoryQuery`, `CustomerShowController`, `DashboardMetricsQuery`, `DashboardWarningsQuery`, páginas y `MergeCustomersAction` (`REQ-IDN-006`).
**Hecho cuando:** un test **por cada uno de los 6 presets** en los límites exactos de fecha; **la vista y la exportación devuelven el mismo conjunto** (equivalencia, aunque la exportación llegue en T-070); búsqueda por los 4 criterios con normalización; sin `N+1`; **el perfil no filtra actividad en otras organizaciones**; cada uno de los **7 warnings** se dispara solo en su condición; el balance del perfil coincide con el del portal.

**Cierre de H4:** ciclo completo apartar → pagar → conciliar → cobrar · 15 requisitos cerrados · **el producto ya es usable**.

---

## H5 · Planes y suscripciones

> **Objetivo demostrable:** los límites bloquean de verdad, el cambio de plan funciona y se cobra por comprobante.

**T-066 · Medición de consumo** · `B` · 3 pts · *Requisitos: `REQ-SUB-003`*
`UsageCalculator`, `UsageCounter`, `RecalculateUsage`, comando.
**Hecho cuando:** **una prueba por cada una de las 6 métricas** con su definición exacta; el contador se mueve en la misma transacción; el job corrige una deriva inyectada; completar un apartado libera cupo; contador nunca negativo; sin `N+1`.

**T-067 · `LimitEnforcer` y aplicación de límites** · `B` · 3 pts · *Requisitos: `REQ-SUB-004`*
`LimitEnforcer`, `EnforcePlanLimit`, excepciones enriquecidas, `usePlanLimits`, `LimitBanner`, `UpgradePrompt`; **insertar las llamadas en las 12 acciones** de `REQ-SUB-004` RB-3.
**Hecho cuando:** un test por cada uno de los **12 puntos de aplicación**; concurrencia con un cupo disponible; **el mensaje al cliente final no revela el plan del negocio**; límite `NULL` no bloquea; `suspended` bloquea todo; función de pago da `403` por API.

**T-068 · Cambio de plan** · `B` · 3 pts · *Requisitos: `REQ-SUB-005`*
`ChangePlanAction`, `PlanChangePreview`, página de suscripción.
**Hecho cuando:** subir aplica límites al instante; bajar programa sin quitar nada; cancelar deja Free; la previsualización lista lo que excede; revertir antes del vencimiento funciona.

**T-069 · Facturación por comprobante** · `B` · 3 pts · *Requisitos: `REQ-SUB-006`*
`subscription_invoices`, `subscription_receipts`, `IssueInvoiceAction`, `ApproveSubscriptionReceiptAction`, `IssueRenewalInvoices`, historial de facturas.
**Hecho cuando:** renovación 7 días antes; Free no genera facturas; factura inmutable; aprobación extiende el período y levanta la suspensión; **duplicado bloqueado reutilizando el colaborador de `REQ-PAY-003`**; la factura usa la fuente de tasa de **plataforma**.

**T-070 · Exportación a Excel** · `B` · 3 pts · *Requisitos: `REQ-BOF-043`*
`ExportJob`, `GenerateExport`, los 3 `Export`, `PurgeExpiredExports`, `ExportController`.
**Hecho cuando:** Free da `403` y el botón se muestra deshabilitado; el request no genera el archivo; **el contenido coincide con la vista** (cierra la equivalencia de T-065); enlace caducado `403` y purgado `410`; **el archivo no contiene `account_id`**; descarga desde otra organización `403`; `chunkById`.

**T-071 · Impago, gracia y suspensión** · `B` · 3 pts · *Requisitos: `REQ-SUB-007`, `REQ-ORG-008`*
`ProcessSubscriptionDunning`, `SuspendOrganizationAction`, `CloseOrganizationAction`, `InvoiceOverdueNotification`.
**Hecho cuando:** la secuencia completa de estados por fechas; **cinco tests explícitos de que `suspended` no afecta a apartados, portal, comprobantes, chat ni recordatorios**; comprobante en revisión congela la gracia; pagar levanta la suspensión sin republicar; cierre con apartados activos rechazado.

**T-072 · Degradación no destructiva** · `B` · 2 pts · *Requisitos: `REQ-SUB-008`*
`ApplyDowngradeAction`, `DowngradeOutcome`, `ApplyScheduledDowngrades`.
**Hecho cuando:** **nada se borra** (un test por cada una de las 6 métricas); despublica los excedentes conservando los más antiguos con desempate por `id`; apartados y equipo intactos; **comprobante de cliente aceptado con cuota agotada y subida del negocio rechazada** (los dos lados); subir de plan durante la gracia cancela la degradación.

**Cierre de H5:** el modelo de negocio funciona y se puede cobrar · 8 requisitos cerrados.

---

## H6 · Cuentas de consumidor y feed

> **Objetivo demostrable:** una persona entra con Google, sigue dos catálogos de negocios distintos, ve su feed y reclama su historial previo.

**T-073 · Migraciones y modelos de cuenta** · `A` · 1 pt
`account_identities`, `catalog_subscriptions`, `catalog_digest_dispatches`; `Account`, `AccountIdentity`, `CatalogSubscription`; `AccountProvider`, `LinkOrigin`.
**Hecho cuando:** `accounts` **no tiene documento ni teléfono** (assert de esquema); las 2 tablas puente están en la lista blanca de `tenancy:audit`.

**T-074 · Google OAuth y guard `account`** · `B` · 3 pts · *Requisitos: `REQ-ACC-001`*
Socialite, `AuthenticateAccountAction`, `GoogleAccountController`, guard `account`.
**Hecho cuando:** crea cuenta con identidad; segundo login refresca perfil sin duplicar; **email repetido con otro `provider_id` crea cuenta nueva**; email compartido con un `User` aceptado; `email_verified` ausente rechazado; cancelar en Google no rompe la navegación.

**T-075 · Suscripción a catálogo** · `B` · 3 pts · *Requisitos: `REQ-ACC-002`*
`SubscribeToCatalogAction`, `UnsubscribeFromCatalogAction`, `FollowCatalogButton`, `useFollowCatalog`, página de suscripciones.
**Hecho cuando:** idempotente; catálogo expirado rechazado; **la intención se completa tras el login y devuelve al mismo catálogo**; **apartar con cuenta suscribe y sin cuenta no**; silenciar excluye del digest pero no del feed; despublicar deja la suscripción inactiva y republicar la revive.

**T-076 · Dos modos de sesión del portal** · `B` · 3 pts · *Requisitos: `REQ-ACC-005`, `REQ-LAY-020`*
Completar `PortalContext` con el modo cuenta, ampliar la portada a multi-negocio.
**Hecho cuando:** **la sesión acotada no accede al feed ni a suscripciones**; la de cuenta ve fichas de N negocios agrupadas; coexistencia prevalece la de cuenta; `code` ajeno da `404`; cuenta sin fichas es válida; **toda consulta del portal lleva filtro de organización** (assert de consultas).

**T-077 · Vinculación y reclamo** · `B` · 3 pts · *Requisitos: `REQ-ACC-004`, `REQ-IDN-002`*
`ClaimCustomerAction`, `LinkAccountAction`, `ClaimController`.
**Hecho cuando:** documento + código vinculan; **el documento solo NUNCA vincula**; código de otra persona falla; ficha ya vinculada rechazada; rate limit al 6.º intento; auditoría escrita en la organización; la matriz de precedencia del formulario; **el email enviado se ignora con sesión de cuenta**.

**T-078 · Feed y privacidad del suscriptor** · `B` · 5 pts · *Requisitos: `REQ-ACC-003`, `REQ-ACC-007`*
`FeedQuery`, `FeedController`, `FeedItemResource`, `FeedList`, `useFeed`, `SubscriberListQuery`, `SubscriberStatsQuery`, página de suscriptores.
**Hecho cuando:** **un producto de un catálogo no seguido nunca aparece**; deduplicación dentro de una organización y no entre organizaciones; `isNew` en el límite de 14 días; proyección con la fuente de tasa de cada organización; sin `count(*)`; **el `Resource` de suscriptor no contiene `email`, `phone` ni `account_id`**; una organización no ve suscripciones de otra.

**Cierre de H6:** el bucle de retención existe · 7 requisitos cerrados.

---

## H7 · Comunicación

> **Objetivo demostrable:** un cliente escribe al negocio y recibe respuesta en tiempo real; los recordatorios de cuota salen solos.

**T-079 · Reverb y canales** · `B` · 3 pts · *Requisitos: `REQ-INF-005`, `REQ-CHT-003`*
Servicio `reverb`, `config/reverb.php`, `routes/channels.php` con los 3 canales, `echo.ts`, `useRealtimeMessages` con fallback.
**Hecho cuando:** **la autorización rechaza a un miembro de otra organización** en los 3 canales; broadcast `afterCommit`; fallback a polling tras 5 s; deduplicación por `id`; sin configuración funciona en polling.

**T-080 · Chat: envío y bandeja** · `B` · 5 pts · *Requisitos: `REQ-CHT-001`, `REQ-CHT-002`*
`conversations`, `messages`, `SendMessageAction`, `ConversationListQuery`, `ChatWidget`, `useConversation`, `ThreadContextHeader`, bandeja.
**Hecho cuando:** **la organización se deriva del catálogo y no del payload**; suscriptor sin ficha no puede escribir; hilo creado una sola vez con concurrencia; `idempotencyKey` evita duplicados; **enviar a una organización sin ficha da `403`**; el contexto del cliente **no incluye datos de otros negocios**; plan Free limita la vista a 30 días **sin borrar mensajes**.

**T-081 · Lectura y no leídos** · `B` · 2 pts · *Requisitos: `REQ-CHT-004`*
`markReadBy`, `UserTyping`, `RecountConversations`, `useTypingIndicator`.
**Hecho cuando:** contadores correctos tras 10 mensajes en ambas direcciones; marcado idempotente; `chat:recount` corrige una deriva; el badge cuenta hilos y no mensajes; el badge del portal suma varios negocios; **el límite de 30 días no altera el conteo**.

**T-082 · Web Push y destinatario polimórfico** · `B` · 4 pts · *Requisitos: `REQ-NOT-001`, `REQ-NOT-005`, `REQ-CHT-005`*
`push_subscriptions`, `NotifiableResolver`, SW, `usePushSubscription`, `SendWebPush`, `PurgeStalePushSubscriptions`, `NotifyUnreadMessage`.
**Hecho cuando:** se suscribe a `Account` o a `Customer` según la sesión; **una persona con ambas identidades recibe el aviso una sola vez**; vincular la cuenta reasigna la suscripción; `410` purga y `429` no; mensaje leído antes de los 2 min no notifica; 5 mensajes producen 1 notificación.

**T-083 · Notificaciones de cobro y manuales** · `B` · 3 pts · *Requisitos: `REQ-NOT-002`, `REQ-NOT-003`, `REQ-NOT-004`, `REQ-ACC-006`*
`NotificationType` con sus mapas, `notification_dispatches`, `DispatchDueRemindersAction`, `SendManualPushAction`, `DispatchCatalogDigestAction`, las clases de notificación, `Compose.tsx`.
**Hecho cuando:** un test por offset en el límite; doble ejecución no duplica; **agrupa por negocio y no entre negocios**; envío a las 08:00 de cada zona; **el nombre del negocio aparece en todo mensaje**; rollback no notifica; Free no envía digest y reporta el alcance potencial; el segmento reutiliza `CollectionFilters`.

**Cierre de H7:** el negocio se comunica con sus clientes · 10 requisitos cerrados.

---

## H8 · Datos y plataforma

> **Objetivo demostrable:** la telemetría entra sin frenar la navegación, el negocio ve su analítica y tú ves tus métricas de plataforma.

**T-084 · Telemetría: ingesta y almacén** · `B` · 5 pts · *Requisitos: `REQ-TLM-001`, `REQ-TLM-002`, `REQ-TLM-003`, `REQ-TLM-004`*
`TelemetryEventName` con los 15 eventos, `EventSanitizer`, `telemetry_events`, `TelemetryController`, `ProcessTelemetryBatch`, `useTelemetry`, `TelemetryBuffer`, `useDwellTime`, `AssignVisitorId`, los 2 jobs de vinculación.
**Hecho cuando:** un test por evento con su payload; **el cliente no puede inyectar un `organization_id`**; el endpoint no escribe en BD; el buffer dispara por los 3 disparadores; **lote multi-organización se atribuye correctamente y el tenant se limpia entre eventos**; permanencia < 5 s no emite; **la vinculación a `customer_id` está acotada por organización y la de `account_id` no**.

**T-085 · Privacidad, retención y analítica** · `B` · 3 pts · *Requisitos: `REQ-TLM-005`*
`IpHasher`, `CookieConsent`, `useConsent`, `PurgeTelemetry`, `TelemetryMonthlyAggregate`, `CatalogAnalyticsQuery`, `AnalyticsController`.
**Hecho cuando:** **la IP nunca se persiste en claro**; no esenciales bloqueados sin consentimiento y los 5 esenciales sí; purga con retención distinta por plan; agregados preservados antes del borrado; **la analítica no expone `account_id` ni actividad en otras organizaciones**.

**T-086 · Backoffice de plataforma** · `B` · 8 pts · *Requisitos: `REQ-PLT-001`, `REQ-PLT-002`, `REQ-PLT-003`, `REQ-PLT-004`, `REQ-PLT-005`, `REQ-PLT-006`*
`PlatformUser`, `PlatformAdminSeeder`, guard `platform`, `routes/platform.php`, `OrganizationDirectoryQuery`, `PlatformMetricsQuery`, `SystemHealthQuery`, `SupportAccessGrant`, `GrantSupportAccessAction`, `RequiresSupportAccess`, `GrantCourtesyPeriodAction`, las 5 páginas.
**Hecho cuando:** **credenciales de `User` no sirven en el guard `platform`**; el directorio **no contiene ningún campo personal de cliente final**; MRR incluye `past_due` y excluye `suspended`; `multiBusinessAccounts` cuenta correctamente; **sin grant da `403` en cada sección de datos y con grant se lee pero no se escribe**; el grant aparece en el `activity_log` de la organización y se le notifica; `withoutTenantScope` solo en `app/Queries/Platform` y `app/Actions/Platform`.

**Cierre de H8:** los datos están modelados y la plataforma es operable · 11 requisitos cerrados.

---

## H9 · Producción

> **Diferido hasta que haya dominio y servidor.**

**T-087 · Traefik, TLS y despliegue** · `AB` · 5 pts · *Requisitos: `REQ-INF-002`, `REQ-INF-006`, y la parte de producción de `REQ-INF-001`*
`docker-compose.prod.yml` con etiquetas de Traefik, `TrustProxies`, `docs/runbook-despliegue.md`, imagen etiquetada por commit.
**Hecho cuando:** `url()` genera `https` detrás del proxy; `request()->ip()` devuelve la del cliente; redirección `www` → apex; el router de `reverb` sin compresión; la imagen final sin `node_modules` ni dependencias de desarrollo; la verificación de humo del runbook existe.
**Bloqueado por:** compra del dominio y provisión del servidor.

---

## Verificación de cobertura

Los 118 requisitos están asignados. Reparto:

| Dominio | Req. | Tareas |
|---|---|---|
| `ORG` | 8 | T-005, T-006, T-008…T-010, T-013, T-013b, T-014, T-015, T-036, T-071 |
| `SUB` | 8 | T-011, T-066…T-072 |
| `IDN` | 8 | T-040, T-043, T-045, T-049, T-050, T-065, T-077 |
| `ACC` | 7 | T-073…T-078 |
| `CAT` | 9 | T-025, T-026, T-030…T-035 |
| `FX` | 6 | T-017…T-023 |
| `LAY` | 15 | T-041…T-048, T-051…T-053, T-061 (022), T-076 (020) |
| `PAY` | 9 | T-054…T-064 |
| `BOF` | 13 | T-012, T-016, T-026…T-029, T-032, T-038, T-065, T-070 |
| `NOT` | 5 | T-082, T-083 |
| `CHT` | 5 | T-079…T-082 |
| `TLM` | 5 | T-084, T-085 |
| `PLT` | 6 | T-086 |
| `INF` | 6 | T-002 (001, 003, 004), T-079 (005), T-087 (002, 006) |
| `QAX` | 8 | T-003 (001, 002, 003, 004, 007), T-007 (008) + criterio transversal en todas |

`REQ-QAX-005` y `REQ-QAX-006` no tienen tarea propia: son **política de proceso** y se cumplen en cada PR (declarar los IDs que cierra y propagar al espejo si cambia un identificador público).

`REQ-QAX-003` y `REQ-QAX-007` se instalan en T-003 pero **se cumplen en las 85 tareas restantes**: son la razón por la que ninguna tarea de Fase B cierra sin tests ni sin PHPDoc.

`REQ-INF-003` se abre en T-002 y **cierra al final de H8**, cuando las catorce entradas del schedule existen: cada job añade la suya en su propia tarea.

`REQ-INF-002` es la única de H9 y está explícitamente diferida hasta que haya dominio y servidor.

---

## Riesgos del plan

| Riesgo | Señal temprana | Respuesta |
|---|---|---|
| **H0 se alarga** por afinar el tenancy | Más de 5 jornadas sin llegar a T-010 | Cerrar T-005…T-007 con lo mínimo y volver después; el gate ya detecta lo que falte |
| **T-045 y T-061 son las tareas más densas** (creación y aprobación) | Cualquiera de las dos pasa de 3 jornadas | Partir por sad paths: el happy path primero, los SP en un segundo PR |
| **Insertar `LimitEnforcer` en H5** toca 12 acciones ya escritas | — | Es deliberado (`05 §10.2`): más limpio que anticiparlo. Cada inserción es de una línea más su test |
| **H6 toca el flujo de apartado** ya cerrado | Regresiones en T-045 al hacer T-077 | Los tests de H3 son la red; se ejecutan antes de cerrar cada tarea de H6 |
| **Deriva spec ↔ código** sin herramienta | Un PR que no declara requisitos | `REQ-QAX-006` RB-7 y revisión disciplinada; si un requisito resulta inviable, se corrige la spec en el mismo PR |
