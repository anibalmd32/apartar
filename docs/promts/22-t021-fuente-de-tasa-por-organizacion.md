# PROMPT 22 — `T-021` · Fuente de tasa por organización

> Hito **H1 · Aritmética y tasa** · Tarea `T-021` · Fase `B` · 2 pts
> **Cierra `REQ-FX-003`.**
> Depende de `T-020` (`3fc80c2`, ingesta, `ExchangeRateService` y `ManualRateProvider`) y de `T-014` (`3b827c1`, pantalla de ajustes y `UpdateSettingsAction`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-021: fuente de tasa por organizacion`.

## 0. Qué vas a hacer

La ingesta es de plataforma; **la elección de fuente es de cada negocio**. Dos organizaciones pueden mostrar el mismo producto proyectado con tasas distintas, cada una con su sello.

Llevas además un añadido de alcance sobre el job de `T-020`: la guarda del 30 % puede vetarse a sí misma para siempre. Está en §5, con el diagnóstico hecho.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/13-bimoneda-y-tasa-de-cambio.md](../specs/13-bimoneda-y-tasa-de-cambio.md) → **`REQ-FX-003`** | tus ocho reglas y siete `SP-`; RB-5b y SP-1 son los que más se torcen |
| Mismo documento → `REQ-FX-002` **SP-2** y **SP-2b** | el añadido de alcance de §5 |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-020` y `T-021` | lo que ya existe y lo que no hay que rehacer |
| [docs/specs/30-backoffice-accesos-y-usuarios.md](../specs/30-backoffice-accesos-y-usuarios.md) → `REQ-BOF-005` | la tasa manual se audita, y **la redacción se aplica después de comparar** |
| `app/Domain/Exchange/`, `app/Domain/Settings/SettingKey.php`, `app/Actions/Settings/UpdateSettingsAction.php`, `routes/backoffice.php` | el terreno: la ruta que vas a escribir ya está anunciada en un comentario |

## 2. Alcance

**Sí:**

1. **`UpdateExchangeRateSettingsAction`**.
2. **`PUT /backoffice/ajustes/tasa`** con su método de controlador, su `FormRequest` y sus **dos declaraciones** por ruta (superficie y supervivencia a suspensión).
3. La **sección de tasa** en la pantalla de ajustes: selector de fuente y, junto a él, **la tasa vigente de cada fuente disponible con su antigüedad** (RB-6).
4. La **auditoría** del cambio (`REQ-BOF-005`).
5. **El añadido de alcance de §5.**
6. Sus tests.

**No:**

- **No** rehagas la resolución de lectura: existe. Ver §3.
- **No** implementes `MoneyLabel`, `CurrencyToggle` ni `RateStamp`: son **`T-022`**.
- **No** toques la conversión de las facturas de suscripción de RB-7: la fuente de plataforma se decide en `platform_settings` y eso llega con `REQ-SUB-006`, en **H5**.
- **No** añadas columnas ni tablas: la fuente y la tasa manual son **ajustes**, y sus dos claves ya existen en `SettingKey`.

## 3. La lectura ya existe; esto es el lado de escritura

`T-020` dejó funcionando y probado:

- `ExchangeRateService::current(?Organization)` → resuelve **la fuente configurada de esa organización** y devuelve su `RateSnapshot`.
- `ExchangeRateService::forSource(RateSource)` → la tasa de una fuente pública, sin organización. Es lo que necesita la pantalla de RB-6.
- `ManualRateProvider` → lee `exchange_rate_manual` de esa organización y devuelve el snapshot con `source = manual`.
- `SettingKey::ExchangeRateSource` y `::ExchangeRateManual`, con sus reglas de validación y su alcance.

Léelo antes de escribir nada. Si algo de ahí te estorba, dilo en el reporte en vez de duplicarlo: **dos caminos que resuelven la misma pregunta divergen**, y el día que lo hagan el síntoma será un catálogo proyectando con una fuente y su sello diciendo otra.

## 4. Por qué es una ruta aparte, y por qué las dos claves se guardan juntas

**Aparte de `PUT /ajustes`** por el mismo motivo por el que `T-014` separó identidad: son capacidades distintas de `REQ-ORG-003` RB-1, con policies distintas, y juntarlas obliga a autorizar por la más permisiva de las dos. El comentario de `routes/backoffice.php` ya anuncia esta ruta; ponla donde dice.

**Y las dos claves en la misma operación** porque SP-1 lo exige: cambiar a `manual` sin definir valor es `422`. No es una validación de formulario sino la única forma de que no exista el estado intermedio "fuente manual, tasa nula" — en el que `current()` devolvería `null` y **todos los catálogos de ese negocio se quedarían sin bolívares** hasta que alguien volviera a la pantalla. Un estado que la spec no admite no debe poder existir ni por un instante, ni siquiera dentro de una transacción.

## 5. Añadido de alcance: la guarda que se veta a sí misma

Está en el plan bajo `T-021` y en `REQ-FX-002` **SP-2b**, recién escrito. Resumen:

`FetchExchangeRate::isPlausible()` rechaza toda variación mayor al 30 % respecto a la última fila de esa fuente — **sin mirar la antigüedad de esa fila**. Y como el rechazo no persiste nada, la referencia **no avanza nunca**. Encadénalo: la fuente o nuestra ingesta caen una semana, el bolívar se mueve de verdad, y al volver *ninguna* lectura entra jamás. La plataforma entera se queda proyectando la tasa de la semana pasada, con un `Log::error` cada media hora que nadie lee.

Un umbral del 30 % es una **velocidad de cambio** y no hay velocidad sin base de tiempo: 30 % en media hora es una fila corrupta del proveedor, 30 % en cuatro meses es el bolívar.

La salida se escribe con la misma vara que la alarma: **la guarda se abstiene cuando su referencia tiene más de 24 h**, que es cuando `isStale()` y la alerta de plataforma de `REQ-PLT-006` RB-4 ya la dan por obsoleta. Mientras hay vigilancia, la guarda protege; cuando la referencia es tan vieja que la propia plataforma la declara caducada, deja de tener autoridad para vetar la única cifra reciente que existe. Lo que **no** se toca es el umbral: es lo que protege de una tasa corrupta.

Déjalo registrado en el log igualmente —aceptar por abstención no es lo mismo que aceptar por plausible— y pruébalo con un test que fije el veto vigente (referencia fresca, salto grande, se rechaza) y otro el levantado (referencia de más de 24 h, salto grande, entra).

## 6. Cinco cosas que se tuercen solas

1. **`manual` no inserta en `exchange_rates`** (RB-4). Esa tabla es de plataforma y guarda fuentes públicas; la tasa de un negocio es un dato suyo. Su histórico vive en la auditoría. Hay un test que lo exige.
2. **La manual lleva `publishedAt` = `fetchedAt` = fecha de la edición** (RB-3). Poner solo `fetchedAt` deja `publishedAt` vacío y la tasa se ve **siempre obsoleta**, porque `isStale()` mide sobre esa columna — y una tasa que el dueño acaba de fijar avisando de que está desactualizada es el aviso que enseña a ignorar todos los avisos.
3. **La antigüedad que muestra la pantalla es sobre `publishedAt`**, no sobre cuándo corrió nuestro job. Es la misma corrección que trajo `T-019` al contrato de `RateView` y por el mismo motivo.
4. **Elegir una fuente sin tasa reciente se permite** (SP-4): se muestra la última disponible con su sello, y si no hay ninguna, solo USD. Bloquear la elección por falta de tasa sería violar `REQ-FX-005` RB-1 desde la pantalla de ajustes.
5. **Cambiar de fuente no recalcula nada** (SP-7). Los pagos conciliados conservan su snapshot y las cuotas están en USD. Si te ves escribiendo un recorrido de apartados, párate: es `REQ-FX-006` y no es de nadie todavía.

## 7. El test de RB-1, y lo que aún no existe

El gate pide **"dos organizaciones con fuentes distintas proyectan importes distintos para el mismo producto"**. Los productos llegan en **H2**: no los inventes ni los adelantes. Prueba la regla donde ya se puede, que es donde de verdad vive: dos organizaciones con fuentes distintas, el **mismo importe en USD**, y `current()` de cada una devolviendo una tasa distinta que da dos importes en bolívares distintos con `Money::convertTo()`. Es exactamente la misma afirmación, sin catálogo de por medio.

## 8. Hecho cuando

- [ ] `PUT /backoffice/ajustes/tasa` solo del `owner`; `member` recibe `403` (SP-3).
- [ ] Cambiar a `manual` sin valor → `422`; manual ≤ 0 o con formato inválido → `422` (SP-1, SP-2).
- [ ] **`manual` no inserta fila en `exchange_rates`**, con test.
- [ ] La manual devuelve `publishedAt` = `fetchedAt` = fecha de edición, y **no** nace obsoleta.
- [ ] **Dos organizaciones con fuentes distintas dan dos importes en bolívares distintos** para el mismo USD (§7).
- [ ] La pantalla lista las fuentes disponibles con su tasa y su antigüedad **sobre `publishedAt`** (RB-6).
- [ ] Elegir una fuente sin tasa reciente **no** se bloquea (SP-4).
- [ ] El cambio queda auditado (`REQ-BOF-005`), con la redacción aplicada **después** de comparar.
- [ ] La caché de ajustes de esa organización se invalida al guardar.
- [ ] La ruta declara **superficie** y **supervivencia a suspensión**; `tenancy:audit` y la suite de tenancy en verde.
- [ ] **La guarda del 30 % se abstiene con referencia de más de 24 h** (§5), con los dos tests.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Cómo garantizas que no existe el estado "fuente manual, tasa nula", ni siquiera transitorio.
2. Qué hace la pantalla cuando una de las fuentes disponibles **no tiene ninguna tasa** todavía.
3. Cómo probaste RB-1 sin productos, y si la prueba te convence.
4. Cómo quedó la abstención de la guarda del 30 %: qué se registra cuando entra por esa vía, y cómo distingues en el log una aceptación normal de una por referencia caducada.
5. Si al leer lo que dejó `T-020` encontraste algo que contradiga la spec, qué y dónde. **No lo tapes duplicando**.
6. Nada más. No propongas siguientes pasos.
