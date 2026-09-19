# PROMPT 23 — `T-022` · Proyección bimoneda y toggle

> Hito **H1 · Aritmética y tasa** · Tarea `T-022` · Fase `B` · 3 pts
> **Cierra `REQ-FX-004` y `REQ-FX-005`.**
> Depende de `T-018` (`ed88816`, `Money`, `MoneyFormatter` y `lib/money.ts`), de `T-019` (`08a8d4e`, `RateView` y `RateSnapshot::isStale()`) y de `T-021` (`429d97e`, la fuente por organización y la pantalla que ya enseña tasas).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-022: proyeccion bimoneda y toggle`.

## 0. Qué vas a hacer

El dinero ya sabe sumarse y convertirse, y la tasa ya entra sola. Falta **cómo se ve**: el mismo importe en las dos monedas, con una de las dos destacada según lo que la persona haya elegido, y el sello que dice con qué tasa se calculó y de cuándo es.

Y su mitad menos vistosa, que es la que de verdad cierra `REQ-FX-005`: **cuando no hay tasa, el bolívar desaparece de la pantalla** — ni `Bs. 0,00`, ni un guion, ni un toggle que no hace nada. La tienda sigue vendiendo en dólares.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → **`REQ-FX-004`** y **`REQ-FX-005`** | tus dos requisitos enteros, con sus notas normativas: la de las etiquetas y la de RB-7 |
| Mismo documento → *Contratos compartidos* | `MoneyView`, `RateView` y por qué `rate` es el único `number` del sistema |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-021`, `T-022` y `T-033` | lo que acaba de cerrarse, lo que heredas y lo que **no** es tuyo |
| [docs/specs/01-asunciones-criticas.md](../specs/01-asunciones-criticas.md) → `ASU-FX-03`, `ASU-FX-07`, `ASU-FX-08` | el origen de la cookie de un año, del sello y del refresco |
| `app/Domain/Money/`, `app/Domain/Exchange/`, `resources/js/lib/money.ts`, `resources/js/types/{money,exchange}.ts` | el terreno, y los docblocks que ya dicen qué está prohibido hacer aquí |
| `resources/js/features/tenancy/components/ExchangeRateForm.tsx` | la única pantalla que hoy enseña tasas, y de donde sacas dos cosas |

## 2. Alcance

**Sí:**

1. **`ProjectsMoney`** (`app/Http/Resources/Concerns/`): el único sitio por el que un importe sale al cliente.
2. **`useCurrencyDisplay`**, **`MoneyLabel`**, **`CurrencyToggle`** y **`RateStamp`** en `resources/js/features/currency/`.
3. **Por dónde llegan la tasa y la preferencia a las page props**, y su resolución en el servidor (§4).
4. **Los dos realineados que heredas de `T-021`** (§5).
5. Sus tests: Vitest para lo de arriba y **tests PHP para la resolución del servidor**.

**No:**

- **No** emitas `currency_toggled` ni escribas un emisor de telemetría. Es `T-084`; está en §6.
- **No** cablees el refresco de cinco minutos contra `GET /api/tasa`: va con la primera página con precios, que es `T-033`, y así está escrito en el plan.
- **No** toques la marca de "tasa del día del pago" de RB-8: esos importes llevan el snapshot de `REQ-FX-006`, cuyas columnas son `T-023` y cuyo uso es H4.
- **No** hagas aritmética de dinero en el cliente. El docblock de `lib/money.ts` explica por qué, y no es una preferencia de estilo.
- **No** inventes una pantalla de muestra con precios falsos para poder enseñar el toggle. Los precios llegan en H2; §8 dice dónde se prueba esto hoy.

## 3. Lo que ya existe y no se rehace

- `Money`, `MoneyFormatter::format()` (`$1,234.56` y `Bs. 1.234,56`, con los negativos resueltos) y `Money::convertTo(Currency, RateSnapshot)`.
- `ExchangeRateService::current(?Organization)` → la tasa de la fuente que esa organización eligió, o `null`. `forSource()` y `sourceFor()` para lo demás.
- `RateView::of(?RateSnapshot, RateSource)` → lo que viaja al cliente, con `rate`, `publishedAt`, `isStale` y sus nulos.
- `RateSnapshot::isStale()` → el mismo predicado que usan la guarda de la ingesta y la alerta de plataforma. **No escribas un segundo criterio de 24 h.**
- `MoneyView`, `CentsUsd`, `centsUsd()`, `hasVes()` y `availableLabels()` en el lado TS, ya tipados y documentados.

## 4. Por dónde entra esto en las page props

`REQ-FX-004` RB-4 pide que **el primer render del servidor ya sea correcto**: si la preferencia se descubriera al montar el componente, la página abriría en USD y saltaría a bolívares un instante después, en cada visita de todo el que eligiera bolívares. Así que la resuelve el servidor y llega resuelta.

Dos datos, y los dos atraviesan todas las superficies con precios —catálogo, portal, feed y backoffice—, así que el sitio es **`HandleInertiaRequests`** y no cada controlador:

- **La preferencia**, en este orden: cookie válida → `currency_display_default` de la organización → `usd_primary`. Una cookie con basura **se descarta sin error** (SP-3) y cae al default; no es un `422` ni un log, es un valor que no reconocemos.
- **La tasa vigente** de la organización en contexto, como `RateView`. Fuera de un tenant no hay fuente que resolver, y eso se contesta explícitamente en lugar de dejar que reviente.

**La preferencia es de la persona, no del negocio** (RB-3): la cookie no se parte por organización y **no se reescribe al cambiar de una a otra**. Quien navega el catálogo de dos negocios en bolívares no quiere volver a pulsar el toggle en el segundo. El `currency_display_default` solo decide el arranque de quien todavía no ha elegido nada.

Ojo con la resolución de la tasa: la pinta el sello **y** la usa la proyección de cada importe. Si el sello sale de una consulta y los importes de otra, un despliegue a medio camino puede estampar una tasa distinta de la que multiplicó — el fallo más caro de todos, porque el número es creíble. **Una resolución por respuesta**, y memorizada donde muera con la petición: `T-020` ya se quemó con esto (`REQ-FX-003` RB-5b, el binding que no es singleton porque el worker de colas vive más que el job).

## 5. Los dos realineados que heredas

Los dejó `T-021` y están escritos en el plan y en los contratos compartidos de [13]:

1. **Las etiquetas de las fuentes están en un `.tsx`.** `ExchangeRateForm` guarda las tres cadenas en una constante propia, y tú necesitas las mismas para escribir «Tasa BCV del 18/09 09:00». Dos diccionarios de los mismos tres casos acaban llamando cosas distintas a la misma tasa en dos pantallas. **El nombre de la fuente sale del enum** —`PaymentMethod::label()` es el precedente— y viaja con los datos; lo que el desplegable añada para explicar la opción es presentación de esa opción, no un segundo nombre.
2. **`publishedAgo()` vive dentro de `ExchangeRateForm.tsx`.** Es la antigüedad en palabras, medida sobre `publishedAt`, y `RateStamp` la necesita entera. Se muda a la *feature* de moneda con su test, y la pantalla de ajustes pasa a consumirla. Si la copias, dentro de dos meses una dirá «hace 3 h» y la otra «hace 4 h» sobre la misma fecha.

## 6. `currency_toggled` no se emite aquí, y tampoco se finge

RB-7 dice que cada cambio emite el evento. El bus —`TelemetryEventName`, el buffer, el endpoint— es `T-084`, en H8.

Lo que **no** vale es dejar un `track()` que no hace nada para que el hook "ya esté preparado": un emisor que se traga los eventos en silencio es indistinguible de uno conectado, no hay test que se ponga rojo, y el día que llegue el bus nadie sabrá si alguna vez estuvo enchufado. Es literalmente el caso de `PublishedCatalogs` que `T-016` tuvo que arreglar. El hook cambia la moneda y ya; `T-084` tiene escrito en el plan que `currency_toggled` sale de aquí.

## 7. Seis cosas que se tuercen solas

1. **Sin tasa, el bolívar no se muestra de ninguna forma** (`REQ-FX-005` RB-4). Ni `Bs. 0,00` —que es un precio inventado—, ni `—`, ni «no disponible» donde va una cifra. `MoneyView.ves` es `null` y `hasVes()` es la única pregunta; el toggle se deshabilita **con tooltip** (SP-2), no desaparece: un control que se esfuma deja a la persona buscando lo que hizo mal.
2. **Y la ausencia tiene que ser la misma en los dos sitios.** Si el sello dice que no hay tasa y algún importe de esa página trae `ves`, uno de los dos miente. Sale de que los dos se resuelven una vez, en la misma respuesta (§4).
3. **El formateo es del servidor, y el toggle es puramente de cliente** (RB-6). `MoneyView` trae las dos cadenas hechas, así que cambiar de modo no genera petición. El día que alguien escriba un `.toFixed(2)` en el cliente, las dos monedas se puntúan al revés la una de la otra y el error se lee como un precio correcto.
4. **`isStale` es un aviso discreto, no un bloqueo** (RB-3 de `REQ-FX-005`). La tasa se sigue usando y el importe se sigue mostrando; lo que cambia es que al lado del sello aparece la advertencia. Bloquear por antigüedad viola RB-1, que es la regla que sostiene todo el requisito.
5. **La hora del sello.** `publishedAt` llega en ISO con el desfase de `APP_TIMEZONE`, y quien lo pinte decide en qué zona se lee. Hay **tres** candidatas y no dos: la del navegador de quien mira, la de la aplicación, y la de la propia organización —`organizations.timezone` es columna desde `T-004`, con `America/Caracas` por defecto—. `T-020` perdió una tarde con cuatro horas de desfase y el síntoma no pareció nunca una fecha. Elige, escríbelo donde se lea, y dilo en el reporte.
6. **El toggle sobrevive a que `localStorage` esté bloqueado** (SP-1). En modo privado se cae a la cookie; si tampoco, funciona en memoria durante la sesión. Lo que no puede es lanzar: un catálogo que no abre porque el navegador no deja guardar una preferencia es un catálogo que no vende.

## 8. Dónde se prueba esto hoy, que no hay ninguna página con precios

El catálogo es H2. Eso **no** convierte esto en andamio —`Money` se escribió entero en `T-018` sin un solo apartado—, pero sí obliga a elegir bien dónde vive cada prueba:

- **`ProjectsMoney`**, con sus tests PHP: con tasa compone las dos representaciones; sin tasa el `ves` es `null`; una tasa de 30 h marca `isStale` y **aun así proyecta**.
- **La resolución del servidor** (§4), con tests de *feature*: cookie válida manda; cookie corrupta cae al default sin error; sin cookie manda el ajuste de la organización; fuera de tenant, el default de plataforma.
- **El hook y los tres componentes**, en Vitest, con page props falsas: los dos modos con el formato exacto (`$20.00 (Bs. 850,00)` y `Bs. 850,00 ($20.00)`), sin tasa solo USD y toggle deshabilitado, `localStorage` inaccesible no rompe, la preferencia sobrevive al cambio de organización.
- **`RateStamp` y `publishedAgo()`**, contra la pantalla de ajustes, que es la única que hoy tiene `RateView` de verdad delante.

Si al escribir los tests te ves creando un producto o un catálogo, párate: no existen, y adelantarlos es empezar H2.

## 9. Hecho cuando

- [ ] Los dos modos renderizan con el **formato exacto** de RB-1 y RB-2.
- [ ] **Sin tasa: solo USD, `ves` a `null`, toggle deshabilitado con tooltip** — en ningún caso `Bs. 0,00` ni un guion.
- [ ] `localStorage` inaccesible **no rompe**; cookie corrupta cae al default sin error.
- [ ] La preferencia **sobrevive al cambio de organización** y el primer render del servidor ya es el correcto.
- [ ] `ProjectsMoney` compone `MoneyView` completo y decide entre proyectar y omitir VES, con sus tests.
- [ ] El sello escribe la fecha de **`publishedAt`** y marca la antigüedad con `RateSnapshot::isStale()`, sin un segundo criterio de 24 h.
- [ ] **El nombre de cada fuente sale del enum** y `ExchangeRateForm` consume esa lista (§5.1).
- [ ] **`publishedAgo()` vive en la *feature* de moneda** y la pantalla de ajustes la consume (§5.2).
- [ ] **Ningún emisor de telemetría**, ni real ni de mentira (§6).
- [ ] Si tocas alguna ruta, declara **superficie** y **supervivencia a suspensión**; `tenancy:audit` y la suite de tenancy en verde.
- [ ] Las recetas de calidad en verde —`composer` y `npm`—, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 10. Reporta al terminar

1. Cómo garantizas que el sello y los importes de una misma página hablan de la misma tasa.
2. Qué pasa exactamente en una pestaña donde `localStorage` lanza al leer **y** la cookie está corrupta, y por qué eso no es un error visible.
3. En qué zona horaria escribe el sello su `DD/MM HH:mm`, y por qué esa y no otra (§7.5).
4. Cómo quedaron los dos realineados de §5: dónde vive ahora cada cosa y qué consumidores tocaste.
5. Si al leer `T-021` o los contratos encontraste algo que contradiga la spec, qué y dónde. **No lo tapes duplicando.**
6. Nada más. No propongas siguientes pasos.
