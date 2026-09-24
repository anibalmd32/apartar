# Módulo 91 — Estándares, pruebas y definición de hecho (`QAX`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Asunciones aplicadas: `ASU-QAX-01` … `ASU-QAX-07`
> Requisitos: `REQ-QAX-001` … `REQ-QAX-008`
>
> Este módulo es **normativo para todos los demás**: define qué significa "hecho" en este proyecto.
>
> **Efecto del pivote:** entra `REQ-QAX-008`, el **gate de aislamiento multi-tenant**. Es el único gate del proyecto que no admite excepciones ni deuda técnica: una fuga entre organizaciones no es un bug, es el fin del producto.
>
> **`indexion` descartado.** `REQ-QAX-005` y `REQ-QAX-006` se reescribieron: la conformidad se verifica con Pest, Vitest, PHPStan, `tenancy:audit` y revisión de PR. Detalle en [02-spec-consolidada.md §8](02-spec-consolidada.md).

---

### REQ-QAX-001 · Estándares de código backend

**Historia de usuario**
> Como equipo, queremos un backend con reglas de arquitectura verificables, para que el código siga siendo mantenible cuando crezca.

**Contratos de datos**
- `pint.json` (preset `laravel`), `phpstan.neon` (Larastan), `composer.json` con los scripts `lint`, `analyse`, `test`.

> **`composer check` corre `test:coverage`, no `test`, y es por tiempo de sesión.** Encadenar `test` y luego el gate de cobertura aparte recorre la suite **dos veces**, la segunda instrumentada con pcov; una sola pasada con cobertura cuesta un 20-40 % más que la pasada limpia, frente al 100 % que cuesta repetirla. El gate de `REQ-QAX-003` RB-1 sigue invocándose en cada vuelta y en CI, que es lo que lo mantiene vivo. Corregido al medir por qué las sesiones se alargaban conforme crecía la suite. **Con la suite en paralelo (`T-003b`) el sobrecoste de la cobertura cayó a 32 s sobre 256**, así que la pregunta de si merece la pena dejó de tener dos lados.
>
> **La suite corre en paralelo desde `T-003b`, y hay dos tests que no pueden.** `pest --parallel` da a cada proceso su propia base de datos; los de `tests/Feature/Concurrency` **no caben ahí** —abren dos conexiones sobre filas confirmadas, así que no pueden usar `RefreshDatabase` y barren a mano lo que siembran— y tienen su propio *testsuite*, `Concurrency`, que corre en serie después. Lo que producían mezclados con los demás no era un fallo suyo sino **un fallo en otro archivo**, intermitente y ajeno: era el *deadlock* al sembrar planes que se arrastraba desde `T-030`, y con el aislamiento desapareció. Quien escriba el siguiente test de concurrencia lo pone en esa carpeta, y si lo deja fuera se lo dice `ConcurrencyTestsAreIsolatedTest`.
>
> **Y desde `T-034` esa ronda en serie es «lo que confirma de verdad», no solo concurrencia.** El índice `FULLTEXT` de InnoDB solo ve filas confirmadas, así que dentro de la transacción de `RefreshDatabase` todo `MATCH` devuelve cero —medido en el contenedor—, y el test de la búsqueda **pasa en verde** porque la degradación a `LIKE` lo recoge: prueba la mitad que no dice su nombre. `CatalogFullTextSearchTest` vive por eso en esa carpeta. La regla, que `phpunit.xml` explica en su sitio: **lo que dependa de lo que el motor hace en el `COMMIT`** —texto completo, bloqueos reales, disparadores, restricciones diferidas— va ahí, y se comprueba midiendo en el motor, no deduciendo.
>
> **La suite no tolera que nada más toque el árbol mientras corre, y esto costó una pasada entera de descubrir.** Lanzar `composer check` y `npm run check` a la vez produce ~25 fallos que no son del código: `vite build` escribe en `public/` mientras `Storage::fake('public')` borra directorios. Medido en `T-032`, donde costó veinte minutos averiguarlo. **Las dos recetas se corren una detrás de otra**, nunca en paralelo, y tampoco con el *dev server* de Vite escribiendo. Desde `T-003b` ya no hace falta acordarse: las dos cogen el mismo cerrojo (`storage/framework/gate.lock`) y la segunda se niega a arrancar diciendo por qué.
>
> **Y los gates baratos van primero.** `composer test:quality` tarda unos cincuenta segundos y contiene reglas estructurales que no se adivinan —entre ellas que cada clase de `app/Domain` y `app/Actions` tenga su `<Clase>Test`—; descubrirlas al final de una pasada completa cuesta la pasada entera. El orden que sale más barato es: la carpeta que se toca → `composer test:quality` → `composer check` → `composer tenancy` → `npm run check`. **`check` no lo repite por dentro** a propósito: ya corre dentro de su suite, y duplicarlo serían cincuenta segundos en cada vuelta para adelantar un fallo que el paso anterior ya adelantó. En CI sí es un paso propio, porque allí no hay nadie que lo lance antes.
>
> **El gate de cobertura va por `vendor/bin/pest --coverage`, nunca por `artisan test --coverage`.** Descubierto en `T-003b`: `php artisan test --parallel --coverage --min=85` termina **en verde, sin tabla y sin aplicar el umbral**, y se comprueba en un segundo pidiéndole `--min=99.9`, que también sale con código 0. No es cosa del paralelo: en serie hace lo mismo. Es la forma más peligrosa que puede tomar un gate —**pasa por no haber mirado**— y llevaba en el proyecto desde que se escribió la receta. En paralelo, además, pcov tiene que llegar a los *workers* (`--passthru-php`), o el porcentaje sale por debajo del real; se verificó comparando serie y paralelo sobre el mismo subconjunto, con el umbral fallando en los dos.
>
> **`schema:dump` es un camino ya explorado y descartado con datos** (`T-003b`), no una mejora pendiente: quitar el `migrate` por proceso con un volcado salió **más lento** (306 s frente a 269 s), rompió tests y la imagen no trae `mysqldump` para regenerarlo. Si vuelve a proponerse, que sea con una medida nueva delante.
>
> **`REQ-QAX-003` RB-9 —ningún test llega a la red real— se impide, no se pide.** `TestCase` llama a `Http::preventStrayRequests()`, así que una petición HTTP sin falsear falla diciendo qué URL se intentó. Se puso al descubrir que **tres tests llevaban meses saliendo a `api.pwnedpasswords.com`** para ejercitar `Password::uncompromised()`: pasaban, y encima pasaban *al revés* cuando la red fallaba, porque el verificador de Laravel da por limpia la contraseña que no pudo consultar. El servicio se falsea en `FakesPwnedPasswords` sin debilitar la regla, que sigue calculando el hash y buscando su sufijo.
>
> **Lo que cuesta hoy una verificación completa** (`T-003b`, sobre 2 028 tests y 20 núcleos): `composer check` **5 min 51 s** —`lint` 21 s con caché de Pint, `analyse` 42 s con caché de PHPStan caliente, `test:coverage` 288 s— frente a los **20 min** de antes. `composer tenancy` 3 min y `npm run check` 1 min 24 s. Las tres piezas que lo bajaron, por orden de efecto: la suite en paralelo (1 068 s → 256 s), el MySQL de test sobre `tmpfs` y con `innodb-flush-log-at-trx-commit=2`, y `opcache.enable_cli` (−20 % sobre la suite). Las cachés de PHPStan y de Pint viven en `storage/framework/`, que sobrevive a recrear el contenedor: PHPStan en frío son **529 s** y en caliente 42 s, así que perder esa caché cuesta más que todo lo demás junto.
>
> **`tenancy:suite` corre con `--processes=4` y no con los que haya**, que es al revés que el resto. Son 158 tests, y en paralelo cada proceso paga su propio `migrate:fresh` (~11 s) antes del primero: con veinte procesos ese coste fijo se come la ganancia —156 s en serie, ~140 s con veinte, **117 s con cuatro**—. El número está atado al tamaño de la suite, así que cuando crezca hay que volver a medirlo; no es una constante, es una medición con fecha.
>
> **`composer analyse` corre con `parallel.maximumNumberOfProcesses: 2`, y el motivo conviene entenderlo antes de subirlo.** El script da `memory_limit=1G` a **cada** proceso y el contenedor `app` tiene 768 MB en total ([90](90-infraestructura-y-entorno.md) RB-6): con la paralelización por defecto —tantos procesos como núcleos— la suma se pasa del techo y el kernel mata un *worker*. El síntoma **no se parece** a quedarse sin memoria: es «Child process was killed by signal 9» y un análisis que termina incompleto, es decir, un gate que pasa por no haber mirado. Empezó a morder en `T-029` al analizar el trait de medialibrary, pero el techo estaba sobrevendido desde el principio: el `memory_limit` es un máximo por proceso y el del contenedor es un total duro, y nadie los había reconciliado. Si vuelve a morder, las salidas son subir el contenedor o bajar el límite por proceso — **no** volver a la paralelización por defecto.
- Estructura de `app/` según [02-spec-consolidada.md](02-spec-consolidada.md) §6.1.

**Reglas de negocio y precondiciones**
- RB-1 **Thin controllers**: un método de controlador recibe un `FormRequest`, invoca **una** `Action` inyectada por constructor y devuelve la respuesta. Prohibidos: queries de Eloquent, `if` de negocio y cálculos en controladores.
- RB-2 **Actions transaccionales**: toda mutación de más de una tabla ocurre en una `Action` dentro de `DB::transaction()`, con bloqueo pesimista donde la spec lo exija.
- RB-3 **Modelos anémicos**: `app/Models` contiene relaciones, casts, scopes y accesores triviales. Las reglas de negocio viven en `app/Domain`. Ningún modelo supera 200 **líneas de código**, sin contar blancos ni comentarios. **La medida se precisó en `T-030`**, y no es un aflojamiento: contando líneas en crudo, lo que se mide es cuánto se explica el archivo, y este proyecto exige explicarlos. `Product` llegó a 200 con 87 líneas de código y 99 de comentario, así que el `Product::scopePublished()` que `REQ-CAT-007` **exige en ese mismo modelo** no cabía ni escrito sin un solo docblock: dos requisitos cerrados se contradecían, y el que cede es el que medía la cosa equivocada. Es además cómo cuenta desde el principio el gate gemelo del frontend (`REQ-QAX-002` RB-1, `skipBlankLines` + `skipComments`), así que las dos mitades de la misma regla vuelven a decir lo mismo.
- RB-4 **Sin facades en `app/Domain`**: el dominio recibe sus dependencias por constructor y es testeable sin arrancar el framework.
- RB-5 **Inyección por constructor** con propiedades promovidas `readonly`. Prohibido `app()`/`resolve()` dentro de métodos de negocio.
- RB-6 **Larastan nivel 6** obligatorio en CI, con el objetivo declarado de subir a 8 (`ASU-QAX-03`). Cada `@phpstan-ignore` exige un comentario que justifique por qué.
- RB-7 **Pint** con preset Laravel, sin excepciones por archivo.
- RB-8 Prohibido `float`/`double` para importes en cualquier capa (`REQ-FX-001` RB-1); regla verificada por búsqueda en CI sobre migraciones y casts.
- RB-9 `declare(strict_types=1)` en todos los archivos de `app/`.

**Happy path**
1. El desarrollador escribe una `Action` con dominio puro y un controlador de 8 líneas.
2. `composer lint && composer analyse && composer test` pasa en local.
3. CI reproduce lo mismo y el PR queda verde.

**Sad paths y edge cases**
- SP-1 Controlador con una query → detectado en revisión; se documenta como falta de arquitectura (no automatizable de forma fiable, es criterio de revisión).
- SP-2 Larastan reportando un falso positivo → se añade `@phpstan-ignore` **con** justificación; sin justificación, la revisión lo rechaza.
- SP-3 Modelo que crece por encima de 200 líneas de código → señal de que hay lógica de negocio mal ubicada; se refactoriza a `app/Domain`. Crecer en documentación no es esa señal (RB-3).
- SP-4 Facade usada en `app/Domain` → detectada en revisión; el test unitario del dominio, que corre sin framework, lo evidencia al fallar.
- SP-5 Nivel 6 que se vuelve inviable por una dependencia sin tipos → se acota con un baseline **explícito y con fecha**, no con la bajada del nivel global.

**Fuera de alcance inmediato**
- Test de arquitectura automatizado (Pest Arch o Deptrac): se documenta como mejora recomendada.
- Nivel 9 de PHPStan.
- Mutation testing.

**Gate de validación**
- Superficie esperada: `pint.json`, `phpstan.neon`, `composer.json` (scripts), estructura de `app/`.
- Tipos esperados: los directorios de §6.1 presentes con al menos un artefacto cada uno.
- Anti-`SHALLOW`: no aplica (requisito de proceso); su verificación es el paso de CI de `REQ-QAX-004`.
- Tests: `composer analyse` sale con código 0 en nivel 6; `composer lint --test` sin diferencias; ningún archivo de `app/` sin `strict_types`; búsqueda de `float`/`double` en migraciones sin resultados; ningún modelo supera 200 líneas de código (RB-3).

---

### REQ-QAX-002 · Estándares de código frontend

**Historia de usuario**
> Como equipo, queremos componentes pequeños y tipados con lógica en hooks, para no acabar con páginas de mil líneas imposibles de tocar.

**Contratos de datos**
- `eslint.config.js`, `.prettierrc`, `tsconfig.json` con `strict: true` y `noUncheckedIndexedAccess`.
- Estructura de `resources/js/` según [02-spec-consolidada.md](02-spec-consolidada.md) §6.2.
- Scripts `npm run lint`, `npm run typecheck`, `npm run test`.

**Reglas de negocio y precondiciones**
- RB-1 **Ningún componente supera 200 líneas** (objetivo 150): regla ESLint `max-lines` con `skipBlankLines` y `skipComments`, como **error** en CI, no como advertencia.
- RB-2 **Lógica en custom hooks**: los componentes de página componen; el estado, los efectos y las llamadas viven en `features/*/hooks`. Un componente con más de dos `useEffect` es señal de refactor.
- RB-3 **TypeScript estricto**: `strict: true`, `noUncheckedIndexedAccess: true`, `noImplicitAny`. Prohibido `any` (regla ESLint como error); `unknown` + estrechamiento es la alternativa.
- RB-4 **TanStack acotado** según §6.2 RB-3: `react-table` solo en backoffice y en modo manual; `react-form` en los tres formularios complejos; `react-query` **solo** para tasa de cambio, chat, badge de la bandeja y paginación del feed. Usarlo para datos que ya viajan en page props es un fallo de revisión.
- RB-5 **daisyUI 5 + Tailwind 4**: los átomos se envuelven en `components/ui`; las páginas no aplican clases de daisyUI en crudo (garantiza consistencia y permite cambiar el sistema visual en un solo lugar).
- RB-6 **TSDoc obligatorio** en todo hook, tipo exportado y componente público (`ASU-QAX-...` / directiva del prompt): qué hace, qué recibe, qué devuelve.
- RB-7 **Mobile-first**: cada componente se define primero para 375 px; el `body` **nunca** hace scroll horizontal — el contenido ancho (tablas, diagramas, bloques de código) scrollea dentro de su propio contenedor `overflow-x: auto`.
- RB-8 Los tipos del dominio viven en `resources/js/types/domain.ts` como espejo manual de `app/Domain`; cualquier divergencia es un fallo de revisión (la generación automática con un transformer queda documentada como mejora).
- RB-9 Accesibilidad mínima: todo control interactivo alcanzable por teclado, `label` asociado en cada campo, contraste AA y foco visible.
- RB-10 **El formato lo comprueba una receta, no la buena voluntad.** `npm run format:check` forma parte de `npm run check` y del trabajo `frontend` de CI, igual que `composer lint` (`pint --test`) lo es del trabajo `backend`. Un `.prettierrc` que nada verifica es una guía de estilo que nadie lee: el código diverge en silencio y la divergencia aparece de golpe, como un `prettier --write` que reformatea treinta archivos ajenos dentro del commit de otra tarea. `resources/js/components/vendor/**` queda excluido en `.prettierignore` por el mismo motivo por el que ya está exento de `max-lines`: es andamiaje heredado en retirada.

**Happy path**
1. El desarrollador crea `useReceiptForm` con la lógica y un componente de 90 líneas que la consume.
2. `npm run lint && npm run typecheck && npm run test` pasa.
3. La página funciona igual en 375 px y en escritorio.

**Sad paths y edge cases**
- SP-1 Componente que llega a 210 líneas → CI falla; se extrae un subcomponente o un hook.
- SP-2 `any` introducido para salir del paso → CI falla; se usa `unknown` con estrechamiento.
- SP-3 `react-query` usado para el listado de productos → rechazado en revisión (RB-4); el listado va por page props.
- SP-4 Clase de daisyUI en una página → rechazado en revisión; se mueve al átomo correspondiente.
- SP-5 Tabla ancha que desborda en móvil → contenedor con `overflow-x: auto`, nunca scroll del `body` (RB-7).
- SP-6 Tipo de dominio divergente del PHP (campo renombrado en el backend) → el test de contrato de las page props lo detecta al fallar la aserción de forma.

**Fuera de alcance inmediato**
- Generación automática de tipos desde PHP.
- Storybook y documentación visual de componentes.
- Auditoría de accesibilidad completa (WCAG AA verificada por herramienta).
- Pruebas E2E (`ASU-QAX-02`).

**Gate de validación**
- Superficie esperada: `eslint.config.js`, `tsconfig.json`, `.prettierrc`, `package.json` (scripts), estructura de `resources/js/`.
- Tipos esperados: `domain.ts` con los tipos declarados en los módulos; `inertia.d.ts` con las page props compartidas.
- Anti-`SHALLOW`: no aplica (requisito de proceso).
- Tests: `max-lines` configurada como error y sin violaciones; `tsc --noEmit` limpio; sin `any` en `resources/js`; scripts presentes; test de contrato de page props compartidas.

---

### REQ-QAX-003 · Estrategia de pruebas

**Historia de usuario**
> Como equipo, queremos que las reglas de dinero y de cobranza estén cubiertas por pruebas, para poder cambiar el sistema sin miedo a romper el balance de un cliente.

**Contratos de datos**
- **Pest 3** para PHP; **Vitest + React Testing Library** para el frontend (`ASU-QAX-01`, `ASU-QAX-02`).
- Factories para todas las entidades; `tests/Unit`, `tests/Feature`, `resources/js/**/*.test.ts(x)`.

**Reglas de negocio y precondiciones**
- RB-1 **Cobertura obligatoria del dominio**: `app/Domain` y `app/Actions` deben alcanzar **≥ 85 %** de líneas. Sin umbral global (`ASU-QAX-01`), para no premiar tests de relleno en el resto.
- RB-2 **Tests unitarios sin framework** para el dominio puro: `Money`, `ScheduleGenerator`, `FifoAllocator`, `LayawayStatus`, `EventSanitizer`, `DocumentNumber`, `PhoneNumber`.
- RB-3 **Tests de propiedad** (con 500 casos aleatorios) para las tres invariantes aritméticas críticas:
  - `sum(Money::allocateEvenly(n)) === cents` (`REQ-FX-001`);
  - `sum(installments.amount_cents) === financiado` (`REQ-LAY-004`);
  - `sum(allocations) === payment.amount − unallocated` (`REQ-PAY-005`).
- RB-4 **Tests de feature Inertia** para cada endpoint: assert de componente y de props, no del HTML.
- RB-5 **Tests de transacción**: cada `Action` transaccional tiene un test que fuerza un fallo a mitad y verifica el rollback completo.
- RB-6 **Tests de concurrencia** para los tres puntos con bloqueo: reserva de stock, aprobación de comprobante y desactivación del último superadmin.
- RB-7 **Tests de equivalencia** donde la spec exige una sola fuente de verdad: vista de cobranza vs. exportación (`REQ-BOF-042`), balance del portal vs. del backoffice (`REQ-BOF-041`), aprobación de comprobante vs. pago manual (`REQ-PAY-007`), métrica del dashboard vs. su listado (`REQ-BOF-044`), segmento de push vs. vista de cobranza (`REQ-NOT-004`).
- RB-8 Base de datos de test en MySQL real (no SQLite): el sistema depende de `FULLTEXT`, de `lockForUpdate` y de restricciones `UNIQUE` compuestas que SQLite no reproduce.
- RB-9 Sin llamadas de red reales en los tests: `Http::fake()` para el proveedor de tasa, con un fixture del payload real como test de contrato (`REQ-FX-002` SP-4), y `Socialite::fake()` para el OAuth de las cuentas de consumidor (`REQ-ACC-001`).
- RB-10 **Toda factory y todo test de feature crea al menos dos organizaciones.** Es la regla que hace que las fugas se detecten por accidente y no solo cuando alguien las busca: si el escenario de prueba solo tiene un tenant, un `where` olvidado pasa desapercibido. La factory base siembra una organización "vecina" con datos equivalentes.
- RB-11 **Tests de plan** obligatorios para cada punto de aplicación de límite (`REQ-SUB-004` RB-3) y para las dos caras de las reglas asimétricas: el comprobante de un cliente se acepta con la cuota agotada y la imagen del negocio no (`REQ-SUB-008` RB-6); el impago suspende al equipo y no al cliente (`REQ-SUB-007` RB-3).

**Happy path**
1. El desarrollador implementa `FifoAllocator` con su test unitario de propiedad.
2. Añade el test de feature de la aprobación y el de rollback.
3. CI ejecuta 5 suites (unit, feature, propiedad, Vitest, contrato) y todo pasa.

**Sad paths y edge cases**
- SP-1 Test que depende del reloj (vencimientos) → se usa `Carbon::setTestNow()`; prohibido depender de la hora real.
- SP-2 Test que depende de la zona horaria del entorno → se fija la zona en `phpunit.xml`.
- SP-3 Test de concurrencia inestable (flaky) → se aísla con transacciones explícitas y conexiones separadas; si sigue inestable, se documenta y se marca, nunca se borra.
- SP-4 Cobertura del dominio por debajo del 85 % → CI falla.
- SP-5 Test que pasa por casualidad al no aislarse la BD → `RefreshDatabase` en todos los tests de feature.
- SP-6 Fixture del proveedor de tasa desactualizado respecto a la API real → el test de contrato sigue verde y el fallo aparece en producción; se documenta la revisión periódica del fixture como tarea manual.

**Fuera de alcance inmediato**
- Pruebas E2E con Playwright.
- Mutation testing (Infection).
- Pruebas de carga y de rendimiento.
- Pruebas de accesibilidad automatizadas.

**Gate de validación**
- Superficie esperada: `phpunit.xml`, `tests/Pest.php`, `tests/Unit/Domain/**`, `tests/Feature/**`, `tests/Concerns/CreatesNeighbourOrganization.php`, `database/factories/**`, `vitest.config.ts`.
- Tipos esperados: una factory por entidad del modelo de datos (§5); un test unitario por clase de `app/Domain`.
- Anti-`SHALLOW`: no aplica (requisito de proceso), pero **es el mecanismo que resuelve el `SHALLOW` de los demás módulos**: los métodos que las pruebas ejercitan son los que aportan lógica no trivial junto a los tipos.
- Tests: cobertura de `app/Domain` y `app/Actions` ≥ 85 %; las tres invariantes con 500 casos; un test de rollback por Action transaccional; los tres tests de concurrencia; los cinco tests de equivalencia; **toda factory y todo test de feature siembran una organización vecina** (RB-10, verificable por revisión y por el helper compartido); los tests de plan de RB-11; suite corriendo sobre MySQL; sin llamadas de red reales.

---

### REQ-QAX-004 · Pipeline de integración continua

**Historia de usuario**
> Como equipo, queremos que cada PR se verifique solo, para no depender de que alguien se acuerde de correr las comprobaciones.

**Contratos de datos**
- `.github/workflows/ci.yml` con servicios MySQL 8 y Redis 7.
- Jobs: `backend` (Pint, Larastan, Pest con cobertura), `frontend` (ESLint, `tsc --noEmit`, Vitest, `vite build`) y `tenancy` (`tenancy:audit` + suite de aislamiento, RB-4b).

**Reglas de negocio y precondiciones**
- RB-1 CI se ejecuta en cada `push` a `main` y en cada PR hacia `main`.
- RB-2 Los **tres** jobs corren **en paralelo** y los tres deben pasar para poder mezclar: `backend`, `frontend` y `tenancy`.
- RB-3 Pasos del job backend, en este orden: instalar dependencias con caché → `pint --test` → `phpstan` → `migrate` sobre el MySQL de servicio → `pest --coverage` con el umbral del dominio.
- RB-4 Pasos del job frontend: instalar con caché → `eslint` → `tsc --noEmit` → `vitest run` → `vite build` (verifica que el build de producción no rompe).
- RB-4b Pasos del job **tenancy**: `migrate` → `tenancy:audit` → la suite `tests/Feature/Tenancy`. Es un job **propio y separado** (`REQ-QAX-008` RB-7) para que su fallo sea inequívoco en el informe y no quede sepultado entre cientos de tests de negocio. Es el único job cuyo fallo no admite discusión ni excepción temporal.
- RB-5 Ningún paso se marca como "continuar en caso de error": un fallo bloquea la mezcla.
- RB-6 El pipeline **no** despliega (`ASU-INF-06`): solo verifica.
- RB-7 Los secretos no se exponen en los logs; el CI usa un `.env.ci` con valores de prueba.
- RB-8 Duración objetivo por debajo de 10 minutos; si se supera de forma sostenida, se paraleliza la suite en lugar de recortar comprobaciones.

**Happy path**
1. Se abre un PR; los tres jobs arrancan en paralelo.
2. `backend`, `frontend` y `tenancy` pasan en 6 minutos.
3. El PR queda listo para revisión humana.

**Sad paths y edge cases**
- SP-1 MySQL del servicio tardando en aceptar conexiones → paso de espera con healthcheck antes de migrar.
- SP-2 Caché de dependencias corrupta → clave de caché ligada al hash del lockfile; se invalida al cambiar.
- SP-3 Test inestable haciendo fallar CI de forma intermitente → se corrige o se marca explícitamente; no se desactiva el job.
- SP-4 `vite build` que falla solo en producción por una importación dinámica → RB-4 lo detecta antes de mezclar.
- SP-5 PR de solo documentación → los jobs corren igual (son rápidos); no se añade lógica de saltos condicionales por rutas.
- SP-6 Larastan con un baseline desactualizado → el paso falla; el baseline se regenera de forma explícita en un commit propio.

**Fuera de alcance inmediato**
- Despliegue continuo.
- Entornos de preview por PR.
- Reportes de cobertura publicados en un servicio externo.
- Matriz de versiones de PHP o Node.

**Gate de validación**
- Superficie esperada: `.github/workflows/ci.yml`, `.env.ci`.
- Tipos esperados: los tres jobs con los pasos de RB-3, RB-4 y RB-4b; servicios MySQL y Redis declarados.
- Anti-`SHALLOW`: no aplica (requisito de proceso).
- Tests: el workflow declara los tres jobs y todos sus pasos; ningún paso con `continue-on-error`; el job `tenancy` es independiente; no ejecuta despliegue; la clave de caché depende del lockfile.

---

### REQ-QAX-005 · Glosario canónico de identificadores en inglés

**Historia de usuario**
> Como equipo, queremos un único lugar donde estén todos los nombres de clases, tipos y campos que la spec exige, para que nadie invente un nombre distinto al implementar y para poder dar contexto compacto a una herramienta asistida por IA.

> **Reencuadre.** Este requisito nació para alimentar `indexion spec align`. Descartada la herramienta, el espejo pierde a su consumidor automático pero conserva un valor real: es el único artefacto que reúne, en inglés y en un solo archivo, los **identificadores esperados de los 118 requisitos**. Se degrada de "artefacto de tooling que hay que mantener sincronizado" a **contrato de nombres del equipo**.

**Contratos de datos**
- `docs/specs/.align/requirements.en.md`: artefacto derivado, en inglés, una entrada por requisito con sus sentencias normativas y su lista literal de identificadores esperados.
- Los módulos en español son la fuente.

**Reglas de negocio y precondiciones**
- RB-1 Es un **contrato de nombres**: si la spec dice `PaymentApplier`, la clase se llama `PaymentApplier`. El espejo es el sitio donde ese vocabulario está reunido y consultable de un vistazo.
- RB-2 Contiene, por requisito: su ID, un título en inglés, sentencias normativas con `shall` y la lista de identificadores esperados.
- RB-3 Sigue siendo **derivado**: la fuente de verdad es `docs/specs/` y toda corrección se hace primero en el módulo en español.
- RB-4 **Obligación de sincronía reducida**: se actualiza cuando cambia un **identificador público** (una clase, un enum, un campo, un tipo TS), no cuando cambia la prosa, un happy path o un sad path. Mantener dos narrativas en paralelo sin una herramienta que lo aproveche sería trabajo perdido.
- RB-5 La trazabilidad de IDs sigue siendo **1:1** con los módulos, y eso sí se verifica: es lo que permite comprobar que no falta ni sobra ningún requisito.
- RB-6 Se elimina la copia en `.kiro/specs/`: existía solo para herramientas SDD que ya no se usan.
- RB-7 La prosa explicativa no se traduce. El espejo es vocabulario, no una segunda spec.

**Happy path**
1. Al implementar `REQ-PAY-005` se consulta su entrada del espejo y se toman los nombres exactos: `PaymentApplier`, `FifoAllocator`, `AllocationPlan`.
2. Durante la implementación se decide renombrar `AllocationPlan` a algo más claro.
3. Se corrige el módulo en español y se propaga al espejo en el mismo PR.

**Sad paths y edge cases**
- SP-1 Identificador renombrado en el código sin actualizar la spec ni el espejo → se detecta en revisión al comparar con la sección *Tipos esperados* del requisito.
- SP-2 Requisito nuevo ausente del espejo → el conteo de IDs deja de coincidir (RB-5).
- SP-3 Cambio de prosa sin tocar identificadores → **no** exige actualizar el espejo (RB-4).
- SP-4 Duda sobre qué nombre usar → el espejo es la referencia; si es ambiguo, se corrige el módulo.

**Fuera de alcance inmediato**
- Generación automática del espejo.
- Spec íntegramente en inglés.
- Verificación automatizada de que los identificadores del código coinciden con el espejo.

**Gate de validación**
- Superficie esperada: `docs/specs/.align/requirements.en.md`.
- Tipos esperados: una entrada por cada uno de los 118 IDs.
- Anti-`SHALLOW`: no aplica (artefacto documental).
- Tests: el conteo de IDs del espejo coincide con el de los módulos y no hay huérfanos en ninguna dirección (comprobable con dos `grep` y un `diff`, sin herramientas externas).

---

### REQ-QAX-006 · Definición de hecho y política de revisión

**Historia de usuario**
> Como equipo, queremos un criterio objetivo y ejecutable de cuándo un requisito está terminado, para no confiar en la impresión de que ya está hecho.

> **Reencuadre.** Este requisito describía la cadena de comandos de `indexion`. Descartada la herramienta, se reescribe como la política de aceptación que sí es ejecutable: las cuatro líneas del *Gate de validación* de cada requisito, verificadas con Pest, Vitest, PHPStan y `tenancy:audit`.

**Contratos de datos**
- La sección *Gate de validación* de cada uno de los 118 requisitos.
- Los tres jobs de CI de `REQ-QAX-004`: `backend`, `frontend`, `tenancy`.
- Fases A y B según [02-spec-consolidada.md](02-spec-consolidada.md) §8.2.

**Reglas de negocio y precondiciones**
- RB-1 Un requisito está **hecho** cuando sus cuatro líneas se cumplen: la *superficie esperada* existe, los *tipos esperados* existen con su nombre exacto, su función **anti-`SHALLOW`** tiene lógica real y sus *tests* pasan.
- RB-2 **Fase A** cierra con `phpstan` y `tsc` limpios, migraciones corriendo y los tipos completos. `Anti-SHALLOW` está **tolerado**: los métodos aún están vacíos y eso es correcto en esta fase.
- RB-3 **Fase B** cierra cuando los tests del requisito pasan y su `Anti-SHALLOW` está resuelto. Un módulo con tipos y métodos vacíos **no cuenta como entregado**, y ese es el fallo que esta regla existe para prevenir.
- RB-4 La etiqueta `Anti-SHALLOW` se conserva aunque la herramienta que la acuñó ya no esté: nombra un modo de fallo real, y renombrarla en 118 secciones no aportaría nada.
- RB-5 Se verifica en **revisión de PR** contra la sección del requisito, no por un comando de conformidad documental. Se acepta explícitamente la pérdida: no hay métrica automática de *drift* entre spec y código.
- RB-6 Lo que **sí** es automático son los tres jobs de CI (`REQ-QAX-004`), y en particular `tenancy` (`REQ-QAX-008`), que nunca dependió de `indexion`.
- RB-7 Cada PR declara en su descripción **qué requisitos cierra**, por ID. Es el sustituto ligero de la trazabilidad automatizada, y es suficiente a esta escala.
- RB-8 Un requisito que se decide no implementar se mueve a *Fuera de alcance inmediato* en su módulo, con fecha y motivo. Nunca se deja a medias sin declararlo.

**Happy path**
1. Se termina la Fase B de `REQ-PAY-005`.
2. El PR declara "cierra REQ-PAY-005, REQ-PAY-007" y sus tests incluyen los casos listados en ambos gates.
3. La revisión comprueba las cuatro líneas de cada uno y los tres jobs de CI pasan.

**Sad paths y edge cases**
- SP-1 PR que declara cerrar un requisito con los métodos vacíos → rechazado por RB-3.
- SP-2 PR sin declarar requisitos → se pide la lista antes de revisar (RB-7).
- SP-3 Requisito cuyos tests pasan pero cuya *superficie esperada* está en otros archivos → se acepta si la ubicación es mejor, **corrigiendo la spec en el mismo PR**; la spec no puede quedar mintiendo.
- SP-4 Deriva entre spec y código detectada tarde → consecuencia asumida de RB-5; la mitigación es RB-7 y la revisión disciplinada.
- SP-5 Requisito que resulta inviable como está especificado → se corrige el requisito, no se implementa otra cosa en silencio.

**Fuera de alcance inmediato**
- Métrica automática de *drift* entre spec y código.
- `indexion` y cualquier herramienta de alineación documental.
- Trazabilidad automatizada requisito ↔ commit.

**Gate de validación**
- Superficie esperada: la sección *Gate de validación* de los 118 requisitos; `.github/workflows/ci.yml`; plantilla de PR con el campo "requisitos que cierra".
- Tipos esperados: no aplica (requisito de proceso).
- Anti-`SHALLOW`: no aplica; **es el requisito que define la regla** para todos los demás.
- Tests: los tres jobs de CI existen y pasan; la plantilla de PR incluye el campo de requisitos; los 118 requisitos tienen sus cuatro líneas de gate (verificable con `grep`).

---

### REQ-QAX-007 · Documentación técnica obligatoria (PHPDoc / TSDoc)

**Historia de usuario**
> Como desarrollador que llega al proyecto, quiero que las clases y los hooks expliquen su propósito y sus contratos, para entender el sistema sin tener que reconstruirlo mentalmente.

**Contratos de datos**
- PHPDoc en toda clase, interfaz, enum, método público y propiedad promovida de `app/`.
- TSDoc en todo hook, tipo exportado y componente público de `resources/js/`.

**Reglas de negocio y precondiciones**
- RB-1 **PHPDoc obligatorio** en clases, interfaces, enums, métodos públicos y propiedades promovidas: propósito, `@param`, `@return`, `@throws` y genéricos de colecciones (`@return list<Installment>`, `Collection<int, Layaway>`), que además es lo que permite subir el nivel de Larastan.
- RB-2 **Prohibido el comentario obvio** dentro del cuerpo (`// incrementa el contador`). El código debe ser autoexplicativo; el comentario en el cuerpo se reserva para explicar **por qué**, no qué.
- RB-3 **TSDoc obligatorio** en hooks, tipos exportados y componentes públicos: qué hace, qué recibe, qué devuelve y cualquier efecto secundario relevante.
- RB-4 Los PHPDoc de las declaraciones públicas son además el vehículo para resolver `DRIFTED` (`REQ-QAX-006` RB-5): deben usar el **vocabulario de la spec** (los términos del glosario de §3), no sinónimos improvisados.
- RB-5 Todo `@throws` documentado debe corresponder a una excepción que el método realmente pueda lanzar, y toda excepción de dominio declarada en la spec debe aparecer documentada en el método que la lanza.
- RB-6 Los enums documentan el significado de cada caso cuando no es evidente por el nombre (por ejemplo, la diferencia entre `Unlisted` y `Draft` en `CatalogVisibility`).
- RB-7 Ninguna clase de `app/Domain` carece de una frase que explique su responsabilidad y su invariante principal.

**Happy path**
1. El desarrollador escribe `FifoAllocator` con un PHPDoc que explica la regla FIFO y su invariante de suma.
2. Larastan aprovecha los genéricos y no reporta tipos indeterminados.
3. El gate de `spec align` empareja el requisito con el archivo por el vocabulario del PHPDoc.

**Sad paths y edge cases**
- SP-1 PHPDoc que contradice la firma (tipos distintos) → Larastan lo detecta y CI falla.
- SP-2 Método público sin PHPDoc → detectado en revisión; no automatizado en v1 (se documenta como mejora con una regla de Pint o un sniff).
- SP-3 Comentario obvio en el cuerpo → se elimina en revisión.
- SP-4 PHPDoc con vocabulario propio en lugar del de la spec → produce `DRIFTED`; se corrige alineando los términos con el glosario.
- SP-5 `@throws` documentado que ya no se lanza tras un refactor → se elimina; la revisión lo verifica.
- SP-6 Documentación en inglés en la prosa del PHPDoc → **preferida**, precisamente para el matching de vocabulario (`REQ-QAX-005` RB-1); la narrativa en español vive en la spec, no en el código.

**Fuera de alcance inmediato**
- Verificación automatizada de la presencia de PHPDoc.
- Documentación de API generada (phpDocumentor, TypeDoc).
- Storybook para componentes.

**Gate de validación**
- Superficie esperada: todos los archivos de `app/Domain`, `app/Actions` y `resources/js/features/*/hooks`.
- Tipos esperados: cada clase de dominio y cada `Action` con su bloque de documentación; cada hook con TSDoc.
- Anti-`SHALLOW`: no aplica, pero es el requisito que **hace posible** resolver `DRIFTED` en todos los demás módulos.
- Tests: Larastan sin contradicciones entre PHPDoc y firmas; muestreo de revisión de que las clases de `app/Domain` documentan responsabilidad e invariante; los términos del glosario (§3) aparecen en los PHPDoc de las clases correspondientes (comprobación que el propio `spec align` realiza).

---

### REQ-QAX-008 · Gate de aislamiento multi-tenant

**Historia de usuario**
> Como responsable de la plataforma, quiero una prueba automática de que ningún negocio puede ver los datos de otro, para poder afirmarlo ante un cliente sin estar confiando en mi memoria.

**Contratos de datos**
- Suite `tests/Feature/Tenancy/` con un caso por endpoint del tenant.
- Helper `CreatesNeighbourOrganization` (`REQ-QAX-003` RB-10).
- Comando de auditoría estática `php artisan tenancy:audit`.

**Reglas de negocio y precondiciones**
- RB-1 Es la invariante `ORG-002` convertida en gate, y **no admite excepciones, deuda técnica ni baseline**. Un fallo aquí bloquea la mezcla sin discusión: una fuga entre organizaciones no es un bug de gravedad alta, es el fin del producto.
- RB-2 **Un test de fuga por cada endpoint del tenant.** El patrón es siempre el mismo, y por eso se puede parametrizar en lugar de escribirlo a mano 80 veces:
  1. sembrar la organización A y la organización B con datos equivalentes;
  2. autenticarse como usuario de A;
  3. intentar leer, escribir o borrar el recurso de B por su identificador;
  4. exigir `404` (no `403`: no se revela la existencia del recurso ajeno, `REQ-ORG-002` RB-6).
- RB-3 Superficies cubiertas, todas ellas:
  | Superficie | Qué se verifica |
  |---|---|
  | Backoffice | los 60+ endpoints del tenant con el recurso de otra organización |
  | Catálogo público | el tenant se deriva del catálogo y nunca del payload (`REQ-CAT-021` RB-3) |
  | Portal | `PortalContext` acota las fichas visibles (`REQ-ACC-005` RB-3) |
  | Canales de broadcast | los tres canales rechazan a quien no pertenece (`REQ-CHT-003` RB-1, `REQ-PAY-004` RB-1) |
  | Jobs | el tenant se fija por elemento y se limpia (`REQ-INF-003` RB-1b) |
  | Archivos firmados | la firma no basta sin pertenencia (`REQ-PAY-002` RB-2) |
  | Plataforma | la exclusión del scope aparece **solo** en las rutas permitidas de RB-4b |
  | Telemetría | el cliente no puede inyectar `organization_id` (`REQ-TLM-001` RB-3) |
  | Feed | solo aparece contenido de catálogos seguidos (`REQ-ACC-003` RB-1) |
- RB-4 **Auditoría estática** complementaria (`tenancy:audit`), porque los tests solo cubren lo que alguien se acordó de escribir:
  1. todo modelo con `organization_id` usa el trait `BelongsToOrganization`;
  2. todo índice único de negocio incluye `organization_id`, salvo las **tres excepciones declaradas** (`users.email`, `catalogs.slug`, `layaways.code`), que están en una lista blanca explícita;
  3. `withoutTenantScope()` no aparece fuera de las rutas permitidas;
  4. ninguna migración añade una tabla del tenant sin `organization_id` ni sin su índice.
- RB-4b **Rutas permitidas para excluir el scope de tenancy**, lista cerrada: `app/Queries/Platform`, `app/Actions/Platform`, `app/Auth/TenantAwareUserProvider` y los comandos de mantenimiento. La de `app/Auth` es la menos obvia y la más necesaria: el login resuelve al usuario **antes** de conocer la organización, y `users.email` es único global (`REQ-ORG-002` RB-3) precisamente para que esa búsqueda sin filtro no pueda devolver el usuario de otro negocio. La comprobación busca **las dos grafías** —`withoutTenantScope()` y `withoutGlobalScope(TenantScope::class)`—, porque el código genérico sobre `Builder<Model>` no puede usar el scope local.
- RB-4c **Toda factory de un modelo con `BelongsToOrganization` usa `CreatesWithinTenant`.** La guarda de creación del trait lo exige de hecho; sin esta comprobación, depende de que cada tarea se acuerde.
- RB-5 La lista blanca de las tres excepciones vive en el código, con un comentario que explica **por qué** cada una es global. Añadir una cuarta exige modificar esta spec.
- RB-6 **Verificación de la frontera cuenta ↔ organización**, que es la parte más sutil del modelo: ningún `Resource`, respuesta JSON o exportación del backoffice expone `account_id` (`REQ-ACC-007` RB-5), y ninguna consulta del backoffice devuelve actividad de sus clientes en otras organizaciones (`REQ-ACC-007` RB-4, `REQ-BOF-041` RB-9).
- RB-7 El gate corre en CI como **job propio** (`REQ-QAX-004`), separado del resto, para que su fallo sea inequívoco en el informe y no quede sepultado entre otros.
- RB-8 No depende de ninguna herramienta externa: son tests de Pest y un comando de Artisan. Es la razón por la que descartar `indexion` no lo afecta y por la que es el único gate que puede exigirse desde el primer commit.

**Happy path**
1. Un desarrollador añade un endpoint de backoffice y olvida que el binding debe estar filtrado.
2. El test parametrizado de fuga lo detecta: el recurso de la organización vecina devuelve `200` en lugar de `404`.
3. CI falla en el job de tenancy con el nombre exacto del endpoint, y el desarrollador lo corrige antes de mezclar.

**Sad paths y edge cases**
- SP-1 Endpoint nuevo sin test de fuga → la parametrización se construye **desde la tabla de rutas**, no desde una lista escrita a mano, de modo que un endpoint nuevo del tenant entra automáticamente en la suite y falla si no está cubierto. Es lo que evita que el gate se degrade con el tiempo.
- SP-2 Modelo nuevo sin el trait → detectado por `tenancy:audit` (RB-4.1).
- SP-3 Índice único nuevo sin `organization_id` → detectado por `tenancy:audit` (RB-4.2).
- SP-4 Cuarta excepción legítima → exige modificar esta spec y la lista blanca; no se puede añadir en silencio.
- SP-5 `withoutTenantScope()` introducido en un controlador de backoffice → detectado por RB-4.3.
- SP-6 Test que pasa porque la organización vecina no tenía datos equivalentes → mitigado por el helper de RB-10 de `REQ-QAX-003`, que siembra datos comparables y no vacíos.
- SP-7 Fuga a través de un canal de broadcast, que ningún test HTTP detectaría → cubierta explícitamente por RB-3.
- SP-8 Fuga a través de un job que hereda el tenant del anterior → cubierta por RB-3 y por `REQ-INF-003` RB-1b.
- SP-9 `account_id` expuesto en una exportación a Excel → cubierto por RB-6, con assert sobre las columnas del archivo generado.

**Fuera de alcance inmediato**
- Pruebas de penetración y auditoría externa de seguridad.
- Cifrado por organización de datos en reposo.
- Verificación formal del aislamiento.

**Gate de validación**
- Superficie esperada: `tests/Feature/Tenancy/CrossTenantAccessTest.php`, `tests/Feature/Tenancy/BroadcastChannelIsolationTest.php`, `tests/Feature/Tenancy/JobTenantContextTest.php`, `tests/Feature/Tenancy/AccountBoundaryTest.php`, `app/Console/Commands/TenancyAudit.php`, `app/Domain/Tenancy/GlobalUniqueExceptions.php`, `.github/workflows/ci.yml` (job `tenancy`).
- Tipos esperados: `CreatesNeighbourOrganization`, `TenancyAudit`, `GlobalUniqueExceptions`.
- Anti-`SHALLOW`: `TenancyAudit::handle()` (las cuatro comprobaciones estáticas de RB-4, con la lista blanca) supera 4 líneas junto a `GlobalUniqueExceptions`; la parametrización desde la tabla de rutas vive en `CrossTenantAccessTest`.
- Tests: la suite cubre **todos** los endpoints del tenant derivados de la tabla de rutas; los tres canales de broadcast rechazan al ajeno; un job sin tenant falla y el tenant se limpia entre ejecuciones; la firma de archivo no basta sin pertenencia; `tenancy:audit` detecta las cuatro clases de violación con casos inyectados; la lista blanca contiene exactamente las tres excepciones declaradas; ningún `Resource` ni exportación expone `account_id`; el job de CI es independiente y sin `continue-on-error`.
