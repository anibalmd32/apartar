# Módulo 31 — Inventario, fichas técnicas y catálogos (`BOF` 020+)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [11-catalogo-y-productos.md](11-catalogo-y-productos.md), [12-catalogos-curados.md](12-catalogos-curados.md), [50-planes-y-suscripciones.md](50-planes-y-suscripciones.md)
> Requisitos: `REQ-BOF-020` … `REQ-BOF-024`
>
> Todo en este módulo es **del tenant** y está sujeto a los **límites del plan**. Cada requisito declara qué métrica consume.

## Contratos compartidos del módulo

```php
/** Datos de alta o edición de producto, ya validados. */
final readonly class ProductData
{
    public function __construct(
        public string $name,
        public string $sku,
        public ?string $slug,
        public string $description,
        public int $categoryId,
        /** @var list<int> */
        public array $tagIds,
        public Money $price,
        public ?Money $compareAtPrice,
        public Money $downPayment,
        public int $installmentsCount,
        public int $frequencyDays,
        public int $stock,
        public ProductStatus $status,
        public ?CarbonImmutable $publishedAt,
        /** @var list<array{group:?string, label:string, value:string, position:int}> */
        public array $specifications,
    ) {}
}
```

```ts
export interface ProductFormValues {
  readonly name: string;
  readonly sku: string;
  readonly description: string;
  readonly categoryId: number | null;
  readonly tagIds: readonly number[];
  /** Decimales en string para no perder precisión en el input. */
  readonly price: string;
  readonly compareAtPrice: string;
  readonly downPayment: string;
  readonly installmentsCount: number;
  readonly frequencyDays: number;
  readonly stock: number;
  readonly status: 'draft' | 'published' | 'archived';
  readonly publishedAt: string | null;
  readonly specifications: readonly SpecificationFormRow[];
}

export interface SpecificationFormRow {
  readonly id: string;          // clave local del arreglo dinámico
  readonly group: string;
  readonly label: string;
  readonly value: string;
}
```

---

### REQ-BOF-020 · CRUD de productos y condiciones de financiamiento

**Historia de usuario**
> Como negocio, quiero crear y editar mis productos definiendo precio, cuota inicial y plan de cuotas, para publicar lo que ofrezco con las condiciones que yo decido.

**Contratos de datos**
- Rutas `GET /backoffice/productos`, `GET .../crear`, `POST .../`, `GET .../{product}/editar`, `PUT .../{product}`, `POST .../{product}/archivar`.
- `StoreProductRequest` / `UpdateProductRequest` → `UpsertProductAction`.
- **Consume:** métrica `products` del plan.

**Reglas de negocio y precondiciones**
- RB-1 Validaciones duras de coherencia financiera, en `CoherentFinancingTerms`:
  - `price > 0` y ≤ $1.000.000;
  - `down_payment >= 0` y **estrictamente menor** que `price`;
  - `installments_count` entre 1 y 36;
  - `frequency_days` entre 1 y 90;
  - `compare_at_price`, si existe, **mayor** que `price`;
  - `stock >= 0`.
- RB-2 Los importes se introducen en USD con dos decimales y se persisten en centavos (`REQ-FX-001`).
- RB-3 `sku` y `slug` son únicos **por organización** (`UNIQUE(organization_id, …)`): dos negocios pueden usar el mismo SKU sin interferir. El `slug` se autogenera del nombre y es editable mientras el producto no esté publicado.
- RB-4 Los valores por defecto de `installments_count` y `frequency_days` vienen de `organization_settings` (`REQ-ORG-006`), para que el negocio no los reescriba en cada producto.
- RB-5 Publicar exige: al menos una imagen, categoría asignada, descripción no vacía y condiciones financieras válidas. Un `draft` puede estar incompleto.
- RB-6 Cambiar precio, inicial, número de cuotas o frecuencia **no** afecta a apartados existentes (`REQ-LAY-002` RB-4) y **se audita** (`REQ-BOF-005`).
- RB-7 La UI muestra en vivo la previsualización del plan ("inicial $50 + 5 cuotas de $30 cada 15 días") usando el mismo cálculo del dominio que generará el cronograma real.
- RB-8 **Crear un producto consume la métrica `products`**; al alcanzar el techo, `422` con el plan que lo desbloquea (`REQ-SUB-004`). Archivar libera cupo (`REQ-CAT-007` RB-8).
- RB-9 El listado es una tabla server-driven (`REQ-BOF-045`) con filtros por estado, categoría, etiqueta y stock bajo, búsqueda por nombre y SKU, y una columna que indica **en cuántos catálogos** está cada producto (`REQ-CAT-007` RB-6).
- RB-10 El borrado es siempre lógico ("archivar").
- RB-11 Con la organización `suspended` no se pueden crear ni editar productos (`REQ-ORG-008` RB-3).

**Happy path**
1. El negocio crea un producto en `draft`; los campos de cuotas vienen precargados con sus defaults.
2. Completa datos, sube imágenes y añade la ficha técnica; la previsualización confirma "inicial $50 + 5 × $30".
3. Pulsa "Publicar"; la validación de completitud pasa y el producto queda disponible para añadirlo a catálogos.

**Sad paths y edge cases**
- SP-1 `down_payment >= price` → `422` con la regla explícita.
- SP-2 `compare_at_price <= price` → `422`.
- SP-3 Publicar sin imágenes o sin categoría → `422` con la lista de lo que falta.
- SP-4 SKU duplicado **en la misma organización** → `422`. En otra organización → permitido.
- SP-5 Editar el `slug` de un producto publicado → `422`.
- SP-6 Reducir `stock` por debajo de lo reservado → permitido con advertencia (`REQ-LAY-003` SP-2).
- SP-7 Dos miembros editando el mismo producto → gana el último; la UI muestra la marca de última modificación y su autor.
- SP-8 Importe con más de dos decimales → se rechaza en lugar de redondear en silencio.
- SP-9 Límite `products` alcanzado → `422`; se sugiere archivar productos obsoletos como alternativa a subir de plan, que es una salida honesta.
- SP-10 Archivar un producto presente en catálogos → permitido; deja de renderizarse y conserva la relación (`REQ-CAT-007` RB-7).
- SP-11 Producto de otra organización por manipulación de la URL → `404`.

**Fuera de alcance inmediato**
- Variantes de producto (talla, color).
- Importación masiva por CSV o Excel.
- Precios por volumen.
- Costos y márgenes.

**Gate de validación**
- Superficie esperada: `app/Actions/Product/UpsertProductAction.php`, `app/Http/Requests/Backoffice/StoreProductRequest.php`, `app/Http/Requests/Backoffice/UpdateProductRequest.php`, `app/Http/Controllers/Backoffice/ProductController.php`, `app/Rules/CoherentFinancingTerms.php`, `resources/js/pages/backoffice/products/Form.tsx`, `resources/js/features/products/hooks/useProductForm.ts`.
- Tipos esperados: `ProductData`, `ProductFormValues`, `ProductStatus`, `CoherentFinancingTerms`, `IncompleteProductException`, `PlanLimitReachedException`.
- Anti-`SHALLOW`: `UpsertProductAction::handle()` (comprobación de límite, transacción con producto, etiquetas y especificaciones, auditoría) y `CoherentFinancingTerms::validate()` (seis reglas cruzadas) superan 4 líneas.
- Tests: matriz de coherencia financiera con casos límite; publicar incompleto rechazado; **SKU duplicado en otra organización permitido** (test de tenancy); slug inmutable tras publicar; más de dos decimales rechazado; cambio de precio no afecta apartados y se audita; límite del plan bloquea y archivar libera; defaults de la organización aplicados; producto ajeno da `404`; `suspended` bloquea la creación.

---

### REQ-BOF-021 · Constructor dinámico de ficha técnica

**Historia de usuario**
> Como negocio, quiero añadir tantos pares de característica y valor como necesite, agrupándolos, para describir cada producto con el detalle que le corresponda.

**Contratos de datos**
- `SyncSpecificationsAction(Product $product, list<SpecificationInput> $rows)`, invocada desde `UpsertProductAction`.
- `product_specifications` con `organization_id`, `group?`, `label`, `value`, `position`.
- Formulario con `@tanstack/react-form` en modo arreglo dinámico.

**Reglas de negocio y precondiciones**
- RB-1 Filas dinámicas: añadir, eliminar y reordenar por arrastre, con `group` opcional por fila.
- RB-2 `label` y `value` obligatorios y no vacíos; `label` ≤ 60 caracteres, `value` ≤ 500.
- RB-3 Máximo **50** especificaciones por producto (límite técnico, no de plan).
- RB-4 `position` se **recalcula** contiguo desde 1 en cada guardado; el orden lo fija el arrastre, no el índice del formulario.
- RB-5 El guardado es un **reemplazo completo** transaccional (borrar y reinsertar), no un diff fila por fila: evita estados intermedios inconsistentes.
- RB-6 Los grupos se ofrecen como autocompletado con los grupos ya usados **en otros productos de la misma organización**; nunca se sugieren los de otro negocio.
- RB-7 `label` duplicado dentro del mismo grupo → advertencia en la UI, **permitido** en el servidor (hay fichas legítimas con etiquetas repetidas en distintas unidades).
- RB-8 Se guarda junto con el producto, en la misma operación, no en un endpoint aparte.

**Happy path**
1. El negocio añade 9 filas, escribe "Motor" en el grupo de tres y reordena por arrastre.
2. Guarda el producto; la acción reemplaza las especificaciones con posiciones 1..9.
3. La ficha pública las muestra agrupadas y en ese orden (`REQ-CAT-004`).

**Sad paths y edge cases**
- SP-1 Fila con `label` vacío → `422` señalando el índice de la fila.
- SP-2 Más de 50 filas → `422`.
- SP-3 Guardado con 0 filas → válido: elimina todas y la ficha omite la sección.
- SP-4 Fallo a mitad del reemplazo → rollback: el producto conserva sus especificaciones anteriores íntegras.
- SP-5 HTML en `value` → se persiste como texto y se escapa al renderizar (`REQ-CAT-004` RB-4).
- SP-6 Reordenamiento de filas nuevas sin `id` de BD → la clave local mantiene el orden sin depender del `id`.
- SP-7 Dos miembros guardando el mismo producto → el reemplazo completo del último gana; no hay merge.
- SP-8 Autocompletado de grupos → **nunca** sugiere grupos de otra organización (test de fuga).

**Fuera de alcance inmediato**
- Plantillas de ficha por categoría.
- Tipado por especificación (numérico, unidad, booleano).
- Reutilización de fichas entre productos.

**Gate de validación**
- Superficie esperada: `app/Actions/Product/SyncSpecificationsAction.php`, `app/Http/Requests/Backoffice/Concerns/ValidatesSpecifications.php`, `app/Queries/SpecificationGroupsQuery.php`, `resources/js/features/products/components/SpecificationBuilder.tsx`, `resources/js/features/products/hooks/useSpecificationRows.ts`.
- Tipos esperados: `SpecificationInput`, `SpecificationFormRow`, `SyncSpecificationsAction`.
- Anti-`SHALLOW`: `SyncSpecificationsAction::handle()` (validación, reemplazo transaccional, recálculo de posiciones) y `useSpecificationRows` (añadir, eliminar, reordenar con claves locales) superan 4 líneas.
- Tests: posiciones contiguas tras reordenar; `label` vacío rechazado con índice; 51 filas rechazadas; 0 filas borra todo; rollback conserva las anteriores; autocompletado acotado a la organización (fuga); escapado de HTML.

---

### REQ-BOF-022 · Gestión de imágenes de producto

**Historia de usuario**
> Como negocio, quiero subir varias fotos y elegir la principal, para que mi producto se vea bien en el catálogo y en el enlace que comparto.

**Contratos de datos**
- Colección `images` de medialibrary sobre `Product` (`REQ-CAT-005`).
- Rutas `POST /backoffice/productos/{product}/imagenes`, `DELETE .../imagenes/{media}`, `PATCH .../imagenes/orden`.
- **Consume:** métrica `storage_mb` del plan.

**Reglas de negocio y precondiciones**
- RB-1 Hasta **10** imágenes por producto; `jpg`, `png`, `webp`; **8 MB** máximo por archivo; validación de MIME real.
- RB-2 La imagen de `position = 1` es la principal: cards del catálogo, Open Graph y feed.
- RB-3 Las conversiones se generan en cola; la UI muestra "procesando" por imagen hasta que existan.
- RB-4 Eliminar la principal promueve automáticamente a la siguiente.
- RB-5 Subida múltiple con vista previa local y barra de progreso por archivo.
- RB-6 `alt` editable por imagen, con el valor por defecto de `REQ-CAT-005` RB-4.
- RB-7 Eliminar una imagen **sí** borra el archivo del disco y libera cuota — a diferencia de los comprobantes, que son evidencia y nunca se borran (`REQ-PAY-002` RB-8).
- RB-8 **La subida consume `storage_mb`**: con la cuota agotada se rechaza con el mensaje del plan (`REQ-SUB-008` SP-4). Aquí el afectado es el negocio por su propia decisión, a diferencia del comprobante de un cliente, que siempre se acepta (`REQ-PAY-001` RB-10).
- RB-9 Los archivos se guardan bajo una ruta que incluye el `organization_id`.

**Happy path**
1. El negocio arrastra 4 archivos al área de subida y ve el progreso.
2. Reordena para dejar la mejor primero y guarda el orden.
3. Las conversiones terminan, el catálogo sirve WebP y el consumo de almacenamiento se actualiza.

**Sad paths y edge cases**
- SP-1 Undécima imagen → `422` con el límite.
- SP-2 Archivo de 12 MB → `422`.
- SP-3 Archivo que no es imagen real → `422` por MIME real.
- SP-4 Eliminar la única imagen de un producto **publicado** → `422`: publicar exige al menos una (`REQ-BOF-020` RB-5). En `draft` sí se permite.
- SP-5 Subida interrumpida → nada se persiste para ese archivo; los ya subidos permanecen.
- SP-6 Job de conversión que falla → la imagen queda utilizable con el original y marcada para revisión.
- SP-7 **Cuota de almacenamiento agotada** → `422` con el desglose de consumo y el plan que lo desbloquea; las imágenes existentes no se tocan.
- SP-8 Reordenamiento concurrente → gana el último guardado.
- SP-9 Producto de otra organización → `404`.

**Fuera de alcance inmediato**
- Recorte, rotación y edición en el navegador.
- Biblioteca de medios reutilizable.
- Vídeo de producto.
- CDN.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/ProductImageController.php`, `app/Actions/Product/SyncImageOrderAction.php`, `app/Rules/RealMimeType.php`, `resources/js/features/products/components/ImageUploader.tsx`.
- Tipos esperados: `SyncImageOrderAction`, `ImageSet`, `RealMimeType`, `StorageQuotaExceededException`.
- Anti-`SHALLOW`: `SyncImageOrderAction::handle()` (pertenencia al tenant, recálculo de orden, promoción de principal) supera 4 líneas; el controlador concentra límite, tipo y cuota.
- Tests: límite de 10; tamaño y MIME rechazados; borrar la principal promueve la siguiente; publicado sin imágenes rechazado; **cuota agotada rechaza la subida del negocio pero no la del comprobante de un cliente** (test de los dos lados); borrar libera cuota; la ruta incluye el `organization_id`; producto ajeno da `404`.

---

### REQ-BOF-023 · Gestión de categorías y etiquetas

**Historia de usuario**
> Como negocio, quiero mantener mis propias categorías y etiquetas, para organizar mi catálogo a medida que crece mi inventario.

**Contratos de datos**
- Rutas `GET|POST|PUT|DELETE /backoffice/categorias` y `/backoffice/etiquetas`.
- `Category` (`organization_id`, `name`, `slug`, `position`) y `Tag` (`organization_id`, `name`, `slug`), con `UNIQUE(organization_id, slug)`.

**Reglas de negocio y precondiciones**
- RB-1 **La taxonomía es de cada organización** (`REQ-CAT-006` RB-1). No hay categorías compartidas ni sugeridas por la plataforma, y por tanto tampoco comparables entre negocios.
- RB-2 Categorías **planas**, con orden manual por `position` (arrastre). **Hoy el gesto son botones de subir y bajar**, no arrastre: `T-026` cerró el requisito sin la librería de *drag & drop*, que llega con `T-031` (`useSortableProducts`) y entonces cambia el gesto. Lo que ya tiene su forma definitiva es el contrato —`PATCH /backoffice/categorias/orden` con la lista entera de identificadores—, así que el cambio no toca el servidor. Se declara aquí para que nadie lea esta regla creyendo que está cumplida al pie de la letra.
- RB-3 `name` y `slug` únicos por organización; el `slug` se autogenera y es editable solo si no hay productos publicados asociados.
- RB-4 Una categoría con productos asociados **no** se puede eliminar (FK `restrict`): la UI ofrece "reasignar productos a otra categoría" antes de borrar.
- RB-5 Una etiqueta se puede eliminar en cualquier momento: se desasocia de los productos.
- RB-6 Renombrar **no** cambia el `slug` automáticamente (los filtros compartidos por URL se conservan); cambiar el `slug` es una acción explícita.
- RB-7 La UI muestra el conteo de productos por categoría y por etiqueta, incluyendo `draft`.
- RB-8 Se pueden crear al vuelo desde el formulario de producto; si el nombre ya existe en esa organización, se reutiliza en lugar de fallar.
- RB-9 No consumen ninguna métrica de plan: son metadatos y limitarlos no aportaría nada.

**Happy path**
1. El negocio crea la categoría "Electrodomésticos" y la coloca tercera.
2. Crea tres etiquetas y las asigna desde el formulario de cada producto.
3. Los filtros de sus catálogos reflejan el cambio (`REQ-CAT-006` RB-3).

**Sad paths y edge cases**
- SP-1 Borrar una categoría con productos → `422` con el conteo y la sugerencia de reasignar.
- SP-2 Nombre duplicado en la misma organización → `422`. En otra organización → permitido.
- SP-3 Editar el `slug` de una categoría con productos publicados → `422`.
- SP-4 Borrar una etiqueta usada en un filtro ya compartido por URL → permitido; el filtro se ignora (`REQ-CAT-006` SP-4).
- SP-5 Reordenamiento concurrente → gana el último.
- SP-6 Creación inline con nombre existente → se reutiliza (RB-8).
- SP-7 Categoría de otra organización por manipulación de la URL → `404`.

**Fuera de alcance inmediato**
- Jerarquía de categorías.
- Contenido editorial por categoría.
- Fusión de etiquetas.
- Taxonomía sugerida por la plataforma.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/CategoryController.php`, `app/Http/Controllers/Backoffice/TagController.php`, `app/Actions/Catalog/ReassignCategoryProductsAction.php`, `app/Models/Category.php`, `app/Models/Tag.php`.
- Tipos esperados: `Category`, `Tag`, `CategoryInUseException`.
- Anti-`SHALLOW`: `ReassignCategoryProductsAction::handle()` (reasignación en lote, validación de pertenencia, auditoría) supera 4 líneas; `Category::scopeOrdered()` con `withCount` junto al modelo.

> **La mención de «auditoría» de ese anti-`SHALLOW` no crea un evento.** La lista de `REQ-BOF-005` RB-1 es **cerrada** y su bloque de Catálogo cubre el precio, el financiamiento y el estado de un producto y la publicación de catálogos — no la taxonomía. Mover productos de una categoría a otra no cambia lo que se vende, ni a qué precio, ni quién puede verlo: cambia cómo se filtra. Así que la reasignación **no audita**, y lo que sostiene el anti-`SHALLOW` es lo demás —el lote, la validación de pertenencia y la transacción—, que ya pasa de cuatro líneas de sobra. Si algún día hiciera falta el rastro, es un evento nuevo en RB-1 con su tarea, no una línea colada en una acción.
- Tests: borrado con productos rechazado; reasignar y luego borrar funciona; **mismo nombre de categoría en dos organizaciones permitido** (tenancy); slug bloqueado con productos publicados; borrar etiqueta desasocia; creación inline reutiliza; categoría ajena da `404`.

---

### REQ-BOF-024 · Gestión de catálogos

**Historia de usuario**
> Como negocio, quiero armar catálogos temáticos, ordenarlos y publicarlos con un enlace, para hacer campañas dirigidas por WhatsApp.

**Contratos de datos**
- Rutas `GET|POST|PUT /backoffice/catalogos`, `PATCH .../{catalog}/productos`, `POST|DELETE .../{catalog}/publicar`.
- `SyncCatalogProductsAction` (`REQ-CAT-023`) y `PublishCatalogAction` (`REQ-CAT-022`).
- **Consume:** métrica `catalogs` (solo los publicados) y `max_products_per_catalog`.

**Reglas de negocio y precondiciones**
- RB-1 El CRUD cubre `name`, `description`, portada, `is_listed`, `published_at` y `expires_at`.
- RB-2 La composición y el orden se gestionan con el buscador de productos y el reordenamiento por arrastre (`REQ-CAT-023`).
- RB-3 Publicar valida los **seis requisitos** de `REQ-CAT-022` RB-4, incluido el cupo del plan y la existencia de una cuenta de cobro activa.
- RB-4 El `slug` es inmutable tras la primera publicación; la UI lo explica **antes** de publicar, porque después no hay vuelta atrás.
- RB-5 La UI muestra el estado derivado (`Draft` / `Listed` / `Unlisted` / `Expired`) con un badge y, para los publicados, la URL completa con botón de copiar y una previsualización de cómo se verá el enlace al compartirlo.
- RB-6 Explica con esas palabras la diferencia entre `Listed` y `Unlisted`: "cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina".
- RB-7 Muestra el **conteo de suscriptores** del catálogo (`REQ-ACC-007` RB-2) y, en plan Free, cuántos avisos habría podido enviar si tuviera la función (`REQ-ACC-006` SP-1).
- RB-8 Los **borradores no consumen** la métrica `catalogs`: solo los publicados (`REQ-SUB-003` RB-2). Así el negocio puede preparar campañas sin gastar cupo.
- RB-9 Al despublicar, se advierte que los enlaces difundidos dejarán de funcionar y que las suscripciones quedarán inactivas pero no se perderán.
- RB-10 Cada publicación, despublicación y cambio de expiración se audita.
- RB-11 Con la organización `suspended` no se puede publicar (`REQ-ORG-008` RB-3).

**Happy path**
1. El negocio crea "Ofertas de septiembre", añade 12 productos y los ordena.
2. Define `expires_at` al 30/09, publica como `Unlisted` y copia el enlace con su previsualización.
3. Lo difunde por WhatsApp; el 01/10 el enlace responde `410` sin intervención.

**Sad paths y edge cases**
- SP-1 Publicar sin productos publicados → `422`.
- SP-2 Publicar sin cuenta de cobro activa → `422` con el enlace para crearla.
- SP-3 Publicar con el cupo `catalogs` agotado → `422` con el plan que lo desbloquea; se sugiere despublicar otro como alternativa.
- SP-4 `expires_at` en el pasado → `422`.
- SP-5 Editar el `slug` tras publicar → `422`.
- SP-6 Despublicar un catálogo difundido → permitido tras confirmación con la advertencia de RB-9.
- SP-7 Producto del catálogo archivado → el catálogo sigue publicado, ese producto no se renderiza y el backoffice lo marca "no visible" en la composición.
- SP-8 Catálogo con todos sus productos despublicados → publicado pero vacío (`REQ-CAT-021` SP-4); la UI lo advierte de forma destacada.
- SP-9 Portada ausente → se usa la imagen del primer producto.
- SP-10 Catálogo de otra organización → `404`.
- SP-11 Slug sugerido ya tomado por otra organización → se desambigua con sufijo, informándolo (`REQ-CAT-022` RB-1).

**Fuera de alcance inmediato**
- Duplicar un catálogo.
- Programar la publicación futura.
- Métricas de conversión por catálogo (más allá de `catalog_viewed` y suscriptores).
- Plantillas visuales de catálogo.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Backoffice/CatalogController.php`, `app/Actions/Catalog/PublishCatalogAction.php`, `app/Actions/Catalog/SyncCatalogProductsAction.php`, `resources/js/pages/backoffice/catalogs/Form.tsx`, `resources/js/features/catalogs/components/CatalogProductSorter.tsx`.
- Tipos esperados: `CatalogVisibility`, `PublishCatalogData`, `CatalogProductOrder`, `SlugLockedException`, `CatalogNotPublishableException`.
- Anti-`SHALLOW`: cubierto por `PublishCatalogAction::handle()` y `SyncCatalogProductsAction::handle()` (`REQ-CAT-022`, `REQ-CAT-023`); el controlador aporta la composición del estado derivado, el conteo de suscriptores y el alcance potencial.
- Tests: publicar vacío rechazado; sin cuenta de cobro rechazado; cupo agotado rechazado; **los borradores no consumen cupo** (test de RB-8); slug bloqueado tras publicar; badge correcto en los cuatro estados; producto archivado marcado no visible; conteo de suscriptores presente y alcance potencial en Free; catálogo ajeno da `404`; `suspended` bloquea la publicación.
