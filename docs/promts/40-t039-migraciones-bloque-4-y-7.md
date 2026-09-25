# PROMPT 40 — `T-039` · Migraciones bloque 4 y 7

> Hito **H3 · Apartado** · Tarea `T-039` · Fase `A` · 2 pts · **abre H3**
> Sin requisitos propios: es la tabla sobre la que se apoyan `REQ-IDN-001`, `REQ-IDN-003`, `REQ-LAY-001`, `REQ-LAY-002` y `REQ-LAY-004`.
> Depende de `T-038` (`d4d63db`, cierre de H2) y de las tablas de H0, que ya incluyen `accounts`.

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-039: migraciones bloque 4 y 7`.

## 1. Cómo correr las pruebas

Esta tarea no toca el frontend, así que la vuelta es corta:

1. **Mientras trabajas**: `docker compose exec -T app php artisan migrate:fresh` y la suite de tenancy, que es la que te va a hablar (`composer tenancy`).
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**—. **`npm run check` también**, aunque no toques `.tsx`: el gate es el gate. **Nunca a la vez.**
4. En primer plano y con *timeout* amplio. **Una sola vuelta completa.**
5. La vez pasada hicieron falta dos vueltas porque una suite se había pasado **antes** de un renombrado. Si cambias algo después de pasar una suite, esa suite ya no está pasada.

## 2. Qué vas a hacer

Las cuatro tablas sobre las que se construye H3: la ficha del cliente final, su token de acceso por enlace, el apartado y sus cuotas. No hay modelos, ni acciones, ni pantallas —eso es de `T-040` en adelante—: hay esquema, índices y los tests que afirman que el esquema dice lo que la spec dice.

Es una tarea de fase `A`, y eso significa que **lo que se decide aquí es caro de cambiar después**. Un índice que falta se añade; una columna que sobra se arrastra durante todo el hito.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → **§4 bloques 4 y 7**, y **§4.1** | tus cuatro filas de tabla, con sus columnas e índices, y la lista cerrada de excepciones |
| [docs/specs/10-identidad-y-autenticacion.md](../specs/10-identidad-y-autenticacion.md) → `REQ-IDN-001` RB-5, `REQ-IDN-003`, `REQ-IDN-008` RB-3 | por qué `customers` no tiene contraseña y por qué el token va como va |
| [docs/specs/20-apartado-y-cronograma.md](../specs/20-apartado-y-cronograma.md) → `REQ-LAY-001`, `REQ-LAY-002`, `REQ-LAY-004` | qué significa cada columna de `layaways` e `installments` |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → **`T-039`**, `T-040`…`T-042`, `T-054` | qué es tuyo y qué no (§4) |
| `app/Domain/Tenancy/GlobalUniqueExceptions.php` | la lista cerrada, en código: `layaways.code` **ya está** |
| `database/migrations/…_create_products_table.php`, `…_create_catalogs_table.php` | el patrón de migración de este proyecto, con sus comentarios de cabecera |
| `database/migrations/…_add_invitation_token_to_users_table.php` | **el precedente exacto** del token de §6 |

## 4. Alcance

**Sí:** las migraciones de `customers`, `customer_login_tokens`, `layaways` e `installments`, con sus columnas, sus índices y sus claves foráneas; y los tests de esquema que las afirman.

**No:**

- **No** escribas modelos, *casts*, `LayawayStatus`, `InstallmentStatus` ni `LayawayCode`: son `T-040`, `T-041` y `T-042`. La columna de estado es una columna; el enum llega después.
- **No** hagas `account_identities`: es del bloque 4 pero llega en **H6**, con las cuentas.
- **No** hagas `receipts`, `payments` ni `payment_allocations`: son del bloque 7 pero llegan en **`T-054`**, con las columnas de *snapshot* de tasa que `T-023` fue a buscar allí.
- **No** siembres datos ni escribas *factories* de negocio más allá de lo que tus tests necesiten.

## 5. `customers` no tiene contraseña, y eso se prueba

`REQ-IDN-001` RB-5: un cliente **nunca** tiene contraseña. No es una funcionalidad que falte, es el modelo de identidad del producto: se entra con documento + código, con enlace o con cuenta vinculada. Una columna `password` ahí sería una invitación a escribir un login que no debe existir, y a los seis meses nadie recordaría que la ausencia era deliberada.

Por eso [05 §4] dice que **la ausencia es verificable por test de esquema**, y por eso la escribes: un caso que afirma que `customers` no tiene esa columna, con un mensaje que diga por qué. Su gemelo ya debería existir del lado de `accounts` —sin documento ni teléfono (`REQ-ACC-001` RB-2)—; si no está, escríbelo también.

Y `UNIQUE(organization_id, account_id)` con `account_id` nullable: MySQL admite varios `NULL` en un índice único, así que un negocio puede tener mil clientes sin cuenta vinculada y ninguno colisiona. Es correcto y parece un error; déjalo dicho en el comentario de la migración, porque el que venga lo va a mirar dos veces.

## 6. El token del enlace **no** abre una cuarta excepción global

[05 §4] declaraba `customer_login_tokens.token_hash` como `UNIQUE` global. **Está corregido**: queda `UNIQUE(organization_id, token_hash)`.

El motivo está en §4.1: `GlobalUniqueExceptions` es una lista **cerrada en tres** —`users.email`, `catalogs.slug`, `layaways.code`— y `tenancy:audit` falla en cuanto aparece una cuarta. No es una formalidad: las tres que están comparten una propiedad —son claves que hay que resolver **antes** de saber en qué organización estamos— y un token de enlace no la tiene, porque `REQ-IDN-003` RB-3 ya obliga a emitir **un enlace por organización**.

El proyecto resolvió esta forma exacta en `T-013` con la invitación de equipo: **el token lleva delante el identificador de su organización** (`7.a8f3…`), el tenant se fija con ese prefijo antes de tocar la tabla, y el índice se compone. Lee esa migración; la tuya es la misma idea.

Tú solo escribes el índice. Lo que hace falta que sepas es que **`layaways.code` sí es global y ya está en la lista**: no la amplíes, no la toques, y tampoco la quites.

## 7. Cuatro cosas que se tuercen solas

1. **`due_date` es un día del calendario, no un instante.** Una cuota vence el día 15 en la zona horaria del negocio (`REQ-ORG-006` RB-7), y guardarla como `datetime` invita a que «vencida» dependa de la hora del servidor. Elige el tipo con eso en la cabeza y déjalo escrito.
2. **Todo el dinero en centavos, en enteros, y ni un `decimal` ni un `float`.** Es la regla del proyecto desde `REQ-FX-001`, y aquí nacen ocho columnas de dinero de golpe: es el sitio donde una se cuela.
3. **`remaining_cents` es `total_cents − paid_cents` escrito otra vez.** Está en la spec y tiene su razón —se ordena y se filtra por él—, pero es un segundo censo del mismo dato, y el día que discrepe de la resta nadie sabrá cuál es el bueno. No lo arregles: la tabla es la que la spec dice. Lo que te toca es **dejar escrito en la migración quién lo mantiene** —las acciones de pago, de H4— para que quien llegue no lo calcule por su cuenta en una tercera versión.
4. **`tenancy:audit` es el gate que no admite excepciones.** Las cuatro tablas llevan `organization_id`, y **todo** índice único lo incluye salvo `layaways.code`. Si el comando protesta, la respuesta no es ampliar la lista blanca.

## 8. Hecho cuando

- [ ] Las cuatro tablas existen con **las columnas e índices exactos de [05 §4]** bloques 4 y 7.
- [ ] **`customers` no tiene `password`**, afirmado por un test de esquema con su motivo (§5).
- [ ] **`accounts` no tiene documento ni teléfono**, afirmado igual (si no lo estaba ya).
- [ ] `customer_login_tokens` lleva **`UNIQUE(organization_id, token_hash)`**, no global (§6).
- [ ] **`layaways.code` es `UNIQUE` global** y `GlobalUniqueExceptions` **sigue teniendo tres entradas**.
- [ ] FK `restrict` en `layaways.product_id` (`REQ-LAY-002` RB-6); `catalog_id` nullable.
- [ ] `installments` con `UNIQUE(layaway_id, number)` e `INDEX(organization_id, status, due_date)`.
- [ ] Todo el dinero en **centavos enteros**; `due_date` con el tipo razonado (§7.1, §7.2).
- [ ] `migrate:fresh` limpio y **`migrate:rollback` también**: cada migración deshace lo suyo.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 9. Reporta al terminar

1. Qué tipo elegiste para `due_date` y por qué, con la consecuencia para «vencida».
2. Cómo quedó el índice de `customer_login_tokens` y qué dice tu migración sobre el prefijo del token.
3. Qué afirman exactamente los dos tests de ausencia de columna, y qué dicen sus mensajes.
4. Qué escribiste sobre quién mantiene `remaining_cents`.
5. Si `tenancy:audit` protestó por algo, qué era y cómo lo resolviste **sin** ampliar la lista blanca.
6. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
7. Nada más. No propongas siguientes pasos.
