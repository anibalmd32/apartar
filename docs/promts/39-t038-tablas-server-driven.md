# PROMPT 39 — `T-038` · Tablas server-driven

> Hito **H2 · Catálogo** · Tarea `T-038` · Fase `B` · 3 pts · **cierra H2**
> **Cierra `REQ-BOF-045`**, y con él dos huecos que el plan dejó asignados aquí (§7 y §8).
> Depende de `T-027` (el listado de productos), `T-031` (composición de catálogos), `T-034` (`SortWhitelist`, ya escrita) y `T-036` (`c856b29`, los resolutores de tenant).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 89 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-038: tablas server-driven`.

## 1. Cómo correr las pruebas

Mismo orden de siempre, con un añadido al final:

1. **Mientras trabajas**, solo lo que tocas.
2. **Los baratos antes de la vuelta completa**: `composer lint`, `composer analyse`, `composer test:quality`.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio, sin sondear en segundo plano. **Una sola vuelta completa.**
5. **`npm run check` pasó de 34 s a 3 min 28 s en la tarea anterior**, y nadie sabe por qué. Córrelo una vez como `npm run check:steps`, que ejecuta los cinco pasos por separado (`lint`, `format:check`, `types`, `test`, `build`), **anota cuánto tarda cada uno** y dilo en el informe. Es un dato, no un encargo: no lo optimices.

## 2. Qué vas a hacer

La tabla del backoffice: la que pagina, ordena y filtra **en el servidor**, y que van a usar cobranza, clientes, comprobantes y suscriptores cuando lleguen. Es la última tarea de H2 y la que deja la infraestructura puesta antes de que H3 empiece a crear listados.

Y hay una razón menos abstracta para hacerla ahora: **hoy el listado de productos hace `->get()` sin límite**. Un negocio del plan Business puede tener 2.000 productos, y esa pantalla se los manda todos al navegador. No es una mejora pendiente, es la pantalla más usada del backoffice trayéndose la tabla entera en cada visita.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/32-backoffice-clientes-y-cobranza.md](../specs/32-backoffice-clientes-y-cobranza.md) → **`REQ-BOF-045`** | tu requisito entero; **RB-4, RB-6, RB-9 y SP-8** son los que se tuercen |
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → `REQ-BOF-019` RB-9, `REQ-BOF-020` SP-7 | qué filtros lleva el listado de productos y la marca de «quién lo modificó» |
| [docs/specs/12-catalogos-curados.md](../specs/12-catalogos-curados.md) → `REQ-CAT-023` SP-4 | la otra mitad de esa marca |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → **`T-038`** | tus **tres** bloques: el requisito y los dos huecos asignados |
| `app/Support/SortWhitelist.php` | **escrita y probada en `T-034`**: genérica sobre cualquier enum. Úsala, no la reescribas |
| `app/Http/Controllers/Backoffice/AuditLogController.php` | ahí vive **la primera copia a mano** de lo que vas a escribir (§6) |
| `app/Http/Controllers/Backoffice/ProductController.php` → `index()`, `listing()`, `filters()` | el listado que vas a convertir |
| `app/Http/Middleware/ResolveCatalogTenant.php`, `ResolveStorefrontTenant.php` | los dos resolutores de §8 |
| `resources/js/types/catalog-view.ts` → `Paginated<T>` | **ojo**: ya existe un tipo con ese nombre y otra forma (§5) |

## 4. Alcance

**Sí, en este orden:**

1. **`ResolvesTableState`** (`app/Http/Controllers/Concerns/`), montando `SortWhitelist`.
2. **`useServerTable`** y **`DataTable`** con sus cuatro subcomponentes, en `resources/js/features/data-table/`.
3. **El listado de productos** pasa a tabla server-driven, con sus filtros actuales (`REQ-BOF-019` RB-9).
4. **`AuditLogController`** adopta `ResolvesTableState` (§6).
5. **Los dos `updated_by`** con su migración y su columna en los listados (§7).
6. **La consulta de sobra de los resolutores de tenant** (§8).
7. Sus tests, incluido el de fuga de SP-8.

**No:**

- **No** conviertas los listados pequeños —equipo, taxonomía, cuentas de cobro, catálogos—: RB-6 los deja con paginación completa y hoy no la necesitan. El de catálogos **sí** gana su columna de §7, y nada más.
- **No** escribas tablas de cobranza, clientes ni comprobantes: esas pantallas son de H3 y H4, y lo que aquí se construye es lo que van a usar.
- **No** toques `SortWhitelist` (§3).
- **No** metas selección múltiple, edición en línea ni columnas configurables: fuera de alcance explícito.

## 5. Ya existe un `Paginated<T>` y **no** es el tuyo

`resources/js/types/catalog-view.ts` declara `Paginated<T>` como `{ data, nextCursor }`: paginación por cursor, sin total y sin número de página, porque en una superficie pública `count(*)` está prohibido y nadie pide «la página 7» de un catálogo.

El contrato de `REQ-BOF-045` necesita otra cosa —página, `perPage`, orden— y el backoffice **sí** puede contar en las tablas pequeñas. Son dos formas distintas con el mismo nombre, y dejarlas así garantiza que alguien importe la que no es y lo descubra en tiempo de ejecución.

Decídelo y **escríbelo en la spec**: o el tipo del backoffice se llama de otra manera, o `Paginated<T>` se generaliza de forma que las dos formas quepan sin que una pueda pasar por la otra. Lo que no vale es dos declaraciones homónimas en dos ficheros.

## 6. Escribes `ResolvesTableState` y ya hay una copia a mano

`AuditLogController` resuelve hoy su propia página, su propio `perPage` y su propio orden. Funciona, y esa es exactamente la razón por la que hay que absorberlo **en este commit**: un segundo mecanismo que funciona no lo arregla nadie nunca, y la convención del proyecto ya lo dice en «Cómo leer este plan» —una lista de casos, o una forma de resolver el estado, es propiedad del tipo y no de cada consumidor—. Van cinco censos sueltos en este proyecto; este sería el sexto.

Si al absorberlo aparece algo que el audit hace y `ResolvesTableState` no contempla, eso no es una excepción del audit: es un requisito que le faltaba al *concern*.

## 7. «Quién lo modificó» lleva dos requisitos abierto

`REQ-BOF-020` SP-7 y `REQ-CAT-023` SP-4 piden los dos la marca de última modificación **con su autor**, y ni `products` ni `catalogs` tienen columna que lo registre: `T-027` y `T-031` dejaron la fecha y el autor sin resolver. Son dos `updated_by` con su migración, y las pinta esta tarea porque es la que toca esos listados.

Dos cosas que no se pueden confundir:

- **No sale de la auditoría.** Componer un catálogo o renombrar un producto **no son** eventos de la lista cerrada de `REQ-BOF-005` RB-1, y no se añaden: esa lista es cerrada a propósito.
- **`T-031` hizo que componer marque el catálogo como modificado**, para que la marca hable de la composición y no del último cambio de nombre. El `updated_by` tiene que contar la misma historia que esa fecha, o serán dos columnas contiguas contradiciéndose.

## 8. Una consulta de sobra, en el sitio donde se va a copiar

`T-036` la dejó medida: los resolutores de superficie cargan el modelo del *slug* para fijar la organización, y `SubstituteBindings` lo carga **otra vez** para el *binding*. Una consulta de más en la vitrina, y otra en cada catálogo y cada ficha.

Se arregla donde nace: el middleware entrega al parámetro de ruta el modelo que ya tiene, y el *binding* lo respeta. **Sin tocar la guarda** que cada resolutor lleva para detectar que alguien reordenó los middlewares — esa mira el parámetro **al entrar**, así que sigue haciendo su trabajo.

Es aquí y no más tarde por calendario: **H3 abre el portal del cliente con un cuarto resolutor**, y lo que se copie de un patrón con una consulta de sobra la lleva también. Arreglarlo hoy son dos sitios; dentro de un hito, cuatro.

## 9. Cinco cosas que se tuercen solas

1. **`@tanstack/react-table` en modo manual** (RB-1): `manualPagination`, `manualSorting`, `manualFiltering`. Si la librería ordena en el cliente, ordena **la página que tiene**, no la tabla: el resultado parece correcto y está mal, y con 25 filas de 2.000 nadie lo nota hasta que toma una decisión con la primera fila.
2. **El orden solo acepta columnas de la lista blanca** (RB-4, SP-1). No es cosmética: sin ella, `sort` es texto de la petición llegando a un `ORDER BY`, que es inyección y fuga de esquema a la vez. `SortWhitelist` ya resuelve esto con un enum; un `sort` que no existe **cae al defecto sin error**.
3. **La URL compartida entre organizaciones muestra los datos de cada una** (SP-8). Es el test que importa de esta tarea: dos miembros de negocios distintos abren la **misma** URL con los mismos filtros y cada uno ve lo suyo. El *global scope* lo garantiza; el test afirma que sigue garantizándolo cuando el estado viaja en la query string.
4. **`page` desbordada no da `404`** (SP-3) y **`perPage` fuera de {25, 50, 100} cae a 25** (SP-2). Lo escribe cualquiera y nada de eso es un error del usuario.
5. **Ningún componente de tabla supera 200 líneas** (RB-9), y son **líneas de código**, sin blancos ni comentarios, como ratificó `REQ-QAX-001` RB-3. `DataTable` compone `TableHeader`, `TableBody`, `TablePagination` y `TableToolbar`; si uno crece, es que hace dos cosas.

## 10. Hecho cuando

- [ ] **`sort` no permitido cae al defecto**, sin error (RB-4, SP-1), por `SortWhitelist`.
- [ ] **`perPage` fuera de lista cae a 25** (SP-2); **`page` desbordada no da error** (SP-3).
- [ ] **Recarga parcial pide solo `rows` y `meta`** (RB-2), con `preserveState` y `preserveScroll`.
- [ ] El estado **se restaura desde la query string** (RB-3, SP-6).
- [ ] **URL compartida entre organizaciones muestra los datos de cada una** (SP-8, test de fuga).
- [ ] Filtro con valor inválido **se ignora y se avisa** (SP-4); fallo de red **conserva la tabla y ofrece reintentar** (SP-5).
- [ ] **El listado de productos pagina en el servidor** y ya no trae la tabla entera, con sus filtros de `REQ-BOF-019` RB-9.
- [ ] **`AuditLogController` usa `ResolvesTableState`** y no su propia resolución (§6).
- [ ] **`updated_by` en `products` y `catalogs`**, con su migración y su columna en los listados, contando lo mismo que la fecha (§7).
- [ ] **Los resolutores de tenant no cargan dos veces el mismo modelo**, y su guarda de orden de middlewares sigue en pie (§8).
- [ ] **`Paginated<T>` deja de estar declarado dos veces con dos formas** (§5), y la decisión está escrita en la spec.
- [ ] Estados de carga, vacío y error (RB-7); en móvil, **tarjetas apiladas sin scroll horizontal** (RB-8).
- [ ] **Ningún componente de tabla supera 200 líneas de código** (RB-9).
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro, **en una sola vuelta**.
- [ ] **Commit hecho.**

## 11. Reporta al terminar

1. Cómo resolviste la colisión de `Paginated<T>` y qué escribiste en la spec.
2. Qué hacía `AuditLogController` que `ResolvesTableState` no contemplaba, si es que había algo, y cómo quedó.
3. Cuántas consultas hace el listado de productos con filtros y orden, y cuántas hacía antes.
4. Cuántas consultas ahorró §8 en cada una de las tres superficies públicas, medidas.
5. Qué historia cuenta `updated_by` en un catálogo al que solo se le añadió un producto, y por qué es la correcta.
6. El desglose de los cinco pasos de `npm run check:steps` con sus tiempos (§1.5).
7. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
8. Nada más. No propongas siguientes pasos.
