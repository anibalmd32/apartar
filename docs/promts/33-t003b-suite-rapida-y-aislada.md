# PROMPT 33 — `T-003b` · Suite rápida y aislada

> Hito **H0 · Cimientos** (tarea hermana de `T-003`) · Tarea `T-003b` · Fase `AB` · 2 pts
> **Se ejecuta entre `T-032` y `T-033`**, con la medida delante: la verificación final de `T-032` tardó **17 min 40 s** en una sola pasada de 2024 tests.
> `REQ-QAX-003` RB-1 y `REQ-QAX-004` siguen siendo la autoridad: esta tarea los hace **más rápidos**, no más flojos.

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Esta tarea es distinta de las demás: **no toca el producto**. Ni una línea de `app/` que no sea para arreglar un test que dependía del orden. Si te ves cambiando comportamiento para que un test vaya más rápido, párate.

**Haz commit al terminar**, con el mensaje `T-003b: suite rapida y aislada`.

## 0. Qué vas a hacer

La suite tarda casi dieciocho minutos y quedan más de cincuenta tareas. Cada sesión paga ese peaje al menos una vez, y cuando algo falla al final, dos. Esto es una tarea de infraestructura para bajarlo a menos de seis **sin quitar un solo test, sin bajar el umbral de cobertura y sin aflojar ningún gate**.

Tres palancas, en orden de rendimiento por riesgo: **paralelizar** la ejecución, poner la base de datos de test en **memoria**, y hacer que la **caché de PHPStan sobreviva** entre ejecuciones.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/91-calidad-y-gates.md](../specs/91-calidad-y-gates.md) → `REQ-QAX-001`, **las notas de operación** | lo ya medido: el doble recorrido, la interferencia entre recetas, el orden barato-primero, el *deadlock* |
| [docs/specs/90-infraestructura-y-entorno.md](../specs/90-infraestructura-y-entorno.md) → `REQ-INF-001` | los servicios, sus límites de memoria y qué es cada volumen |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-003` y `T-003b` | de dónde viene cada receta y qué se espera de esta tarea |
| `phpunit.xml`, `composer.json` (scripts), `phpstan.neon`, `docker-compose.yml`, `.github/workflows/ci.yml` | el terreno completo |
| `tests/Feature/Organization/LastOwnerConcurrencyTest.php`, `tests/Feature/Subscription/LimitEnforcerConcurrencyTest.php` | **los dos tests que no pueden paralelizarse**, y el motivo está escrito en ellos |

## 2. Alcance

**Sí:**

1. **Ejecución en paralelo** de la suite, con los tests de concurrencia aislados en su propio *testsuite* secuencial (§3).
2. **La base de datos de test en `tmpfs`**, separada de la de desarrollo (§4).
3. **Caché de PHPStan persistente** entre ejecuciones y entre reinicios del contenedor.
4. **El orden de los gates escrito donde se lee**: recetas de `composer` y de `npm` que hagan evidente qué va primero y qué no puede correr a la vez.
5. **CI alineado** con los mismos cambios, sin perder el reparto de los tres *jobs*.
6. **Medir antes y después** con el mismo comando y reportar los números (§7).

**No:**

- **No** borres, saltes ni marques como lentos ningún test. Un gate que deja de correr es un gate que no existe, y este proyecto ya lo tiene escrito.
- **No** bajes el umbral de cobertura ni el nivel de PHPStan.
- **No** toques `app/` salvo para arreglar un test que dependiera del orden (§5), y si lo haces, dilo.
- **No** metas funcionalidad de ninguna tarea del plan.

## 3. Lo único con riesgo real: los dos tests de concurrencia

`LastOwnerConcurrencyTest` y `LimitEnforcerConcurrencyTest` **no pueden usar `RefreshDatabase`**: abren dos conexiones para provocar un bloqueo real, y un bloqueo entre conexiones solo es observable sobre filas **confirmadas**. Por eso limpian a mano las tablas que tocan, con la lista explícita que la convención del plan exige.

En paralelo, eso se rompe de la peor manera: cada proceso trabaja sobre su propia base —`apartar_testing_1`, `_2`…—, pero estos dos **confirman** y **barren por nombre de tabla**, así que un barrido puede llevarse por delante las filas que otro proceso estaba usando, y el fallo aparecerá en un test que no tiene nada que ver. Un fallo intermitente y ajeno es exactamente lo que hace que una suite deje de creerse.

**Van a un *testsuite* propio que corre en serie**, antes o después del resto, y eso queda escrito donde la próxima tarea que escriba un test de concurrencia lo vea — `T-044` va a escribir el de la reserva de stock sobre la última unidad, y tiene que saber dónde ponerlo.

## 4. La base de datos de test en memoria, sin llevarse la de desarrollo

`tmpfs` es memoria: rápida y volátil. Para una base de tests eso es exactamente lo que se quiere —se recrea en cada ejecución—, y para la de desarrollo **no**: ahí están los datos sembrados con los que se mira la aplicación en `http://localhost:8080`.

Así que la de test va **aparte**, no como un `tmpfs` colgado del `mysql` de siempre. Y con la memoria hay que contar, no suponer: [90](../specs/90-infraestructura-y-entorno.md) RB-6 declara los límites de cada servicio y el host no es infinito — un `tmpfs` sin tope compite con el contenedor `app`, que ya se quedó corto una vez con PHPStan y respondió con un `SIGKILL` que no se parecía a falta de memoria.

Mientras estés ahí: `innodb_flush_log_at_trx_commit = 2` en la base de test es correcto por el mismo motivo por el que `tmpfs` lo es. Perder la última transacción de una base que se recrea no le importa a nadie.

## 5. Paralelizar destapa tests que se apoyaban unos en otros

Es lo que va a pasar y conviene esperarlo: un test que solo pasaba porque otro había corrido antes —una fila sembrada, una caché caliente, un `id` que siempre salía 1— falla en cuanto el orden cambia.

**Eso no es un problema de la paralelización: es un bug del test que estaba oculto.** Arréglalo en el test, nunca relajando lo que afirma ni fijando el orden para taparlo. Si encuentras uno que dependía de un `id` concreto o del estado que dejó otro, dilo en el reporte: es información sobre cómo se están escribiendo los tests, y vale para las cincuenta tareas que faltan.

Y la cobertura: `--coverage --min=85` en paralelo exige fusionar los informes de cada proceso, y eso a veces cuesta más de lo que ahorra. **Mídelo en las dos formas** —paralelo con cobertura, y paralelo sin ella dejando la cobertura para CI— y trae los dos números en vez de elegir a ciegas. Lo que no vale es que el gate de `REQ-QAX-003` deje de invocarse en local sin que nadie lo decida con datos delante.

## 6. Dos cosas ya medidas que no hay que volver a descubrir

Están en [91](../specs/91-calidad-y-gates.md) y esta tarea debería dejarlas **imposibles de olvidar**, no solo escritas:

1. **`composer check` y `npm run check` no pueden correr a la vez.** `vite build` escribe en `public/` mientras `Storage::fake('public')` borra directorios, y salen veinticinco fallos que no son del código. Costó veinte minutos averiguarlo en `T-032`.
2. **Los gates baratos van primero.** `pest tests/Feature/Quality` tarda medio minuto y contiene reglas estructurales —entre ellas que cada clase de `app/Domain` y `app/Actions` tenga su `<Clase>Test`— que descubiertas al final cuestan la pasada entera.

Si una receta puede impedir el primer caso en lugar de advertirlo, mejor. Y el `deadlock` intermitente al sembrar planes es tuyo si el aislamiento lo hace desaparecer; si sigue ahí, deja dicho qué lo provoca.

## 7. Hecho cuando

- [ ] **La verificación final baja de 6 minutos**, medida con el mismo comando y con los números de antes y después en el reporte.
- [ ] **La misma cuenta de tests** que antes y el **mismo umbral de cobertura** (§2).
- [ ] Los **dos tests de concurrencia** corren en serie, pasan, y su sitio está escrito para quien escriba el siguiente (§3).
- [ ] La base de test vive **en memoria y aparte** de la de desarrollo, con su límite declarado (§4).
- [ ] La caché de PHPStan **sobrevive** entre ejecuciones.
- [ ] Ningún test borrado, saltado ni relajado; si alguno dependía del orden, **arreglado y reportado** (§5).
- [ ] **CI en verde** con sus tres *jobs*.
- [ ] `composer check`, `composer tenancy` y `npm run check` en verde, **uno detrás de otro** (§6).
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Los números: antes y después, por receta, y con cuántos procesos.
2. Dónde quedaron los tests de concurrencia y cómo se entera la próxima tarea que escriba uno.
3. Qué pasó con la cobertura en paralelo: los dos números de §5 y qué recomiendas.
4. Qué tests dependían del orden sin que se supiera, uno por uno, y qué los sostenía.
5. Si el *deadlock* al sembrar planes sigue vivo, y qué es exactamente.
6. Qué queda sin resolver de lo que hoy hace lenta la sesión, aunque no sea de esta tarea.
7. Nada más. No propongas siguientes pasos.
