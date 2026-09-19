# PROMPT 18 — `T-019` · Contratos de tasa

> Hito **H1 · Aritmética y tasa** · Tarea `T-019` · Fase `A` · 1 pt
> Abre `REQ-FX-002` y `REQ-FX-003` (no los cierra: son `T-020` y `T-021`).
> Depende de `T-016` (`1fd353f`, cierre de H0).

> ⚠️ **Se ejecuta antes que `T-017`, y es deliberado.** `Money::convertTo(Currency $target, RateSnapshot $rate)` nombra un tipo que declaras tú, y la dependencia es de un solo sentido: `RateSnapshot` no menciona `Money`. El plan ya lo recoge en la línea "Depende de" de `T-017`.

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-019: contratos de tasa`.

## 0. Qué vas a hacer

**H0 quedó cerrado.** Un negocio se registra, entra, invita a su equipo, configura sus ajustes y declara dónde le pagan — y todo eso queda auditado y aislado de las demás organizaciones. Lo que no hay todavía es una sola cifra de dinero.

H1 lo arregla, y empieza por aquí: **los contratos de la tasa de cambio**. Es una tarea pequeña de declaración pura, y su valor está en que fija las formas que van a usar los tres hitos siguientes.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → **contratos compartidos** y **`REQ-FX-002`, `REQ-FX-003`** | el bloque de código del encabezado es **normativo**: cópialo, no lo mejores |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-019` y `T-020` | qué es tuyo y qué es de la ingesta |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§4 y §6** | la tabla `exchange_rates` y la forma del provider |

## 2. Alcance

**Sí:**

1. **`RateSource`** (enum, tres casos) y **`RateSnapshot`** (ver §4 sobre `isStale()`).
2. **`ExchangeRateProvider`** (interfaz).
3. **`ExchangeRate`** (modelo y migración de `exchange_rates`), con su factory.
4. **`ExchangeRateUnavailableException`** y **`MissingExchangeRateException`**.
5. **`RateView`** en TypeScript.
6. Sus tests de estructura.

**No:**

- **No** implementes `DolarApiProvider` ni `ManualRateProvider`, ni `ExchangeRateService`, ni el job `FetchExchangeRate`, ni `/api/tasa`: todo eso es **`T-020`**.
- **No** implementes la fuente de tasa por organización (`REQ-FX-003` completo): es **`T-021`**.
- **No** toques `Money`: es `T-017`, la tarea siguiente.
- **No** implementes el snapshot inmutable en operaciones (`REQ-FX-006`): es `T-023`.

## 3. La tasa no es del tenant, y eso hay que decidirlo bien

Esta es la decisión que importa de la tarea, y conviene pensarla antes de escribir la migración.

`exchange_rates` guarda **la tasa del BCV y la del paralelo**, que son las mismas para todo el mundo: no son un dato de ninguna organización. Si la tabla llevara `organization_id`, cada negocio guardaría su copia de la misma cifra y la ingesta tendría que escribir N filas por lectura — justo lo que `T-020` tiene prohibido cuando exige **una sola petición HTTP** para todas.

Lo que **sí** es del tenant es *qué fuente usa cada organización* y su tasa manual, y eso ya vive en `organization_settings` desde `T-014` (`exchange_rate_source`, `exchange_rate_manual`).

Consecuencia práctica: el modelo `ExchangeRate` **no lleva `BelongsToOrganization`**, y por tanto tiene que aparecer en la clasificación del gate de aislamiento como lo que es. Lee `REQ-QAX-008` RB-4b antes de decidir dónde encaja, y deja escrito el porqué. Si la suite de tenancy se queja, no la silencies: te está preguntando algo legítimo.

## 4. `isStale()` se implementa, aunque esto sea Fase A

La Fase A separa estructura de lógica para que no queden algoritmos a medio construir, **no para producir muñones**. `RateSnapshot` es un *value object* de cuatro propiedades cuyo único método son tres líneas; dejarlo vacío exigiría un `throw` provisional que PHPStan nivel 6 te obligaría a justificar y que alguien tendría que acordarse de quitar. Un tipo con un método que miente es peor que un tipo completo.

Y ojo con **cuál** de las dos fechas mide: la obsolescencia se mide sobre **`publishedAt`**, no sobre `fetchedAt`. El comentario de la spec lo dice y el motivo es que son preguntas distintas: `publishedAt` es cuándo el BCV publicó esa tasa —la antigüedad que le importa al negocio— y `fetchedAt` es cuándo la leímos nosotros. Medir sobre `fetchedAt` haría que una tasa de hace una semana pareciera fresca por haberla releído hace un minuto, que es el error exacto que tener las dos columnas viene a evitar.

## 5. Dos detalles que no son de estilo

**1. `rate` es un `string` decimal, nunca un `float`.** Está en la spec y es la misma regla que hace que el dinero vayan en centavos enteros: `REQ-FX-001` RB-1 prohíbe `FLOAT`/`DOUBLE` en el esquema y en el código, y `REQ-FX-001` RB-5 convierte con `bcmul` a escala 8. Una tasa en `float` reintroduce por la puerta de atrás el error de redondeo que todo el diseño monetario evita.

**2. Las dos excepciones no son la misma.** `ExchangeRateUnavailableException` es *la fuente no respondió* —un problema de ingesta, reintentable—; `MissingExchangeRateException` es *no hay ninguna tasa que usar* —un problema de negocio, que bloquea la operación—. Que sean dos tipos es lo que permite que `T-020` reintente una y que `T-022` muestre la otra al usuario. Si te parecen redundantes, es que aún no has visto quién las captura.

## 6. Hecho cuando

- [ ] Los tipos del bloque de contratos compartidos de [13](../specs/13-bimoneda-y-tasa-de-cambio.md) existen **con la forma exacta de la spec**.
- [ ] `RateSnapshot` lleva **`publishedAt` y `fetchedAt`**, y `isStale()` mide sobre `publishedAt`, con test.
- [ ] `rate` es `string` decimal en el VO y en el esquema; **ningún `FLOAT`/`DOUBLE`**.
- [ ] `exchange_rates` migrada, con su modelo y su factory.
- [ ] `ExchangeRate` **no es del tenant**, y su clasificación en el gate de aislamiento está decidida y justificada.
- [ ] `RateView` en TS refleja el contrato, con `rate: number | null` y `isStale`.
- [ ] `tenancy:audit` y la suite de tenancy en verde; toda ruta nueva declara superficie y supervivencia a suspensión.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 7. Reporta al terminar

1. Dónde encaja `ExchangeRate` en el gate de aislamiento y por qué — no basta con que la suite pase.
2. Qué forma le diste a `exchange_rates` y qué índice la sostiene, pensando en que `T-020` consulta la vigente en cada petición con precios.
3. Qué dejaste declarado y sin implementar, y de qué tarea es cada cosa.
4. Si el bloque de contratos de [13](../specs/13-bimoneda-y-tasa-de-cambio.md) chocó con algo ya construido, qué y cómo lo resolviste. **No alteres el contrato para que encaje**: repórtalo.
5. Nada más. No propongas siguientes pasos.
