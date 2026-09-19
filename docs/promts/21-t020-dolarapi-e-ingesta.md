# PROMPT 21 — `T-020` · `DolarApiProvider` e ingesta de tasa

> Hito **H1 · Aritmética y tasa** · Tarea `T-020` · Fase `B` · 3 pts
> **Cierra `REQ-FX-002`.**
> Depende de `T-019` (`08a8d4e`, contratos y `exchange_rates`) y `T-018` (`ed88816`, `Money` completo).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-020: DolarApiProvider e ingesta`.

## 0. Qué vas a hacer

La primera pieza del sistema que **habla con el mundo exterior**, y por tanto la primera que puede fallar por algo que no controlamos. Casi todo el requisito trata de eso: qué pasa cuando la API se cae, cuando responde basura, o cuando responde bien pero con una cifra imposible.

Al cerrarla, el proyecto tiene tasa de cambio real y `Money::convertTo()` deja de ser teoría.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → **`REQ-FX-002`** | las ocho reglas y los seis `SP-`; casi todos son modos de fallo |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§6** | la forma del provider y su binding |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-020` | el "hecho cuando" y **el añadido de alcance** de §6 |
| `app/Domain/Exchange/` | lo que dejó `T-019`: `RateSnapshot`, `ExchangeRateProvider`, las dos excepciones |

## 2. Alcance

**Sí:**

1. **`DolarApiProvider`** y **`ManualRateProvider`**.
2. **`ExchangeRateService::current(?Organization $organization = null)`**.
3. **`FetchExchangeRate`** (cola `high`, cada 30 min, `WithoutOverlapping`).
4. **`ExchangeServiceProvider`** con el binding por configuración, y **`ExchangeRateSeeder`**.
5. **`GET /api/tasa?source=`** con su controlador, declarando **superficie**.
6. Su entrada en el `schedule` (`REQ-INF-003` cierra al final de H8; cada job añade la suya).
7. Sus tests, incluido el **fixture de contrato** del payload real.
8. **El añadido de alcance de §6.**

**No:**

- **No** construyas la UI de la fuente por organización ni `PUT /backoffice/ajustes/tasa`: es **`T-021`**.
- **No** implementes la proyección bimoneda, `MoneyLabel`, `CurrencyToggle` ni `RateStamp`: son **`T-022`**.
- **No** implementes el snapshot inmutable en operaciones: es **`T-023`**.

## 3. Una sola petición para toda la plataforma

RB-0 es la regla que ordena el diseño: **la ingesta es de plataforma y única** (`D-6`). Un job cada 30 minutos consulta las fuentes y las persiste, **con independencia de cuántas organizaciones existan**. Escalar a 10.000 negocios no puede multiplicar las llamadas a la API externa.

Y más fino: `GET https://ve.dolarapi.com/v1/dolares` trae **oficial y paralelo en la misma respuesta**, así que tampoco son dos peticiones. El "hecho cuando" pide un test que lo demuestre **con N organizaciones sembradas**, contando las peticiones HTTP: es la forma de que la regla no se erosione el día que alguien "arregle" el job iterando organizaciones.

Recuerda lo que ya decidió `T-019`: `exchange_rates` **no es del tenant** y está declarada en `NON_TENANT_TABLES` con su motivo. El job corre sin organización fijada, y hay un test que lo sostiene.

## 4. Lo que llega de fuera es sospechoso

Tres capas distintas, y conviene no confundirlas:

**1. No respondió** (SP-1, SP-3). Reintentos con backoff `10 s / 60 s / 300 s`, timeout HTTP de **8 s** para no bloquear la cola, y al fallar **se conserva** la caché y el histórico. Nunca se borra lo anterior por no haber podido leer lo nuevo.

**2. Respondió otra cosa** (SP-4). El formato cambió: el parser lanza `ExchangeRateUnavailableException` y se trata como el caso anterior. Aquí va el **fixture de contrato** con el payload real: es lo que convierte un cambio silencioso del proveedor en un test rojo en vez de en una tasa `null` en producción.

**3. Respondió bien, pero con una cifra imposible** (SP-2). `0`, negativa, o **variación mayor al 30 %** respecto a la última: se rechaza, se registra alerta y se conserva la anterior. Esta es la capa que más se olvida y la que más daño evita — una tasa corrupta del proveedor no rompe nada visiblemente: reprecia **todo el catálogo de todos los negocios** y genera apartados con cifras que nadie va a poder explicar.

Y la prioridad dentro del payload: **`promedio > venta > compra`**.

## 5. Dos detalles del almacenamiento

**`exchange_rates` es un histórico inmutable: solo `INSERT`** (RB-2). Nunca se actualiza ni se borra una fila, porque `REQ-FX-006` va a congelar referencias a estas filas en operaciones ya conciliadas.

**No se inserta si nada cambió** (RB-3): misma `source`, misma tasa **y** mismo `published_at` → solo se refresca la caché. Ojo con la conjunción: una tasa idéntica con `published_at` nuevo **sí** es una fila nueva, porque el BCV republicó.

Y `fetched_at` **lo escribe la base de datos, no PHP** (SP-6) — `T-019` ya lo dejó con `useCurrent()`. El motivo es un reloj de contenedor desfasado.

## 6. Añadido de alcance: el falso positivo de `format:check`

Una línea, y está anotado en el plan bajo `T-020` con el diagnóstico hecho.

`resources/js/ssr.jsx` incumple `format:check` **solo en un host Windows**, y no es un problema de formato: `prettier-plugin-organize-imports` emite CRLF en el bloque de imports, el archivo es LF en git y `.gitattributes` fuerza `eol=lf`. Está comprobado que **no se arregla con `--end-of-line lf`**: la normalización de Prettier no alcanza la salida del plugin. Dentro del contenedor —que es lo que corre CI— pasa en verde.

Añade `resources/js/ssr.jsx` al `.prettierignore` con ese motivo escrito. Es el punto de entrada de SSR heredado del starter kit, nadie lo edita, y dejar un aviso permanente en la receta que acabamos de cablear es la forma más rápida de que la gente aprenda a ignorarla.

## 7. Hecho cuando

- [ ] **Una sola petición HTTP** trae oficial y paralelo, **con N organizaciones sembradas** y contando peticiones.
- [ ] Prioridad `promedio > venta > compra`.
- [ ] Rechaza **variación > 30 %** y `rate <= 0`, conservando la anterior y registrando alerta.
- [ ] **No inserta si nada cambió**; sí inserta si cambió el `published_at`.
- [ ] **Fixture de contrato** con el payload real; un cambio de formato lanza `ExchangeRateUnavailableException`.
- [ ] Fallo de API: backoff `10/60/300`, timeout `8 s`, caché e histórico **conservados**.
- [ ] `current()` cae de caché → BD → `null`, con test de los tres caminos.
- [ ] `WithoutOverlapping` aplicado, con test.
- [ ] `GET /api/tasa?source=` público, cacheado 60 s, **sin revelar qué organización usa cuál**; declara superficie.
- [ ] La entrada del `schedule` añadida.
- [ ] `resources/js/ssr.jsx` en `.prettierignore` con su motivo.
- [ ] `tenancy:audit` y la suite en verde; el job corre **sin organización fijada**.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Cómo probaste que es **una sola petición** con N organizaciones — el mecanismo, no que pasa.
2. Cómo detectas la variación > 30 % y contra qué la comparas cuando no hay histórico.
3. Qué forma tiene el fixture de contrato y qué rompería exactamente si el proveedor cambiara el payload.
4. Cómo quedó `current()` con sus tres caminos, y qué pasa si Redis está caído.
5. Si algo de `REQ-FX-002` depende de algo que aún no existe, qué y de qué tarea es.
6. Nada más. No propongas siguientes pasos.
