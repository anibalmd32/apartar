# PROMPT 24 — `T-024` · Migraciones bloque 5

> Hito **H2 · Catálogo** · Tarea `T-024` · Fase `A` · 2 pts
> **Abre H2.** No cierra ningún requisito por sí sola: sostiene los diecinueve del hito.
> Depende de `T-004` (`036303e`, las migraciones de plataforma y el estilo que vas a seguir) y de `T-016` (`1fd353f`, que dejó armada la guarda que esta tarea dispara).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-024: migraciones bloque 5`.

## 0. Qué vas a hacer

Cerraste H1 con la aritmética y la tasa. **Esto abre el catálogo**: las ocho tablas sobre las que se apoyan los diecinueve requisitos de H2, desde el producto hasta el enlace público que un desconocido abre.

Es una tarea de Fase `A` —estructura, sin lógica de negocio— y aun así tiene dos trampas que no son de escribir migraciones. Están en §4 y §5, y las dos hay que resolverlas **en este mismo commit**.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/05-design-tecnico.md](../specs/05-design-tecnico.md) → **§4 bloque 5** y **§4.1** | las ocho tablas con sus columnas e índices, y por qué `catalogs.slug` es único **global** |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-024` y `T-025` | lo que es tuyo y lo que es de la tarea siguiente; la nota de `T-024` tiene las dos trampas |
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) y [12-catalogos-curados.md](../specs/12-catalogos-curados.md) | qué significa cada columna; lee sobre todo `REQ-CAT-007` y `REQ-CAT-022`/`023` |
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → `REQ-BOF-020`, `REQ-BOF-023` | el financiamiento del producto y la taxonomía, que explican varias columnas |
| `app/Domain/Catalog/PublishedCatalogs.php` | **léelo entero antes de escribir la migración.** Su docblock te nombra |
| `app/Console/Commands/TenancyAudit.php`, `app/Domain/Tenancy/GlobalUniqueExceptions.php` | las dos listas blancas, con lo que exige entrar en cada una |
| `database/migrations/` | el estilo vigente: nombres, orden, comentarios y cómo se declaran las FK |

## 2. Alcance

**Sí:**

1. Las **ocho tablas** de [05 §4] bloque 5, con sus índices, sus `UNIQUE` y sus claves ajenas.
2. El **`FULLTEXT(name, description, sku)`** de `products` y el **`UNIQUE(slug)` global** de `catalogs`.
3. El **modelo `Catalog` en su forma mínima** y el cierre del relevo de `PublishedCatalogs::exist()` (§4).
4. La decisión sobre los **dos pivotes** y `tenancy:audit` (§5).
5. Las factories que necesiten tus propios tests, si los tienen.

**No:**

- **No** escribas `Category`, `Tag`, `Product`, `ProductSpecification`, `ProductStatus` ni `CatalogVisibility`: son **`T-025`**, la tarea siguiente.
- **No** completes `Catalog` más allá de lo que el relevo necesita (§4). Sus casts, relaciones y estados son de `T-025`.
- **No** escribas acciones, rutas, pantallas ni policies de catálogo. H2 entero viene detrás.
- **No** toques `media`: la publica `spatie/laravel-medialibrary` y ya está declarada en `TenancyAudit::NON_TENANT_TABLES`. Lo que sí es tuyo es **publicar su migración** si todavía no está.

## 3. Las ocho tablas, y lo que no se ve en la tabla de columnas

[05 §4] bloque 5 las lista con sus índices. Tres apuntes que la tabla no dice y que cuestan una migración correctiva:

- **`catalogs.slug` es único global**, no por organización, y es una de las **tres** excepciones declaradas en §4.1. Es la URL pública que alguien pega en una bio de Instagram: si dos negocios pudieran tener el mismo, el enlace dejaría de identificar a uno. `tenancy:audit` falla si aparece una cuarta excepción, así que esta tiene que estar declarada con su motivo, no descubierta.
- **`products.slug` y `products.sku` son únicos por organización.** Es lo contrario del anterior y por el motivo contrario: dos negocios distintos pueden vender el mismo modelo con el mismo SKU, y prohibirlo sería que el primero que llega se quede con el código de fábrica.
- **`catalog_product.created_at` es funcional, no metadato** ([05 §4], nota): ordena el feed y define qué es novedad (`REQ-CAT-023` RB-3). Eso significa que el pivote lleva `created_at` **explícito** y que reordenar productos dentro del catálogo no lo toca. Si lo escribes como `timestamps()` por costumbre, aparece un `updated_at` que nadie usa y que invita a tocarlo.

## 4. La trampa que hace roja la suite: `PublishedCatalogs`

**Lee `app/Domain/Catalog/PublishedCatalogs.php` antes de escribir la migración de `catalogs`.**

Esa clase sostiene `REQ-ORG-006` SP-5 —cambiar el slug del negocio con catálogos publicados da `422`, porque rompería los enlaces ya compartidos—. Hoy responde que no hay ninguno publicado, que es literalmente cierto porque la tabla no existe. **En cuanto tu migración la cree, lanza.**

Eso no es un accidente: la guarda nació al revés y `T-016` la invirtió a propósito. Con el `false` silencioso, `REQ-ORG-006` SP-5 habría quedado **desactivado** desde tu commit hasta `T-032` —ocho tareas— sin un solo test en rojo, y el síntoma no aparece hasta que un negocio renombra su enlace y descubre a los tres días que nadie llega.

Así que **el relevo lo cierras tú**, en este commit, como `T-054` hará con `RecordedReceipts`. El cuerpo es el que el propio mensaje de la excepción te dicta:

```php
Catalog::query()->whereNotNull('published_at')->exists()
```

que va por el *global scope* del tenant. Necesita el modelo, así que **adelanta `Catalog` en su forma mínima**: tabla, `BelongsToTenant` y nada más. `T-025` lo termina.

Lo que **no** vale es escribir el filtro de organización a mano en un `DB::table('catalogs')->where('organization_id', …)`: esa línea es exactamente la que `tenancy:audit` existe para que nadie escriba, y la que convierte el aislamiento en algo que hay que recordar.

Y el relevo no está cerrado hasta que un test lo ejercite **contra la tabla real**: que con un catálogo publicado la respuesta sea `true`, que sin ninguno sea `false`, y que **el catálogo publicado de otra organización no cuente** — que es la mitad que el *global scope* tiene que estar haciendo.

## 5. Los dos pivotes y `tenancy:audit`

`product_tag` y `catalog_product` no llevan `organization_id` en [05 §4]. `tenancy:audit` los va a reclamar en cuanto existan, y tienes dos salidas honestas:

- **declararlos** en `GlobalUniqueExceptions::tables()` con el motivo escrito, o
- **darles la columna**.

Elige razonando, no para que el comando calle. El argumento de un lado: son pivotes entre dos tablas que ya están aisladas, así que una fila solo puede existir si alguien ya tenía acceso a las dos. El argumento del otro: **`catalog_product` se consulta de forma directa y multi-organización** para ordenar el feed (`REQ-CAT-023` RB-3), y una tabla que se consulta sin pasar por sus padres no hereda su aislamiento — que es justo el motivo por el que `catalog_subscriptions` está declarada y no oculta.

Lo que decidas queda escrito donde se lea, y lo cuentas en el reporte.

## 6. Cuatro cosas que se tuercen solas

1. **El `FULLTEXT` es de MySQL y la suite corre contra el MySQL del contenedor.** No lo sustituyas por un índice normal ni lo dejes para `T-034`: `REQ-CAT-002` se apoya en él y una migración que lo añade después obliga a reconstruir el índice sobre una tabla ya cargada.
2. **`products.category_id` lleva FK `restrict`** (`REQ-BOF-023` RB-4), no `cascade`: borrar una categoría con productos dentro tiene que fallar, porque la alternativa silenciosa es que desaparezca el producto. `T-026` construye encima de eso.
3. **`price_cents`, `compare_at_price_cents` y `down_payment_cents` son `BIGINT` de centavos de USD** (`REQ-FX-001` RB-1). Ningún `DECIMAL` ni `FLOAT` en una columna de dinero, por el mismo motivo por el que existe `Money`.
4. **Toda tabla del tenant lleva su `organization_id` con su índice compuesto por delante**, en el orden en que se consulta. Un índice `(status, organization_id)` sirve de poco: el filtro que siempre está es el del tenant.

## 7. Hecho cuando

- [ ] Las **ocho tablas** existen con sus columnas, índices y claves ajenas de [05 §4] bloque 5.
- [ ] `catalogs.slug` es **único global** y está declarado en `GlobalUniqueExceptions` con su motivo; `products.sku` y `products.slug` son únicos **por organización**.
- [ ] `products` tiene su **`FULLTEXT(name, description, sku)`**.
- [ ] `catalog_product` lleva `created_at` **explícito** y no `timestamps()`.
- [ ] **`PublishedCatalogs::exist()` consulta de verdad**, con el modelo mínimo `Catalog`, y tiene test con dos organizaciones (§4).
- [ ] Los **dos pivotes** están resueltos y razonados (§5).
- [ ] `php artisan migrate:fresh` corre limpio, y `tenancy:audit` y la suite de tenancy quedan **en verde**.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Cómo cerraste el relevo de `PublishedCatalogs` y qué prueba que el catálogo de otra organización no cuenta.
2. Qué decidiste con `product_tag` y `catalog_product`, y con qué argumento.
3. Qué índices creaste que no estén literalmente en [05 §4], si alguno, y por qué hacía falta.
4. Si al leer las especificaciones del catálogo encontraste una columna que el diseño técnico no recoge, o al revés. **No la inventes ni la tapes**: dila.
5. Nada más. No propongas siguientes pasos.
