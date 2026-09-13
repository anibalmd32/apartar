# PROMPT 08 — `T-007` · `tenancy:audit` y la suite de aislamiento

> Hito **H0 · Cimientos** · Tarea `T-007` · Fase `AB` · 3 pts
> Cierra `REQ-QAX-008`.
> Depende de `T-005` (`93fca2b`), `T-008` (`0ba84c9`) y `T-006` (`e7d306c`): `TenantContext`, `GlobalUniqueExceptions`, modelos del tenant reales, middlewares y `DeclaresTenantSurface`. 330 tests en verde.

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 87 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

## 0. Qué vas a hacer

El **gate que no admite excepciones**. `REQ-QAX-008` RB-1 lo dice sin matices: una fuga entre organizaciones no es un bug de gravedad alta, es el fin del producto. Sin deuda técnica, sin baseline, sin "lo arreglamos luego".

Esta tarea llega ahora, con cinco rutas en la aplicación, y **eso es deliberado**. Escrita cuando haya ochenta endpoints, ninguno quedaría cubierto retroactivamente. Escrita hoy, cada endpoint que nazca entra en la suite solo.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/91-calidad-y-gates.md](../specs/91-calidad-y-gates.md) → **`REQ-QAX-008`** | el requisito completo, y es largo: nueve `SP-`, la tabla de superficies de RB-3 y las comprobaciones de RB-4, RB-4b y RB-4c |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-007` | alcance y los **hallazgos de `T-008` que este gate debe recoger** |
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) **§7** | la tabla de rutas; las marcadas `T` son las que generan caso |
| [docs/specs/04-tenancy-y-organizaciones.md](../specs/04-tenancy-y-organizaciones.md) → `REQ-ORG-002` | **RB-3** y **RB-6** (`404`, nunca `403`) |

## 2. Alcance

**Sí:**

1. **`app/Console/Commands/TenancyAudit.php`** con las **cuatro comprobaciones estáticas de RB-4**, más las de RB-4b (las dos grafías, la lista cerrada de rutas permitidas) y **RB-4c** (factory ↔ trait).
2. **`tests/Feature/Tenancy/CrossTenantAccessTest.php`** con la **parametrización derivada de la tabla de rutas**, construida **desde el router**, no desde una lista escrita a mano.
3. **`tests/Feature/Tenancy/JobTenantContextTest.php`** — consolida aquí los tests de job que `T-006` ya escribió.
4. **`tests/Feature/Tenancy/BroadcastChannelIsolationTest.php`** y **`AccountBoundaryTest.php`** — sus objetos todavía no existen (canales en H7, `Account` en H6). Créalos con la parte que **sí** se puede afirmar hoy (ver §4).
5. El job `tenancy` de CI pasa a ejecutar `migrate` → `tenancy:audit` → la suite.

**No:**

- **No** crees rutas de negocio para tener algo que probar. Si la suite hoy cubre pocas rutas, que cubra pocas: lo que se entrega es el mecanismo.
- **No** añadas ninguna excepción a `GlobalUniqueExceptions`. Si algo no pasa, es el algo lo que está mal.

## 3. Lo único que de verdad hay que acertar: el gate tiene que fallar cerrado

`SP-1` es el corazón de la tarea. La parametrización se construye desde la tabla de rutas **para que un endpoint nuevo entre automáticamente y falle si no está cubierto**.

De ahí se sigue una consecuencia que es fácil de incumplir sin darse cuenta: **una ruta que el mecanismo no sabe clasificar tiene que hacer fallar la suite, no saltarse el caso.** Un `markTestSkipped` para lo desconocido convierte el gate en decorativo el día que alguien añada una ruta que no encaje en los patrones previstos — que es exactamente el día en que hace falta.

La clasificación tiene una herramienta que `T-006` dejó puesta: `DeclaresTenantSurface`. Recorre las rutas, pregunta a sus middlewares qué superficie gobiernan, y **toda ruta que no declare superficie es un fallo**, no un caso omitido.

Con cinco rutas esto parece exagerado. Con ochenta será lo único que impida que el gate se degrade.

## 4. Las dos suites cuyos objetos aún no existen

No las dejes vacías ni las llenes de código muerto. Cada una tiene una mitad afirmable hoy:

- **`BroadcastChannelIsolationTest`** — los tres canales llegan en H7. Lo que sí puede afirmarse ya: **que no hay ningún canal registrado sin su comprobación de pertenencia**. Hoy no hay ninguno, así que el test pasa por vacuidad — pero el día que se registre el primero sin autorización, falla. Escríbelo así, recorriendo los canales registrados, no como un fichero en blanco.
- **`AccountBoundaryTest`** — `Account` llega en `T-073`, pero **RB-6 es afirmable desde hoy** como test de arquitectura: ningún `Resource`, respuesta JSON ni exportación del backoffice expone `account_id`. Hoy no hay ninguno; el test crece solo.

Ese patrón —una aserción que hoy pasa sobre el conjunto vacío y falla en cuanto aparezca el primer elemento incorrecto— es preferible a un archivo vacío con un `TODO`, porque no depende de que nadie se acuerde.

## 5. Probar el detector, no solo ejecutarlo

El gate de `REQ-QAX-008` pide que `tenancy:audit` **detecte las cuatro clases de violación con casos inyectados**. No basta con que el comando salga con código 0 sobre el código actual: eso también pasaría si el comando no comprobara nada.

Hace falta, para cada comprobación, un caso que **inyecte la violación** y verifique que el comando la encuentra y la nombra:

1. un modelo con `organization_id` **sin** el trait;
2. un índice único **sin** `organization_id` que no esté en la lista blanca;
3. la exclusión del scope **en un archivo fuera** de la lista de RB-4b — en las dos grafías;
4. una tabla del tenant **sin** `organization_id` ni su índice;
5. una factory **sin** `CreatesWithinTenant` para un modelo que lleva el trait.

## 6. La lista blanca y su prueba

`GlobalUniqueExceptions` tiene que seguir conteniendo **exactamente** las tres excepciones y las dos tablas. `T-005` ya dejó el test que lo exige; verifica que sigue vigente y que el comando lo consume en lugar de tener su propia copia.

Recuerda las dos cosas que `T-008` descubrió y que la spec ahora recoge en **RB-4b**:

- la comprobación busca **las dos grafías**, `withoutTenantScope()` y `withoutGlobalScope(TenantScope::class)`;
- `app/Auth/TenantAwareUserProvider` está en la lista de rutas permitidas, y su motivo está escrito: el login resuelve al usuario antes de conocer la organización.

## 7. Hecho cuando

- [ ] `tenancy:audit` detecta **las cinco clases de violación** con casos inyectados, y nombra el archivo o índice concreto.
- [ ] La suite de fuga se construye **desde el router**; una ruta sin superficie declarada **hace fallar la suite**.
- [ ] Un test demuestra lo anterior: una ruta inyectada sin clasificar hace fallar el mecanismo.
- [ ] El acceso cruzado devuelve **`404`, nunca `403`** (`REQ-ORG-002` RB-6).
- [ ] `JobTenantContextTest` consolida los casos de `T-006` y sigue en verde.
- [ ] Las dos suites diferidas afirman su mitad vigente y fallarán con el primer elemento incorrecto.
- [ ] El job `tenancy` de CI ejecuta `migrate` → `tenancy:audit` → la suite, sin `continue-on-error`.
- [ ] `GlobalUniqueExceptions` sigue con exactamente tres excepciones y dos tablas.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.

## 8. Reporta al terminar

1. Cómo clasifica la suite una ruta y **qué pasa exactamente** con una que no sabe clasificar.
2. Las cinco violaciones inyectadas: cómo las inyectaste y qué mensaje da el comando en cada una.
3. Cuántas rutas cubre hoy la suite y cuántas quedan sin cubrir, si alguna.
4. Qué afirma hoy cada una de las dos suites diferidas.
5. Si alguna comprobación de RB-4 resultó imposible de hacer estáticamente, cuál y por qué. **No la omitas en silencio.**
6. Nada más. No propongas siguientes pasos.
