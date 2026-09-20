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
- **Un test de propiedad lleva semilla fija y tramos declarados.** Sin semilla, un fallo aleatorio es irreproducible, se etiqueta de *flaky* y se queda ahí para siempre; con `mt_srand()` fija, el caso 387 es el caso 387 en la máquina de quien lo arregle. Y los tramos se eligen por lo que cada uno puede romper, no por cubrir un rango: en `T-018`, los primos son donde vive el residuo, `n > cents` es el reparto que devuelve ceros, y los negativos son los créditos de SP-3. **Una tolerancia que nunca se acerca a su límite no prueba nada**: si la deriva real medida es cero, el test se aprieta a igualdad exacta.
- **Un test de concurrencia barre todas las tablas que toca, no solo la raíz.** Los tests de dos conexiones no pueden usar `RefreshDatabase` —un bloqueo entre conexiones solo es observable sobre filas confirmadas—, así que limpian a mano. Purgar solo `organizations` y confiar en el `ON DELETE CASCADE` falla en cuanto una tabla no lo declara: en `T-016`, las entradas de `activity_log` de los dos tests de concurrencia sobrevivían y contaminaban el resto de la suite. La lista de lo que se barre se escribe explícita, y crece con cada tabla que el test escriba.
- **Una corrección de la spec que invalida código ya shipeado nombra quién lo realinea.** La spec es la autoridad, así que en cuanto se corrige, el código que la contradice pasa a estar mal — y si la nota no dice qué tarea lo arregla, queda una divergencia que ningún test detecta, porque los tests se escribieron contra el código. Pasó con `RateView.publishedAt`: la corrección se escribió después de que `T-019` shipeara `fetchedAt` y no asignó el realineado; lo cazó `T-017` al leer el contrato. Toda nota de corrección lleva su tarea, aunque sea la siguiente.
- **Lo que se puede olvidar sin que nadie se entere, no vale.** Es la regla de la que salen las tres anteriores y varias decisiones de diseño repartidas por el código, y conviene tenerla suelta porque se aplica a casos que no son rutas: un colaborador provisional devuelve `false` en silencio cuando llega su tabla (`PublishedCatalogs`, corregido en `T-016`) o **lanza** diciendo qué escribir (`RecordedReceipts`, `T-015`); una operación que se completó de otra forma **se señala con una excepción y no con un booleano**, porque un valor de retorno se ignora sin escribir una línea (`PaymentAccountInUseException`, `T-015`); un `match` sobre un enum no lleva `default` (`LimitEnforcer::measure()`); una receta que nadie invoca es una regla que no existe (`format:check`, `T-016`). Ante la duda entre un fallo ruidoso y uno cómodo, ruidoso — el coste de equivocarse en esa dirección es una hora; en la otra, un requisito apagado durante meses.
- **Una guarda que compara contra el estado anterior tiene que poder salir de su propio veto.** Caso de la anterior, y conviene aparte porque no se parece: si rechazar **no actualiza** aquello contra lo que se compara, un rechazo legítimo se vuelve permanente y el sistema se queda sirviendo el último valor bueno para siempre, con un log cada media hora y nada más. Salió en `T-020` con el umbral del 30 % de la tasa (`REQ-FX-002` SP-2b): una semana de caída, un movimiento real del bolívar, y al volver la fuente **ninguna** lectura vuelve a entrar nunca. La salida se escribe con la misma vara que la alarma —la guarda se abstiene cuando su referencia ya es tan vieja que la propia plataforma la declara obsoleta— y no bajando el umbral, que es lo que protege. Vale para todo lo que valide por comparación con lo último visto: tasas, contadores de uso, *checksums* de importación.
- **Un índice único cuenta filas, no filas visibles.** En cuanto una tabla tiene borrado lógico, la comprobación de unicidad que hace la aplicación y la que hace la base dejan de mirar lo mismo: el `where` del modelo omite lo de la papelera y el índice no. El resultado no es un duplicado, es un **`500` en lugar de un `422`** — la validación da por libre un valor que el `INSERT` rechaza después. Salió en `T-027` con el SKU y el `slug` de un producto archivado, y se resuelve comprobando `withTrashed()`. Vale para toda tabla con `SoftDeletes` y un índice único: hoy `products`, y cualquiera que venga.
- **Una fecha fija en un test envejece; lo que ancla el pasado se escribe relativo al presente.** En `T-021`, los tests que fijaban el veto del 30 % fechaban su referencia con una fecha literal de nueve días atrás. Mientras la regla solo miraba la variación, daba igual; en cuanto SP-2b añadió la antigüedad, esa referencia pasó a estar obsoleta, la guarda se abstuvo y **los dos tests probaban lo contrario de lo que decía su nombre**. Lo que un test quiere decir no es «el 9 de septiembre», es «una referencia reciente» o «una de hace una semana», y eso se escribe `now()->subMinutes(10)`. La excepción es el borde exacto —las 24 h justas—, que se afirma con el reloj congelado porque con el tiempo corriendo no es afirmable. No choca con la semilla fija de los tests de propiedad: ahí lo que se congela es el azar, no el calendario.
- **Una lista de casos es una propiedad del tipo, no del consumidor.** Tres veces en el mismo módulo: `SettingKey` validaba las tres fuentes con tres cadenas escritas a mano hasta que `T-019` las mandó a `RateSource`; el job, el endpoint público y la pantalla llevaban cada uno su censo de las fuentes de plataforma hasta `RateSource::platform()` en `T-021`; y las etiquetas de las mismas tres fuentes están hoy en un `.tsx` esperando a `T-022`. El síntoma nunca es un test en rojo: el consumidor que se quedó corto **sirve de menos, en silencio** —una fuente que no aparece, una etiqueta que dice otra cosa que el sello—, y eso no se descubre hasta que alguien lo mira.
- **Una fecha que entra de fuera se normaliza en el borde.** No por pulcritud: `published_at` es la mitad de la clave de deduplicación de `REQ-FX-002` RB-3, y en `T-020` las cuatro horas de desfase entre el UTC de DolarApi y `APP_TIMEZONE` no se vieron como una fecha torcida —se vieron como una fila nueva de la misma cotización cada media hora, para siempre—. Una fecha mal encajada rara vez se manifiesta como una fecha: se manifiesta como una comparación que deja de coincidir. Y su gemela del otro lado: **si la escribe la base de datos, la sesión de la base de datos tiene que correr en la zona de la aplicación** ([05 §4]).

| Hito | Tareas | Puntos | Cierra |
|---|---|---|---|
| H0 · Cimientos | T-001…T-016 (+ `T-013b`) | 41 | 16 req. |
| H1 · Aritmética y tasa | T-017…T-022 | 14 | 5 req. |
| H2 · Catálogo | T-024…T-038 | 43 | 19 req. |
| H3 · Apartado | T-039…T-053 | 41 | 23 req. |
| H4 · Cobranza | T-054…T-065 (+ `T-023`) | 37 | 16 req. |
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
- **`REQ-ORG-003` no quedó cerrado aquí, aunque su gate sí.** Faltaba la vía por la que un dueño cambia un rol en el producto: RB-3 la enuncia y SP-2/SP-4 la suponen. Se detectó al cerrar `T-013` y lo cerró **`T-013b`**.

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

**Decisiones tomadas al cerrar `T-013b`:**
- **Promover y degradar no son simétricas, y por eso la acción tiene un `match` en vez de un `update()`.** Promover **suma** un `owner` activo: no puede romper la invariante de RB-2 y no necesita bloqueo. Degradar **resta**: es la única dirección en la que una organización se queda sin nadie al mando, y ahí entra `LastOwnerGuard` con `'degradar'` y la escritura como `Closure`. Tampoco se toca `LimitEnforcer`: el techo de `users` cuenta gente con acceso, no gente con galones, y la persona ya ocupaba su sitio.
- **El mismo rol no escribe nada, y el atajo va antes del `match`.** Un `UPDATE` que no cambia nada ensuciaría la auditoría de `REQ-ORG-004` RB-7 con cambios que no ocurrieron y haría pasar por el bloqueo a quien no toca la invariante. Sin ese atajo, «cambiar» al último `owner` a `owner` fallaría con `LastOwnerException`, que es absurdo; hay test.
- **Un `owner` puede degradarse a sí mismo** (RB-3) y eso **no** es el caso de `REQ-ORG-005` RB-4: aquella regla prohíbe *desactivarse*, que es quedarse fuera; degradarse es seguir dentro con menos. La única barrera es ser el último.
- **SP-4 se prueba por HTTP y con dos identidades en la misma petición sin falsearlas.** El `member` entra **por el formulario**, así que su sesión guarda solo su identificador; la promoción va por la ruta nueva; y en medio `actingAs($owner)` fija el usuario en el guard **sin tocar la sesión**, de modo que al olvidar los guards la petición siguiente se resuelve otra vez desde la sesión del `member`, intacta. El control contra el falso positivo es `assertAuthenticatedAs($member)` al final: sin él, el test pasaría igual si quien entrara fuese el `owner`.
- **`TeamMemberRow` sale a `resources/js/features/tenancy/components/`**, primer inquilino de `features/` ([05 §8]). El selector de rol acercaba `Index.tsx` al `max-lines` de 200 de `REQ-QAX-002` RB-1, y la fila ya era un componente dentro del mismo archivo: era la costura natural, no un troceo para esquivar la regla.
- **`SelectField` gana `hideLabel`.** En una columna de tabla la etiqueta visible repetiría la cabecera en cada fila, pero quitarla dejaría el `select` sin nombre accesible —un lector de pantalla anunciaría «cuadro combinado» y nada más—. Con `sr-only`, la etiqueta nombra a la persona (`Rol de Ana`), que es más útil que quince «Rol» seguidos.
- **La ruta `POST equipo/{member}/rol` no declara `during-suspension`**, como el resto del grupo: repartir el gobierno del negocio no es cobrar ni aprobar comprobantes, que es lo único que `REQ-ORG-008` RB-3 conserva.
- **`REQ-ORG-003` queda cerrado de verdad.** Se repasaron sus seis reglas y sus cinco `SP` contra el código: RB-1, RB-2, RB-5 y RB-6 estaban cubiertos desde `T-009`; RB-4 lo completó `T-013` con la navegación filtrada por rol; RB-3, SP-2 y SP-4 son lo que cierra esta tarea. SP-5 —«`owner` desactivado que era el único → imposible por `REQ-ORG-005` RB-3»— lo cubre `DisableTeamMemberActionTest` desde `T-013`.

**T-014 · Ajustes, identidad y vitrina** · `AB` · 3 pts · *Requisitos: `REQ-ORG-006`*
`SettingKey` reducido a **10** claves (retirando `storefront_enabled`, ver arriba) con sus reglas, migración de `organizations.phone`, `Settings` con caché **por organización**, `UpdateSettingsAction`, `UpdateOrganizationAction`, página de ajustes.

**`OrganizationStorefrontController` no es de esta tarea: es de `T-036`.** Estaba escrito en las dos, y sobra aquí. La vitrina `/{org-slug}` lista catálogos publicados, y `Catalog` no existe hasta H2: lo que se podría construir hoy es una página que consulta un modelo inexistente, es decir, código muerto que aparenta estar vivo. Es el mismo criterio que dejó los middlewares de catálogo y portal para sus hitos en `T-006`. Lo que **sí** entra aquí es el interruptor: `organizations.storefront_enabled` ya es columna desde `T-004` y la pantalla de ajustes la gobierna. `T-036` construye la página que ese interruptor enciende.
**Hecho cuando:** cada clave valida su rango; **caché aislada entre organizaciones** (test de fuga); clave desconocida ignorada; Redis caído lee de BD; slug bloqueado con catálogos publicados; **la navegación deriva de `OrganizationRole::capabilities()`** y ninguna sección declara su rol a mano.

**Añadido de alcance: la frontera de `REQ-ORG-003` RB-4 está declarada dos veces.** `OrganizationRole::capabilities()` se escribió en `T-009` —dice su propio docblock— «para pintar el menú sin repetir la frontera», y hoy **no tiene un solo llamante**: `backoffice-layout.tsx` la vuelve a declarar a mano con un `ownerOnly` por sección. Lo detectó `T-013b` repasando RB-4.

Hoy no rompe nada: son dos entradas de menú y coinciden. El problema es cuándo se rompe. Cada hito añade secciones —Ajustes en esta tarea, Suscripción en `T-069`, Catálogos, Cobranza…— y el día que una capacidad cambie de lado, el enum lo sabrá y el menú no. El resultado no es un error visible: es un `member` viendo una sección que le va a dar `403`, o —peor— un `owner` sin ver algo que sí puede hacer, sin un solo test en rojo. Se arregla aquí, que es la siguiente tarea que toca ese archivo: cada sección declara **la `OrganizationCapability` que necesita** y el layout le pregunta al rol.

**Por qué no es otra `T-014b`.** `T-013b` existió porque un requisito se dio por cerrado sin estarlo, y eso merece su propia línea. Esto es distinto: RB-4 **se cumple**; lo que hay es una segunda fuente de verdad esperando a divergir. Un defecto de mantenibilidad viaja con la próxima tarea que ya iba a tocar ese código, no con una tarea propia — y esta iba a tocarlo de todos modos para añadir su sección.

**Decisiones tomadas al cerrar `T-014`:**
- **La caché de ajustes tiene dos copias y las dos van por organización:** la entrada de Redis bajo `settings:{organization_id}` (RB-3) y un memo en memoria indexado por identificador. El memo es donde una fuga sería más fácil de escribir y más difícil de ver —no deja rastro fuera del proceso—, así que `Settings` se registra como **singleton** justamente para que el test pueda ejercitarlo: si filtrara, filtraría ahí.
- **El test de fuga envenena en vez de comparar.** Dos lecturas que difieren pasan también con una caché rota que no cachea nada; escribir un valor imposible bajo la clave de una organización y exigir que la otra siga leyendo lo suyo, no. Se comprueba además **la clave** —no solo el valor— y que invalidar una no purga a la vecina.
- **SP-7 se prueba con un almacén que lanza en todas sus operaciones** (`Tests\Support\ExplodingCacheStore`, registrado con `Cache::extend`), no solo en `get()`: la mitad que se olvida siempre es la escritura, y una lectura que sobrevive al fallo pero revienta al cachear lo leído deja el backoffice igual de caído. Todas las operaciones de caché de `Settings` van envueltas, capturando `Throwable` y no las excepciones concretas de phpredis, porque el contrato es «la caché nunca es el motivo de que esto falle».
- **`SettingKey` gana dos puertas y no una.** `tryFromKey()` es la silenciosa que exige RB-2 para lo que llega de fuera —permite desplegar una versión que ya escribe una clave nueva contra filas sembradas antes, y leer una base donde todavía vive `storefront_enabled`—; `fromKey()` lanza `UnknownSettingException` y es para cuando la clave la nombra nuestro propio código, donde un valor desconocido es una errata que haría leer un default en vez del ajuste del negocio.
- **`Settings` ignora una fila cuya clave el enum ya no conoce**, leyendo la columna en crudo y no el atributo casteado: el cast al enum lanzaría, y RB-2 quiere que esa fila se salte, no que tumbe la lectura entera. El borrado de las filas de `storefront_enabled` es una migración de datos aparte, y no es lo que sostiene la corrección: es lo que evita arrastrar para siempre una fila que nadie lee y que puede contradecir a la columna.
- **Los topes de cada clave se eligieron aquí y cada uno acota un daño concreto**, escrito en `validationRules()`. Los de `default_frequency_days` ∈ [1, 90] y `default_installments_count` ∈ [1, 36] son normativos (`REQ-LAY-004` RB-7) y se citan; los demás son de esta tarea: ventana del inicial ≤ 168 h (inventario reservado sin cobrar), gracia ≤ 30 días (con más, «vencida» no significa nada), tolerancia ≤ 1000 céntimos (es para migajas de redondeo, no para perdonar deuda), alerta de mora ≤ 365 días y como mucho diez recordatorios **distintos**.
- **Hay un test de que el default de cada clave pasa su propia validación.** Parece obvio y es el caso que más caro sale: los defaults se siembran en el registro sin validarse, así que uno fuera de rango solo se descubre cuando alguien abre ajustes y pulsa guardar sin tocar nada.
- **`organizations.phone` se resuelve con las dos mitades.** Se añade la columna —el teléfono es el canal real para hablar con un negocio en este mercado, y una columna nullable hoy es más barata que añadirla con quinientas organizaciones dentro— y **`RegisterOrganizationData` deja de declararlo**: `REQ-ORG-001` RB-8 quiere un alta sin fricción y el teléfono pertenece al perfil, que es esta pantalla. El test de la propiedad se queda como guarda por reflexión: si alguien la devuelve al alta, se ve.
- **`UpdateOrganizationAction` no llama a `assertHasFeature()`**, y es la decisión que más fácil sería tomar al revés. Bloquear el guardado del branding rompería SP-3 —«al subir de plan se activa sin volver a configurarlo»— y convertiría una mejora de plan en rellenar el formulario otra vez. Quien pregunta por la función es quien pinta, con la lectura `hasFeature(Branding)`, y el resultado viaja en `OrganizationBrand.brandingEnabled`. Hay test de las dos mitades: se guarda en Free, y al cambiar el plan se aplica sin tocar nada.
- **El control de branding no se deshabilita.** `REQ-SUB-004` RB-7 dice «deshabilitado con el motivo, nunca oculto», pero eso gobierna **funciones**, y aquí la función no es escribir el dato sino aplicarlo (RB-5b). Lo que hay es el aviso al lado, con el enlace a Suscripción.
- **SP-5 se escribe con su forma definitiva y un colaborador sustituible:** `App\Domain\Catalog\PublishedCatalogs::exist()`. Hoy comprueba que la tabla `catalogs` no existe y responde que no hay ninguno —lo cual es literalmente cierto, no un `TODO` disfrazado—, y la acción ya pregunta, ya bloquea y ya tiene su `422` probado sustituyendo la clase en el contenedor. **`T-032` rellena el cuerpo con `Catalog::query()->whereNotNull('published_at')->exists()`** y no cambia nada más: ni la acción, ni la ruta, ni el mensaje, ni el test. La clase no es `final` por eso.

**Corregido: es `T-032`, no `T-030`.** `T-030` son los estados de publicación del **producto** (`REQ-CAT-007`); quien publica catálogos —y por tanto quien introduce el `published_at` que esta consulta lee— es `T-032` (`REQ-CAT-022`).

**Y el relevo tiene que fallar ruidosamente, porque hoy falla en silencio.** La guarda de `Schema::hasTable('catalogs')` es correcta mientras la tabla no exista, pero **`T-024` crea esa tabla ocho tareas antes que `T-032`**: desde ese commit la comprobación pasa, el cuerpo sigue devolviendo `false`, y `REQ-ORG-006` SP-5 queda desactivado sin que ningún test se ponga en rojo. El síntoma no aparecería hasta que un negocio renombrara su slug con catálogos publicados y descubriera a los tres días que nadie llega a su enlace — que es exactamente el daño que la regla existía para evitar. **`T-016` invierte la guarda**: si la tabla existe y el cuerpo no se ha sustituido, lanza. Es la misma convención de fallo cerrado que rige las declaraciones de ruta y el `match` sin `default` de `LimitEnforcer::measure()`.
- **Dos rutas de guardado y una pantalla.** Los ajustes de operación y la identidad son capacidades distintas de RB-1 —`Settings` y `Branding`— con dos policies; un solo `PUT` obligaría a autorizar por la más permisiva y a revalidar todo para cambiar un color. `PUT /ajustes/identidad` se añade a las dos de [05 §7.4]; `PUT /ajustes/tasa` sigue siendo de `REQ-FX-003` y llega con H1. Ninguna sobrevive a la suspensión.
- **La navegación deriva de `OrganizationCapability`, y `NavSection.capability` admite `'any'` como valor declarado.** Con un campo opcional, olvidarlo haría pública una sección de gobierno en silencio, que es el mismo modo de fallo que se venía a cerrar; así hay que escribir `'any'` y no escribir nada no compila. Al hacerlo no apareció ninguna capacidad mal repartida: las doce de `requiresOwner()` coinciden con RB-1 y `NavigationCapabilitiesTest` compara props contra enum para los dos roles.
- **El formulario de identidad va por `POST` con `_method: 'put'`.** Lleva un archivo, y un `PUT` multipart no lo interpretan ni PHP ni Laravel; el router resuelve `_method` antes de emparejar, así que la ruta sigue siendo `PUT`.
- **El logo vive en el disco `public` y bajo carpeta por organización**, y sustituirlo borra el anterior: nadie más lo referencia y el almacenamiento es una métrica de plan (`REQ-SUB-003`). Firmar una URL por visita sería un coste sin contrapartida —no hay nada privado en el logo de un negocio que quiere que lo vean—.
- **RB-8 (auditoría con antes y después) no entra**: `activity_log` y `LogsSensitiveActivity` son de `T-016`. Lo que sí se escribe ya es `organization_settings.updated_by`, porque el «quién lo cambió» se pierde en el momento del cambio y después no se recupera.

**T-015 · Cuentas de cobro** · `AB` · 2 pts · *Requisitos: `REQ-ORG-007`*
`PaymentAccount`, `PaymentMethod`, `ValidPaymentIdentifier`, `UpsertPaymentAccountAction`, CRUD.

**Corregido: `PaymentMethod` tiene seis casos, no tres.** La fuente es el bloque de contratos compartidos de [22-comprobantes-y-conciliacion.md](22-comprobantes-y-conciliacion.md): `pago_movil`, `transferencia`, `zelle`, `binance_pay`, `efectivo_usd` y `efectivo_ves`. La cifra de esta línea era un error de redacción del plan.

**Reparto del enum con H1 y H4.** El enum es compartido entre `REQ-ORG-007` (esta tarea) y `REQ-PAY-001` (comprobantes, H4), y sus tres métodos no se pueden escribir todos aquí: `defaultCurrency(): Currency` necesita `Currency`, que nace en `T-017`. Aquí se crean los seis casos y `identifierLabel()`, que es lo que esta tarea consume; `requiresReference()` y `defaultCurrency()` llegan con la tarea de comprobantes que los use. Escribir un `Currency` provisional para completar el enum sería fabricar la segunda fuente de verdad que `T-017` tendría que desmontar.
**Hecho cuando:** un caso por método con identificador válido e inválido; borrado con comprobantes se degrada a desactivación.

**Decisiones tomadas al cerrar `T-015`:**
- **El relevo de los comprobantes es `App\Domain\Payment\RecordedReceipts::existFor()`, y nace fallando ruidosamente.** `receipts` llega con `T-054`; hasta entonces ninguna cuenta tiene comprobantes, que es literalmente cierto, y la acción ya degrada el borrado con su test contra esta clase sustituida en el contenedor. La guarda va **al revés que la de `PublishedCatalogs`**: si la tabla existe y el cuerpo no se ha sustituido, **lanza** con el mensaje que dice qué escribir. **Quien lo rellena es `T-054`, la tarea que crea la tabla** —no una posterior—, y esa es exactamente la ventana de ocho tareas que `T-016` tiene que cerrar en el otro caso. Ver la nota añadida bajo `T-054`.
- **`PaymentMethod` gana un tercer método fuera del contrato compartido: `label()`**, y un cuarto de conveniencia, `isCash()`. El primero por lo mismo que `UsageMetric::label()` en `T-013` —el desplegable y la ficha nombran el método, y una lista de etiquetas en el `.tsx` sería un segundo censo de los seis casos—; el segundo porque «¿tiene banco detrás?» se preguntaba en tres sitios y repartido es donde se olvida uno. Ninguno invade el reparto con H4: `requiresReference()` y `defaultCurrency()` siguen sin escribirse, y hay un test por reflexión que se cae el día que aparezcan.
- **`payment_accounts.method` se castea al enum en el modelo.** `identifierLabel()` y el formato cuelgan de él, y leer la columna cruda obligaría a un `PaymentMethod::from()` en cada sitio que la toca. Cambió una aserción de `TenantFactoriesTest` (`T-008`), que comparaba contra la cadena.
- **Cada formato de `identifier` vive en `ValidPaymentIdentifier` y no en el enum**, que es lo que el gate de `REQ-ORG-007` pide con su anti-`SHALLOW`. Los formatos son los del instrumento real: móvil venezolano con prefijo de operadora —aceptando las tres grafías con que se escribe—, veinte dígitos de cuenta nacional, correo en Zelle, Pay ID numérico o correo en Binance Pay, y una dirección con longitud mínima en efectivo. **Zelle no admite teléfono de Estados Unidos**, aunque el servicio real sí: RB-2 fija el correo y ampliarlo sería inventar contrato en una tarea que no es la suya.
- **Lo que consume cupo es una cuenta *activa*, que es lo que mide la métrica.** Crear una desactivada no gasta, reactivarla sí, y editar una que ya está activa no vuelve a pedir cupo. Es la simetría de `EnableTeamMemberAction`: sin ella, desactivar y reactivar sería la forma de rebasar cualquier plan sin pagarlo. `LimitEnforcer` no necesitó ningún cambio — `measure()` ya contaba `payment_accounts` desde `T-013`.
- **`PaymentAccountInUseException` se lanza *después* de que la desactivación esté confirmada, y no es un fallo.** La alternativa era devolver un booleano, y es peor por un motivo que este repositorio ya se ha encontrado dos veces: **un valor de retorno se ignora sin escribir nada**, y un llamante que no lo mire enseña «cuenta eliminada» sobre una cuenta que sigue ahí. Nunca llega al cliente como error: el controlador la recoge y la convierte en el `status` de la redirección, así que no tiene código HTTP ni manejador en `bootstrap/app.php`.
- **Los avisos de SP-4 y SP-6 los produce `warningsFor()`, sobre el estado ya escrito y no sobre la intención.** Ninguno bloquea: dos cuentas idénticas se permiten —hay negocios con dos cuentas en el mismo banco— y desactivar la última activa también, **sin despublicar nada**. El aviso del duplicado compara el identificador reducido —sin separadores ni mayúsculas— y **no canoniza un móvil entre sus formas con y sin prefijo de país**: eso es conocimiento de `ValidPaymentIdentifier`, copiarlo sería un segundo analizador de teléfonos, y lo único que se pierde es la frase, no la regla.
- **El estado de la cuenta es un campo del formulario, no una quinta ruta.** `REQ-ORG-007` declara cuatro verbos y activar o desactivar es un `PUT` con `is_active` cambiado; un par de endpoints `activar`/`desactivar` habría sido una segunda puerta a la misma escritura, con su propio sitio donde olvidarse del cupo.
- **Ninguna ruta declara `during-suspension`.** `REQ-ORG-008` RB-2 conserva los datos de cobro **intactos** durante una suspensión —los apartados vivos siguen mostrando a dónde pagar—, que es distinto de poder cambiarlos: lo que RB-3 deja abierto es cobrar y aprobar comprobantes, no redirigir el dinero mientras se le debe a la plataforma. La omisión es la declaración, y hay test.
- **RB-8 (auditoría) no entra**, por lo mismo que en `T-014`: `LogsSensitiveActivity` es de `T-016`. Y **RB-4 / SP-1 —publicar sin cuenta activa— tampoco**: es de `T-032`, que es quien publica catálogos; la frontera ya estaba declarada desde `e85d943`.

**T-016 · Auditoría** · `AB` · 2 pts · *Requisitos: `REQ-BOF-005`*
`activity_log` con `organization_id`, `AuditEvent` con la lista cerrada, `LogsSensitiveActivity`, `AuditLogController`.
**Hecho cuando:** `properties` con antes y después; job sin actor deja `causer` nulo; fallo de auditoría no revierte la operación; una organización no ve la de otra (fuga); `member` recibe `403`. **Más los dos cierres de H0 de abajo.**

**Cierre de H0 · dos deudas que se saldan aquí.** Es la última tarea del hito y las dos son de arrastre, no de `REQ-BOF-005`:

**1. `npm run format:check` existe desde `T-003` y no lo invoca nadie.** Ni `npm run check` ni CI lo ejecutan, así que hoy **23 archivos** incumplen el `.prettierrc` que `REQ-QAX-002` declara en sus contratos de datos — cinco nuestros y dieciocho heredados. Se detectó al cerrar `T-014`, cuando un `prettier --write` reformateó archivos que nadie había tocado y hubo que revertirlo para que el commit no llevara ruido ajeno. Es la misma forma que el hallazgo de `T-013b`: una herramienta escrita para un propósito, sin un solo llamante. Se salda añadiendo `format:check` a `npm run check` y al trabajo `frontend` de CI, formateando los nuestros, y excluyendo `resources/js/components/vendor/**` con un `.prettierignore` — por el mismo motivo por el que `eslint.config.js` ya lo exime de `max-lines`: es andamiaje del starter kit en retirada y reformatearlo es ruido sobre código que se va a borrar.

**Resuelto en `T-016`, y la causa era peor de lo que decía esta nota.** El `.prettierignore` ya existía y **estaba al revés**: excluía `resources/js/components/ui/*`, que era la ruta del vendor en el starter kit original. Tras el traslado de `T-010`, `ui/` pasó a ser **nuestro** —los envoltorios de daisyUI—, así que la lista eximía lo que escribimos y formateaba lo que está en retirada. Nadie lo notó porque nada invocaba `format:check`: **una exclusión invertida y una receta sin llamante se tapan la una a la otra**, y por separado cualquiera de las dos habría saltado. Los archivos fueron **13, no 23**; la cifra de arriba se midió con la exclusión equivocada todavía puesta. Todo el reformateo fue orden de imports, orden de clases de Tailwind y unión de líneas: ningún cambio semántico.

**2. `PublishedCatalogs::exist()` pasa a fallar ruidosamente** (ver la nota de `T-014`): si la tabla `catalogs` existe y el cuerpo no se ha sustituido, lanza en vez de responder `false`. `T-024` crea esa tabla ocho tareas antes de que `T-032` rellene el cuerpo, y en esa ventana `REQ-ORG-006` SP-5 quedaría desactivado sin un solo test en rojo. **Hecho en `T-016`**, con el mensaje que nombra a `T-032` y la consulta que hay que escribir, y su test.

**Decisiones tomadas al cerrar `T-016`:**
- **El mecanismo que impide olvidar un evento sin emisor es `AuditEvent::emitter()` + un test que recorre el enum.** Cada uno de los 35 casos declara la clase que lo emite; `AuditEventTest` exige que **toda clase que ya existe** use `LogsSensitiveActivity` y **nombre su caso** (`AuditEvent::Xxx` en su código). Las que aún no existen no se comprueban —no hay sujeto— y **empiezan a comprobarse solas** el día que su tarea las escriba: `T-032` no podrá cerrar en verde con `PublishCatalogAction` escrita y sin auditar. Dos guardas más para que no se vuelva vacío: un suelo de emisores cubiertos (hoy 10, los del bloque Organización) y una comprobación de **forma** de cada cadena, porque un `App\Acions\…` con errata se comportaría igual que una tarea pendiente.
- **Los emisores se declaran como cadenas y no como `::class`,** y es lo que hace posible lo anterior: PHPStan resuelve `::class` en análisis y falla con una clase que no existe, que es el estado de 25 de las 35 líneas. Con `::class` habría habido que añadirlas de una en una según llegaran —es decir, tener la lista incompleta durante setenta tareas—, que es justo lo que se quería evitar.
- **`activity_log` pasa a ser tabla del tenant y sale de `NON_TENANT_TABLES`.** Lleva `organization_id` nullable con `INDEX(organization_id, created_at)` ([05 §4] bloque 40) y su modelo es `App\Models\AuditEntry`, que extiende el del paquete y le añade `BelongsToOrganization`. Se apunta también en `config/activitylog.php`, así que ni el helper `activity()` del paquete podría salirse del aislamiento. Dejarla en la lista de tablas exentas habría declarado por escrito lo contrario de lo que hace.
- **Sin clave foránea a `organizations`, a propósito.** Un `cascadeOnDelete` se llevaría la auditoría de una organización cerrada, y `REQ-ORG-008` RB-5 conserva sus datos: el rastro de quién aprobó qué comprobante tiene que sobrevivir **precisamente** al momento en que el negocio deja de existir, que es cuando alguien va a preguntar por él. **Efecto secundario que costó un fallo intermitente:** los dos tests de concurrencia —`LastOwnerConcurrencyTest` y `LimitEnforcerConcurrencyTest`, que confirman de verdad porque no usan `RefreshDatabase`— purgaban solo `organizations` y confiaban en la cascada. Sus entradas de auditoría se quedaban en la base contaminando el resto de la suite. Ahora purgan `activity_log` explícitamente.
- **`causer` admite los tres actores de RB-7 sin que H8 tenga que migrar.** Es el `nullableMorphs` del paquete y **no se estrecha**: un `User`, un `PlatformUser` —que no tiene modelo hasta `T-086`— y el sistema con `causer` nulo. Hay test que inserta un `causer_type` sin modelo y lo lee, porque si la columna hubiera sido una clave foránea a `users` el modelo de soporte de RB-6 habría nacido roto.
- **RB-4 se prueba sustituyendo `AuditTrail` en el contenedor.** El escritor es una clase aparte —no `final`— por eso: con la escritura empotrada en el trait, provocar un fallo exigiría romper la base a mitad de un test, y en MySQL eso se lleva por delante el aislamiento de `RefreshDatabase`. Es el mismo patrón de `PublishedCatalogs` y `RecordedReceipts`. La degradación tiene tres escalones: `properties` que no cabe → su resumen; inserción que falla → **reintento sin `properties`**, conservando quién, qué y cuándo; y si eso también falla, `report()` y seguir. Los tres van al log de errores: degradar en silencio cambiaría un problema ruidoso por uno invisible.
- **La redacción de RB-5 compara el valor crudo y redacta el saneado**, y esto era un fallo real que el test encontró: redactando antes de comparar, dos contraseñas distintas salían las dos `[redactado]`, resultaban iguales y **el campo desaparecía del diff** — una contraseña cambiada no habría dejado rastro ninguno, que es lo contrario de lo que la redacción pretende. Se redacta el valor, no el hecho. La lista se aplica por fragmento del nombre de la clave, así que `account_id`, `accountId` y `related_account_id` son la misma fuga escrita de tres formas.
- **`OrganizationCapability` gana una decimotercera: `Audit`.** RB-1 de `REQ-ORG-003` enumeró doce áreas cuando `REQ-BOF-005` no tenía pantalla, y la auditoría no es ninguna de ellas: es la vista de quién tocó todo lo demás, y RB-9 la reserva al `owner`. Va en el enum y no en un `->isOwner()` suelto porque la frontera se declara **una vez**; con el permiso decidido en el controlador, el menú habría vuelto a decidirlo por su cuenta — lo que `T-014` vino a arreglar. No cambia `D-4`: siguen siendo áreas fijas y dos roles.
- **`updated_by` de `organization_settings` no se retira.** Responde «quién lo tiene ahora»; la auditoría responde «quién lo cambió, desde qué valor y cuándo». Son dos preguntas y la segunda es la que aparece cuando un cliente discute un plazo que se aplicó con otra configuración.
- **El alta de la suscripción Free no se audita retroactivamente** (`T-011`): nace dentro del registro de la organización y no es un cambio de plan. `subscription.plan_changed` lo emitirá `ChangePlanAction` en `T-068`.
- **Un cambio de rol que no cambia nada no escribe entrada.** `ChangeTeamMemberRoleAction` ya salía antes de escribir cuando el rol pedido era el que había; auditarlo habría llenado el registro de cambios que no ocurrieron, que es peor que uno incompleto porque se lee igual de bien.
- **La vista no declara `during-suspension`.** Lo que `REQ-ORG-008` RB-3 conserva es cobrar y aprobar comprobantes; consultar quién cambió un precio en marzo puede esperar a que se regularice el pago. Y es `append-only` de verdad: hay un test que recorre el router y exige que **ninguna** ruta con «auditoria» acepte un verbo que escriba, para que un `POST /auditoria/{entry}/anular` futuro se ponga rojo en vez de colarse.
- **`.prettierignore` estaba justo al revés.** Decía `resources/js/components/ui/*`, que era la ruta del vendor en el starter kit original; tras el traslado de `T-010`, `ui/` pasó a ser **nuestro**, así que la exclusión eximía lo que escribimos y formateaba lo que está en retirada. Nadie lo notó porque `format:check` no lo invocaba nadie — que es exactamente el hallazgo que esta deuda venía a saldar. Al arreglarlo salieron **13 archivos** (no 23: la cifra anterior se midió con la exclusión equivocada) y el reformateo fue solo orden de imports, orden de clases de Tailwind y unión de líneas; ningún cambio semántico.

**Cierre de H0:** `composer check` y `composer tenancy` verdes (`make` es solo un alias y puede no estar instalado) · un negocio se registra, entra y configura ajustes y cuentas de cobro · 16 requisitos cerrados.

**Qué queda abierto de H0 y por qué** (comprobado al cerrar `T-016`, no dado por bueno):
- **`REQ-BOF-005` queda cerrado en su mecanismo, no en su cobertura.** Las nueve reglas, los siete `SP-` y la vista están implementados y probados, pero su gate pide «un test por cada evento de RB-1» y **25 de los 35 no tienen emisor todavía**: llegan con el hito de su Action. Lo que sustituye a ese test hoy es el mecanismo de `emitter()`, que obliga a conectarlos cuando existan.
- **`REQ-ORG-006` sigue con una pieza fuera de H0:** la página de la vitrina `/{org-slug}` es de `T-036`, y el cuerpo de `PublishedCatalogs::exist()` que sostiene SP-5 lo rellena `T-032`.
- **`REQ-ORG-007` igual:** RB-4 (publicar exige cuenta activa) es de `T-032` y el cuerpo de `RecordedReceipts::existFor()` es de `T-054`.
- **`REQ-INF-003`, `REQ-QAX-003` y `REQ-QAX-007` no cuentan entre los 16** y nunca debieron: el primero cierra al final de H8 con las catorce entradas del schedule, y los otros dos son criterio transversal que se cumple en cada tarea.
- **`REQ-SUB-001` tampoco:** `T-011` dejó los planes en la base, pero `/precios` lo renderiza `T-037`.

---

## H1 · Aritmética y tasa

> **Objetivo demostrable:** `Money` y el cronograma pasan sus tests de propiedad con 500 casos, y la tasa se ingesta de DolarApi.

**T-017 · `Money` y `Currency`** · `A` · 2 pts · *Requisitos: `REQ-FX-001`*
`Money` con sus 8 métodos, `Currency`, `MoneyFormatter`, `MoneyCast`, `CurrencyMismatchException`, `InvalidRateException`; tipos TS `CentsUsd`, `MoneyView`, `resources/js/lib/money.ts`.
**Depende de:** `T-019` (necesita `RateSnapshot` para la firma de `convertTo()`).

**Reordenado: `T-019` se ejecuta antes que `T-017`.** `Money::convertTo(Currency $target, RateSnapshot $rate)` nombra un tipo que declara `T-019`, y la dependencia es de un solo sentido: `RateSnapshot` no menciona `Money` (ver el bloque de contratos compartidos de [13](13-bimoneda-y-tasa-de-cambio.md)). Es el mismo motivo por el que `T-011` se adelantó a `T-010` y `T-008` a `T-006`: declarar un tipo provisional para poder escribir la firma y sustituirlo una tarea después es fabricar trabajo y arriesgarse a que el provisional sobreviva.

**T-018 · Lógica y tests de `Money`** · `B` · 3 pts · *Requisitos: `REQ-FX-001`*
**Hecho cuando:** `sum(allocateEvenly(n)) === cents` con **500 casos aleatorios**; residuo en la última parte; mezcla de monedas lanza; formateo `$1,234.56` y `Bs. 1.234,56` exactos; cast con `null` devuelve `null`; conversión con tasa ≤ 0 lanza.
**Por qué es su propia tarea:** es la base de todo el dinero del sistema. Si esto tiene un bug, lo tienen los 118 requisitos.

**T-019 · Contratos de tasa** · `A` · 1 pt · *Requisitos: `REQ-FX-002`, `REQ-FX-003`*
`RateSource`, `RateSnapshot` (con `publishedAt` **y** `fetchedAt`), `ExchangeRateProvider`, `ExchangeRate`, `ExchangeRateUnavailableException`, `MissingExchangeRateException`, `RateView`.
**Se ejecuta antes que `T-017`**, que necesita `RateSnapshot` para la firma de `Money::convertTo()`.

**`isStale()` se implementa aquí, y no es una excepción a la Fase A.** La fase separa estructura de lógica para que no queden mitades de un algoritmo a medio construir, no para forzar muñones: `RateSnapshot` es un *value object* de cuatro propiedades cuyo único método son tres líneas, y dejarlo vacío exigiría un `throw` provisional que PHPStan nivel 6 obligaría a justificar y que alguien tendría que recordar quitar. Un tipo con un método que miente es peor que un tipo completo. Lo que sí queda para `T-020` es todo lo que rodea a la ingesta: el provider real, el servicio, el job y la validación de variación.

**T-020 · `DolarApiProvider` + ingesta** · `B` · 3 pts · *Requisitos: `REQ-FX-002`*
Provider según [05 §6](05-design-tecnico.md), `ManualRateProvider`, `ExchangeRateService`, `FetchExchangeRate`, `ExchangeServiceProvider`, `ExchangeRateSeeder`, endpoint `/api/tasa`.
**Hecho cuando:** **una sola petición** HTTP trae oficial y paralelo (assert con N organizaciones); prioridad `promedio > venta > compra`; rechaza variación > 30 % y `rate <= 0`; no inserta si nada cambió; fixture de contrato del payload real; `WithoutOverlapping`.

**Añadido de alcance: `format:check` da un falso positivo en un host Windows.** `resources/js/ssr.jsx` lo incumple solo fuera del contenedor, y no es un problema de formato: `prettier-plugin-organize-imports` emite CRLF en el bloque de imports en Windows, el archivo es LF en git y `.gitattributes` fuerza `eol=lf`. Comprobado que **no se arregla con `--end-of-line lf`**: la normalización de Prettier no alcanza la salida del plugin. Dentro del contenedor —que es lo que corre CI— pasa en verde. Se resuelve añadiendo `resources/js/ssr.jsx` al `.prettierignore` con ese motivo escrito: es el punto de entrada de SSR heredado del starter kit, nadie lo edita, y dejar un aviso permanente en la receta que acabamos de cablear es la forma más rápida de que la gente aprenda a ignorarla. Detectado al cerrar `T-019`.

**Cerrada** (`3fc80c2`). Dos errores de zona horaria encontrados y arreglados en el camino, los dos ahora en [05 §4] y [05 §6.2]: `published_at` llegaba en UTC y se guardaba desplazado, con lo que la deduplicación de RB-3 no volvía a coincidir y cada vuelta del job insertaba la misma cotización otra vez; y `fetched_at` —el primer valor del proyecto que escribe MySQL— salía cuatro horas por delante porque la sesión heredaba `SYSTEM`. También se corrigió que una cotización inutilizable tumbara el payload entero: [05 §6.2] dice «se descarta» en singular. **El binding del provider no es singleton** y eso pasó a ser contrato (`REQ-FX-003` RB-5b).

**T-021 · Fuente de tasa por organización** · `B` · 2 pts · *Requisitos: `REQ-FX-003`*
`UpdateExchangeRateSettingsAction`, UI en ajustes con las fuentes disponibles y su antigüedad.
**Hecho cuando:** **dos organizaciones con fuentes distintas proyectan importes distintos** para el mismo producto; `manual` no inserta en `exchange_rates`; `member` recibe `403`.

**La lectura ya existe: esta tarea es el lado de escritura.** `T-020` dejó `ExchangeRateService::current($organization)` resolviendo la fuente configurada y `ManualRateProvider` leyendo `exchange_rate_manual` de esa organización, porque la firma de RB-2 lo pedía. Lo que falta es el `Action`, la ruta `PUT /backoffice/ajustes/tasa` y la pantalla — no rehagas la resolución.

**Añadido de alcance: la guarda del 30 % necesita una referencia reciente** (`REQ-FX-002` SP-2b, recién escrito). `FetchExchangeRate::isPlausible()` compara contra la última fila sin mirar su antigüedad, y como el rechazo no persiste nada, la referencia no avanza: una caída larga con movimiento real de la tasa deja la ingesta vetada para siempre. Se realinea aquí —son unas líneas en el job y sus dos tests— porque es la tarea siguiente del módulo y su agente ya tiene el dominio de tasa delante. Con ello desaparece también el paso manual de actualizar la semilla al desplegar ([05 §6.4]).

**Cerrada** (`429d97e`). `REQ-FX-003` cerrado. Cuatro cosas que conviene dejar dichas:

**La ruta aparte no lleva policy aparte, y el prompt daba por hecho que sí.** `PUT /ajustes/tasa` se separa de `PUT /ajustes` por la **operación** —la fuente y la tasa manual son un par que solo tiene sentido guardado junto (SP-1), y la pantalla necesita datos propios—, no por el permiso: `OrganizationCapability::Settings` nombra la tasa explícitamente, así que la capacidad y la policy son las mismas que las de los demás ajustes. Es distinto de la identidad, que sí es otra capacidad (`Branding`). Queda escrito en la ruta y en la acción para que no se lea como un descuido.

**Y la acción delega en `UpdateSettingsAction` en vez de escribir.** Un segundo camino de persistencia sobre las mismas dos claves habría duplicado transacción, auditoría e invalidación de caché, y dos caminos que resuelven la misma pregunta divergen. Consecuencia deliberada: el evento auditado es `SettingsUpdated` y **la lista de 35 de `REQ-BOF-005` no crece**; un evento específico de tasa habría sido el 36.º para un cambio que ya tiene emisor.

**Salir de `manual` no borra la tasa manual:** la clave se **omite** del payload en lugar de enviarse a `null`, porque SP-6 dice que quien vuelve a elegirla la recupera tal cual. Enviar `null` habría sido un borrado que nadie pidió, disfrazado de «guardar».

**Divergencia encontrada y asignada:** las tres etiquetas de las fuentes quedaron en una constante del `.tsx`, que es el segundo censo contra el que avisa el contrato (ver la nota de los contratos compartidos de [13](13-bimoneda-y-tasa-de-cambio.md)). **La realinea `T-022`**, que necesita las mismas cadenas para el sello. En la misma línea, `publishedAgo()` —la antigüedad en palabras— vive hoy dentro de `ExchangeRateForm.tsx` y `RateStamp` la necesita entera: se muda con ella.

**T-022 · Proyección bimoneda y toggle** · `B` · 3 pts · *Requisitos: `REQ-FX-004`, `REQ-FX-005`*
`ProjectsMoney`, `useCurrencyDisplay`, `MoneyLabel`, `CurrencyToggle`, `RateStamp`.
**Hecho cuando:** los dos modos con formato exacto; **sin tasa muestra solo USD y deshabilita el toggle**; `localStorage` inaccesible no rompe; cookie corrupta cae al default; la preferencia sobrevive al cambio de organización.

**No hay ninguna página con precios todavía**, y eso no la convierte en una tarea de andamio: `ProjectsMoney` es el único sitio por el que un importe sale al cliente, y se escribe entero con sus tests antes de que H2 lo consuma, igual que `Money` se escribió en `T-018` sin apartados. Lo que sí hace falta es decidir **por dónde llega la tasa a las page props** —`HandleInertiaRequests`, que es el único punto que toda superficie atraviesa— y dejar el sello y el toggle montados en la única pantalla que hoy enseña tasa, la de ajustes, en lugar de inventarse una de muestra.

**`currency_toggled` (RB-7) no se emite aquí.** El bus de telemetría es `T-084`, en H8, y esta tarea **no** deja un emisor que trague eventos en silencio; ver la nota de `REQ-FX-004` en [13](13-bimoneda-y-tasa-de-cambio.md).

**Y hereda dos realineados de `T-021`:** el censo de etiquetas de `RateSource` y el traslado de `publishedAgo()`.

**Cerrada** (`0913525`). `REQ-FX-004` y `REQ-FX-005` cerrados, y con ellos la degradación entera. Lo que trajo y no estaba escrito:

**`CurrentRate`, una resolución de tasa por respuesta.** La tasa se usa dos veces en la misma página —la pinta el sello y la multiplica cada importe—, y con dos consultas basta que la ingesta inserte entre ellas para estampar una tasa distinta de la que multiplicó. Ese fallo no tiene síntoma: no hay excepción ni log, hay un cliente que multiplica a mano y no le da. El binding es `scoped()` y no `singleton()`, que es la misma trampa de `REQ-FX-003` RB-5b vista desde el otro lado: el memo tiene que morir con la petición o el worker de colas sirve la tasa de hace media hora para siempre.

**La cookie de la preferencia sale del cifrado de Laravel**, y es consecuencia de RB-6 —la escribe el navegador, así que llega sin cifrar—, no una excepción de seguridad. Está razonada en [13](13-bimoneda-y-tasa-de-cambio.md) junto a `REQ-FX-004`, con lo que la justifica: **el precedente no es «esta cookie no es sensible», es «el cliente es quien la escribe»**.

**El sello escribe su hora en la zona del negocio**, que viaja en las page props, y compone `DD/MM HH:mm` sin delegar el patrón en el *locale* (`es-VE` da `18/9` o `18/09` según la versión de ICU). Las dos decisiones están en [13](13-bimoneda-y-tasa-de-cambio.md) bajo `REQ-FX-005`.

**Tercer censo suelto del módulo, y van tres:** `CurrencyDisplay` entra como enum de PHP y `SettingKey` valida contra él en vez de contra su copia de las dos cadenas — después de `RateSource` en `T-019` y `RateSource::platform()` en `T-021`.

**`T-023` se reubica a H4, y no se renumera.** Pedía «las columnas de snapshot en la migración de `payments`», y esa migración **no existe hasta `T-054`**: `T-039` trae `layaways` e `installments`, y `payments` cuelga de `receipts`, que es de cobranza. Adelantarla exigiría una tabla sin sus claves ajenas durante dos hitos, capaz de guardar filas huérfanas de un apartado que todavía no se puede crear. Es el criterio de `T-014` con la vitrina y el de `T-006` con los middlewares: **no se construye contra una tabla que no existe**. `REQ-FX-006` se cierra donde corren sus tests de gate, que es H4 — su regla verificable es «cambiar la tasa no mueve `paid_cents` ni `remaining_cents`», y eso necesita pagos.

**Cierre de H1:** la aritmética monetaria está probada y la tasa entra sola · 5 requisitos cerrados.

---

## H2 · Catálogo

> **Objetivo demostrable:** un negocio carga productos, arma un catálogo y **el enlace público funciona**: un desconocido lo abre, busca, filtra y ve precios en bimoneda.

**T-024 · Migraciones bloque 5** · `A` · 2 pts
Las 8 tablas de catálogo, con `FULLTEXT` y el `UNIQUE(slug)` global de `catalogs`.

**Esta tarea arma la guarda de `PublishedCatalogs::exist()` y tiene que cerrarla en el mismo commit.** `T-016` la dejó lanzando en cuanto `catalogs` exista con el cuerpo sin sustituir, y eso es exactamente lo que pasa aquí: desde la migración, cualquier cambio de slug revienta y la suite se pone roja. Es lo que se buscaba —el `false` silencioso habría desactivado `REQ-ORG-006` SP-5 durante ocho tareas—, pero significa que **la tarea que crea la tabla es la que rellena el cuerpo**, igual que `T-054` con `RecordedReceipts`. El cuerpo necesita `Catalog`, así que `T-024` **adelanta el modelo mínimo** —tabla, `BelongsToTenant` y nada más— y `T-025` lo termina con sus casts, sus relaciones y `CatalogVisibility`. La alternativa era escribir el filtro del tenant a mano en un `DB::table()`, que es la línea que `tenancy:audit` existe para que nadie escriba.

**Y hay que decidir qué pasa con los dos pivotes.** `product_tag` y `catalog_product` no llevan `organization_id` en [05 §4](05-design-tecnico.md), así que `tenancy:audit` los va a reclamar: o se declaran en `GlobalUniqueExceptions::tables()` con su motivo escrito, o llevan la columna. No es trámite —`catalog_product` se consulta de forma directa y multi-organización para ordenar el feed (`REQ-CAT-023` RB-3)—, y la respuesta hay que razonarla, no silenciarla.

**Cerrada** (`8a5a5b8`). Las ocho tablas, el relevo cerrado y tres cosas que dejar dichas:

**Los dos pivotes quedaron declarados, no dotados de columna**, y la lista blanca pasó de dos tablas a cuatro con la distinción escrita: puentes de plataforma frente a pivotes entre tablas ya aisladas. El razonamiento está en [05 §4.1](05-design-tecnico.md) y en la propia clase; lo que decidió `catalog_product` —el caso con argumento en contra, porque el feed lo lee cruzando organizaciones— es que ahí la columna no filtraría nada y quedaría como un dato derivable de `catalog_id` capaz de discrepar de él.

**Tres índices que [05 §4] no listaba**, todos añadidos con su motivo y ya recogidos allí: `UNIQUE(organization_id, name)` en `categories` y `tags` —que es una regla de `REQ-BOF-023` RB-3 que solo la base puede garantizar bajo concurrencia, y la nota de §4 explica por qué—, `INDEX(organization_id, position)` para el orden manual y `INDEX(organization_id, category_id)` para el listado filtrado por categoría.

**Un catálogo expirado sigue contando como publicado** para `PublishedCatalogs::exist()`: su `published_at` no es nulo y su enlace circuló igual, así que renombrar el slug rompería los enlaces compartidos lo mismo. Lo que devuelve un catálogo a borrador es despublicarlo (`REQ-CAT-022` RB-7), y eso sí vacía la columna.

**T-025 · Modelos y enums de catálogo** · `A` · 2 pts · *Requisitos: `REQ-CAT-007`*
`Category`, `Tag`, `Product`, `ProductSpecification`, `Catalog` con el trait, `ProductStatus`, `CatalogVisibility` con sus métodos derivados.
**`Catalog` llega medio escrito de `T-024`** (ver su nota): esta tarea lo completa, no lo crea. Lo que tiene hoy es tabla, `BelongsToOrganization`, `$fillable` y los casts de fechas que el relevo necesitaba; le faltan `CatalogVisibility`, los estados derivados y sus relaciones.

**Cerrada** (`53dbf4f`). Tres cosas que conviene no perder:

**Un `published_at` futuro cuenta como publicado en un catálogo y como programado en un producto.** La asimetría es de la spec —`REQ-CAT-022` deja la publicación programada fuera de alcance y `REQ-CAT-007` RB-5 la exige para el producto— y está ahora escrita en [12](12-catalogos-curados.md) junto al enum, con el orden de evaluación completo, porque es de las que se copian mal de un lado al otro.

**Las relaciones entre catálogo y producto ni filtran ni ordenan.** No filtran por estado porque el archivado conserva su relación (`REQ-CAT-007` RB-7) y quien omite es la consulta pública; no ordenan por `position` porque el orden curado es el **defecto** de la vista (`REQ-CAT-006` RB-5) y cablearlo en la relación dejaría `price_asc` ordenando por posición y desempatando por precio.

**Una factory anidada se resuelve antes de que el contexto del tenant esté fijado**, así que `ProductSpecificationFactory` no trae `product_id` por defecto: el defecto habría creado el producto en una organización y su ficha en otra, en silencio. La forma prevista es `Product::factory()->has(ProductSpecification::factory(), 'specifications')`.

**`Catalog::subscriptions()` no es de `T-025` aunque su docblock lo anunciara:** `catalog_subscriptions` es del bloque 6 y la crea `T-073`, que cierra también `REQ-CAT-022` RB-9.

**Quien escribe en un pivote exento valida que sus dos extremos son de la misma organización.** Es la otra mitad del argumento con el que `T-024` declaró `product_tag` y `catalog_product` sin `organization_id`: el trait del tenant lanza cuando alguien **asigna a mano** un `organization_id` ajeno, pero no cuando una clave foránea apunta a una fila de otra organización —esa fila se guardaría con la organización activa—. Hoy lo contiene la lectura, porque el *global scope* hace que la fila ajena no exista desde ningún lado, y la spec pone la guarda de escritura en la `Action` (`REQ-ORG-002` RB-7, SP-5). Le toca a **`T-027`** para `product_tag` y a **`T-031`** para `catalog_product`, cada una con su test de relación cruzada.

**T-026 · Taxonomía** · `B` · 2 pts · *Requisitos: `REQ-BOF-023`*
CRUD de categorías y etiquetas, `ReassignCategoryProductsAction`, `CategoryInUseException`.
**Hecho cuando:** borrado con productos rechazado; **mismo nombre en dos organizaciones permitido**; creación inline reutiliza; orden persistido.

**Cerrada** (`bf15d5a`). `REQ-BOF-023` cerrado. Cuatro cosas que dejar dichas:

**`TaxonomyByName` está escrita y no tiene llamante: es de `T-027`.** Resuelve la creación al vuelo de RB-8 reintentando el par leer-crear —el choque puede ser del nombre o del `slug`, y los dos se resuelven releyendo o desambiguando—, y vive en el dominio de la taxonomía porque la regla es de `REQ-BOF-023` y la carrera se explica junto al índice que la hace posible. **El formulario de producto la usa; no escribe su propia reutilización.** La asimetría que sostiene: el mismo nombre en el CRUD de `/categorias` es `422` (SP-2), y en la creación al vuelo se reutiliza (SP-6).

**Tres conteos distintos de productos por categoría, y cada uno mide lo suyo.** El visible de RB-7 incluye `draft` y `archived` pero no lo borrado lógicamente; la guarda del borrado cuenta `withTrashed()`, porque la FK `restrict` cuenta filas y no productos visibles; y el bloqueo del `slug` de SP-3 cuenta los `published` por **estado** y no por fecha, porque un `published_at` futuro ya está comprometido. Que el primero excluya lo que el segundo cuenta es deliberado: la cifra de la pantalla tiene que explicar el `422` del borrado, y por eso `ReassignCategoryProductsAction` mueve **también** los de la papelera — si los dejara atrás, «reasignar y luego borrar» fallaría con un error de base después de que la persona hiciera exactamente lo que se le pidió.

**RB-2 dice «arrastre» y lo que hay son botones de subir y bajar.** Es la única parte de `REQ-BOF-023` por debajo de la letra, y está declarada: no hay ninguna dependencia de *drag & drop* en el proyecto y la API nativa del navegador no responde al dedo, que es con lo que se usa este backoffice. Lo que sí quedó con su forma definitiva es el contrato de guardado —`PATCH /categorias/orden` con la lista entera de identificadores—, así que **`T-031` cambia el gesto y no el endpoint** (ver su nota).

**`OrganizationPolicy::manageProducts()`** entra aquí, para que el menú y las rutas del área de inventario pregunten por la capacidad `Products` en vez de volver a decidir el reparto de `REQ-ORG-003` RB-1 cada una por su cuenta.

**T-027 · CRUD de productos** · `B` · 5 pts · *Requisitos: `REQ-BOF-020`*
`ProductData`, `UpsertProductAction`, `CoherentFinancingTerms`, requests, formulario con previsualización del plan en vivo.
**Hereda dos encargos:** llamar a `TaxonomyByName` para la creación al vuelo (ver la nota de `T-026`) y validar que las etiquetas que adjunta son de la misma organización, que es la guarda de escritura de `product_tag` que `REQ-ORG-002` RB-7 pone en la `Action`.
**Hecho cuando:** matriz de las **6 reglas de coherencia** con casos límite; **SKU duplicado en otra organización permitido**; slug inmutable tras publicar; más de 2 decimales rechazado; cambio de precio auditado; defaults de la organización aplicados.

**Cerrada** (`cc708ec`). `REQ-BOF-020` cerrado en lo que es suyo; las cinco piezas repartidas siguen con su dueño y están abajo. Lo que trajo:

**La previsualización del plan no abrió endpoint.** `FinancingPlan` reparte con `Money::allocateEvenly()` —el mismo que usará `ScheduleGenerator`— y el resultado viaja como prop de las rutas de crear y editar, refrescada con una recarga parcial de Inertia (`only: ['plan']`). Lo que impide que alguien reimplemente la división en el `.tsx` no es el comentario: **al cliente no le llega ninguna cifra con la que dividir**, solo el `MoneyView` ya formateado. Es el patrón para el resto de las previsualizaciones en vivo de H2.

**`ChangeProductStatusAction` queda con su punto de extensión escrito:** `archive()` público e idempotente, y un `transitionTo()` privado que escribe la columna y emite `ProductStatusChanged` una sola vez para todas las transiciones. `T-030` añade **delante** la matriz y la completitud de RB-5; no duplica el archivado ni mueve el evento.

**La taxonomía entra en dos mitades que se resuelven al revés:** los identificadores elegidos se resuelven por consulta del tenant y **un id ajeno lanza** —traducido a `422` junto al campo, porque descartarlo en silencio guardaría el producto con menos etiquetas de las pedidas y sin nada que lo explicara—; los nombres escritos al vuelo pasan por `TaxonomyByName`. Todo dentro de la misma transacción, así que una etiqueta ajena deshace también la categoría que se acababa de crear.

**La unicidad del SKU y del `slug` se comprueba `withTrashed()`** (ver la convención nueva de «Cómo leer este plan»).

**Lo que quedó a medias, con dueño:** la advertencia de SP-6 al bajar el `stock` por debajo de lo reservado es de **`T-044`** —hoy no hay reservas que contar— y la segunda mitad de RB-6 —que cambiar el precio no altere apartados vivos— solo es verificable en **`T-045`**, cuando exista el *snapshot* de `REQ-LAY-002`. Las dos están anotadas en sus tareas.

**T-028 · Ficha técnica dinámica** · `B` · 3 pts · *Requisitos: `REQ-BOF-021`, `REQ-CAT-004`*
`SyncSpecificationsAction`, `SpecificationBuilder`, `useSpecificationRows`, `SpecificationTable`, `SpecificationGroupsQuery`.
**Hecho cuando:** posiciones contiguas tras reordenar; reemplazo transaccional con rollback; 51 filas rechazadas; **autocompletado de grupos acotado a la organización** (fuga); escapado de HTML verificado.

**`ProductDetailResource` no es de aquí: es de `T-035`.** De `REQ-CAT-004` sí es de esta tarea todo lo demás —la agrupación de RB-2 y RB-3 con su comparación *case-insensitive*, y `SpecificationTable` con sus tests de Vitest, incluido el de XSS—, y la agrupación se escribe **donde `T-035` pueda llamarla** en vez de dentro de un *resource* que todavía no existe. Es el mismo reparto que `T-022` hizo con `MoneyLabel`: la pieza y su prueba ahora, la página que la monta en su tarea.

**Cerrada** (`a1785c6`). `REQ-BOF-021` cerrado y `REQ-CAT-004` en la parte que no necesita la página. Cuatro cosas:

**La agrupación vive en `SpecificationGroups`, en el dominio, y tiene dos llamantes**: la ficha pública de `T-035` y `SpecificationGroupsQuery`, que necesita la **misma** comparación insensible a mayúsculas para no sugerir «Motor» y «motor» como dos secciones. El rótulo «General» lo pone la pantalla y no el dominio: con el grupo en `null`, una ficha sin secciones no se confunde con una que tenga una sección llamada así. El orden entre grupos es por **primera aparición**, porque la spec no lo fija y alfabético habría sido inventarse un criterio.

**El reemplazo cambia los `id` de todas las filas**, también las que no cambiaron de texto, y hay un test que lo afirma. De ahí que el `id` de una fila no sea una promesa que la pantalla pueda guardar, y que el cliente ordene con claves locales (SP-6). Las inserciones van **fila a fila por el modelo** y no con un `insert()` en lote: el relleno de `organization_id` lo hace el evento `creating` del trait, y una inserción masiva se lo salta — cincuenta consultas como mucho, que es el tope de RB-3, a cambio de que la tabla siga siendo del tenant por construcción.

**`ProductData::$specifications` distingue `null` de `[]`:** `null` es «no la toques» y `[]` borra la ficha entera (SP-3). La distinción existe para que un llamante que no gestiona la ficha —un *seeder*, una importación— no la borre sin pedirlo, y se produce con `sometimes` en la validación.

**El arrastre de RB-1 tampoco está**, por lo mismo que en `T-026`: son botones de subir y bajar, el orden viaja entero y `T-031` cambia el gesto. Ver su nota.

**T-029 · Imágenes de producto** · `B` · 3 pts · *Requisitos: `REQ-BOF-022`, `REQ-CAT-005`*
medialibrary con las 3 conversiones, `TranscodeProductImage`, `SyncImageOrderAction`, `RealMimeType`, `ImageUploader`, `ProductGallery`.
**Hecho cuando:** 3 conversiones en cola; MIME falso rechazado; borrar la principal promueve la siguiente; **la ruta física incluye el `organization_id`**; **el producto sabe decir si tiene imágenes**, que es la pieza que `T-030` necesita para RB-5.

**«Publicado sin imágenes rechazado» no se prueba aquí: es de `T-030`.** La completitud de `REQ-BOF-020` RB-5 —imagen, categoría, descripción y condiciones válidas— se verifica **entera y en un solo sitio**, que es la transición a `published`, y esa transición la escribe `T-030`. Repartir la comprobación entre la tarea de las imágenes y la de los estados dejaría dos listas de requisitos para publicar, y la que se quede corta no falla: publica de menos o de más, en silencio. Lo que sí es de esta tarea es que el producto sepa contestar la pregunta.

**T-030 · Estados de publicación** · `B` · 2 pts · *Requisitos: `REQ-CAT-007`*
`ChangeProductStatusAction`, scopes, `ProductNotAvailableException`.
**Hecho cuando:** matriz de transiciones; **la completitud de RB-5 con sus cuatro condiciones** (`IncompleteProductException` con la lista de lo que falta); `draft`/`archived` dan `404`; **la relación con el catálogo se conserva al archivar**; `published_at` futuro no se lista; publicado en 0 catálogos no es error.
**`ChangeProductStatusAction` llega medio escrita de `T-027`**, que necesita el archivado para su `POST /productos/{product}/archivar` (`REQ-BOF-020` RB-10) y lo escribe ahí en vez de abrir un segundo camino que cambie `status`. Esta tarea añade el resto de la matriz y la completitud; no duplica el archivado ni lo mueve.

**T-031 · Composición de catálogos** · `B` · 3 pts · *Requisitos: `REQ-CAT-023`*
`SyncCatalogProductsAction`, `CatalogProductSorter`, `useSortableProducts`.
**Hecho cuando:** posiciones contiguas desde 1; **`created_at` del pivote NO cambia al reordenar**; deduplicación; producto de otra organización rechazado (fuga); vaciar un catálogo publicado rechazado.
**Y trae el arrastre que `T-026` y `T-028` dejaron pendiente** (`REQ-BOF-023` RB-2 y `REQ-BOF-021` RB-1): con `useSortableProducts` escrito, la lista de categorías y las filas de la ficha técnica cambian el gesto —hoy son botones de subir y bajar— **sin tocar su endpoint ni su payload**, que en los dos casos ya viajan enteros en su orden. Son las dos únicas partes de H2 que quedaron por debajo de la letra, y las dos están declaradas en sus requisitos.
**Aquí también va la guarda de escritura de `catalog_product`** (`REQ-ORG-002` RB-7, SP-5): validar en la `Action` que el producto y el catálogo son de la misma organización, que es lo que sostiene que el pivote no lleve `organization_id`.

**T-032 · Publicación de catálogos** · `B` · 3 pts · *Requisitos: `REQ-CAT-022`, `REQ-BOF-024`*
`PublishCatalogAction`, `UnpublishCatalogAction`, `UniqueCatalogSlug`, `SlugLockedException`, `ShareLinkField`, CRUD y listado con badge de visibilidad.
**Hecho cuando:** un test por cada uno de los **6 requisitos de publicación**; slug único global con desambiguación entre organizaciones; slug inmutable tras publicar; despublicar da `404` y expirado `410`; sello de plataforma presente en Free.

**T-033 · Vista pública del catálogo** · `B` · 5 pts · *Requisitos: `REQ-CAT-021`*
`CatalogShowController`, `CatalogViewResource`, `CatalogFacetsQuery`, página `catalog/CatalogShow.tsx`, `CatalogLayout`, paginación por cursor, OG tags.
**Hecho cuando:** `unlisted` accesible, `draft` → `404`, `expired` → `410`; **el tenant se resuelve del catálogo y todas las consultas quedan filtradas** (fuga con 2 orgs); productos `draft` omitidos; orden curado por defecto; sin `count(*)` en la paginación.

**Aquí se enchufa el refresco de cinco minutos de la tasa** (`ASU-FX-03`, `REQ-FX-002` RB-5). El endpoint `GET /api/tasa` existe desde `T-020` y **no tiene llamante**: `T-022` no lo cableó porque `@tanstack/react-query` sondeando cada cinco minutos en un backoffice sin precios es maquinaria sin consumidor, y esta es la primera página donde una pestaña abierta se queda con una tasa vieja delante de un precio. Lo que el refresco actualiza es **el sello**, no los importes: vienen formateados del servidor y se reproyectan en la siguiente navegación Inertia (`REQ-FX-004` SP-4).

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
**Y la advertencia que `T-027` dejó a medias:** `REQ-BOF-020` SP-6 —bajar el `stock` por debajo de lo ya reservado **se permite, con aviso**— tiene hoy solo la primera mitad, porque no había reservas que contar. El aviso es de aquí (`REQ-LAY-003` SP-2), y va en el formulario de producto que ya existe.

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
**Y las seis columnas del snapshot de tasa** (`REQ-FX-006`): `amount_cents_usd`, `declared_currency`, `declared_amount`, `rate_applied DECIMAL(18,8)`, `rate_source` y **`rate_published_at`** — no `rate_fetched_at`, ver la nota de `REQ-FX-006` en [13](13-bimoneda-y-tasa-de-cambio.md). Venían de `T-023`, que no podía escribirlas porque esta tabla nace aquí.

**Y rellenar `RecordedReceipts::existFor()` (`T-015`), en esta misma tarea.** `REQ-ORG-007` RB-6 —una cuenta de cobro con comprobantes no se borra, se desactiva— está escrita, probada y en producción desde `T-015` contra un colaborador que hoy responde que ninguna cuenta tiene comprobantes, porque la tabla no existe. **El cuerpo pasa a ser `Receipt::query()->where('payment_account_id', $account->getKey())->exists()`**, que va por el global scope del tenant, y no cambia nada más: ni la acción, ni la ruta, ni el mensaje, ni el test. La clase no es `final` por eso. Si se deja para después, la tarea no compila: la guarda **lanza** en cuanto `receipts` existe y el cuerpo sigue sin sustituirse, que es justo lo contrario del `false` silencioso que `T-024` habría dejado con `PublishedCatalogs`.

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

**T-023 · Snapshot inmutable de tasa** · `B` · 1 pt · *Requisitos: `REQ-FX-006`*
La inmutabilidad del snapshot y el cierre del requisito: nada actualiza `rate_applied`, `rate_source` ni `rate_published_at` (RB-3), y cambiar la tasa **no** dispara ningún recálculo (RB-7).
**Hecho cuando:** las 6 columnas existen con `rate_applied` en `DECIMAL(18,8)`; pago en VES congela la tasa; **subir la tasa después no mueve `paid_cents` ni `remaining_cents`**; pago en USD guarda `rate_applied = 1`; el historial muestra el par (USD, declarado + tasa) sin reconvertir.

**Reubicada desde H1** (ver la nota al cierre de H1). Va **después** de `T-062` y no antes: la afirmación que cierra `REQ-FX-006` es que un pago ya conciliado no cambia cuando la tasa sube, y eso solo se puede probar cuando existen los dos caminos que crean pagos —aprobación y registro manual—. Escribir sus columnas en H1 habría sido una migración sin tabla; escribir sus tests antes de `T-058`, un test sin sujeto.

**T-063 · Reasignación de pagos** · `B` · 2 pts · *Requisitos: `REQ-PAY-009`*
`ReallocatePaymentAction`, `AllocationEditor`.
**Hecho cuando:** suma distinta rechazada; cuota ajena rechazada; sobrepago de cuota rechazado; **balance total invariante**; salir de `completed` con el plan en el techo se permite.

**T-064 · Comando de reconciliación** · `B` · 2 pts · *Requisitos: `REQ-PAY-008`*
`ReconcileLayaways` (comando + job) con `--fix` y `--organization`.
**Hecho cuando:** detecta y corrige una discrepancia inyectada; `--organization` acota; sin `N+1`; **el tenant se limpia entre lotes**; terminal no se corrige sin `--fix`.

**T-065 · Cobranza, clientes y dashboard** · `B` · 5 pts · *Requisitos: `REQ-BOF-040`, `REQ-BOF-041`, `REQ-BOF-042`, `REQ-BOF-044`*
`CollectionFilters`, `CollectionPreset`, `CustomerDirectoryQuery`, `CustomerShowController`, `DashboardMetricsQuery`, `DashboardWarningsQuery`, páginas y `MergeCustomersAction` (`REQ-IDN-006`).
**Hecho cuando:** un test **por cada uno de los 6 presets** en los límites exactos de fecha; **la vista y la exportación devuelven el mismo conjunto** (equivalencia, aunque la exportación llegue en T-070); búsqueda por los 4 criterios con normalización; sin `N+1`; **el perfil no filtra actividad en otras organizaciones**; cada uno de los **7 warnings** se dispara solo en su condición; el balance del perfil coincide con el del portal.

**Cierre de H4:** ciclo completo apartar → pagar → conciliar → cobrar · 16 requisitos cerrados · **el producto ya es usable**.

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

**Hay emisores esperándote desde hitos anteriores, y el primero es `currency_toggled`.** `useCurrencyDisplay` existe desde `T-022` (H1) y cambia la moneda sin emitir nada, a propósito: el bus no existía y un emisor de mentira no se distingue de uno conectado (`REQ-FX-004` RB-7, con su nota en [13](13-bimoneda-y-tasa-de-cambio.md)). El «test por evento» de esta tarea es lo que obliga a recorrer los quince y encontrar a los que ya tienen sitio donde vivir.

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
| `FX` | 6 | T-017…T-022, T-023 (en H4) |
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
