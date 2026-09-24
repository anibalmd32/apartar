# Módulo 11 — Productos, taxonomía y ficha técnica (`CAT`)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md), [12-catalogos-curados.md](12-catalogos-curados.md)
> Requisitos: `REQ-CAT-002` … `REQ-CAT-007`
>
> **Cambio estructural del pivote:** ya no existe un listado público global de productos. Un producto solo es visible **dentro de un catálogo** de su organización. El antiguo `REQ-CAT-001` (listado público de `/productos`) queda **eliminado**; su funcionalidad de búsqueda, orden y filtro se ejerce ahora dentro del catálogo (`REQ-CAT-021`).

## Contratos compartidos del módulo

```php
enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function isVisibleInCatalog(): bool { /* … */ }
    public function canBeReserved(): bool { /* … */ }
}
```

```ts
/** Producto en la parrilla de un catálogo. Nunca incluye la descripción completa. */
export interface ProductCard {
  readonly slug: string;
  readonly name: string;
  readonly price: MoneyView;
  readonly compareAtPrice: MoneyView | null;
  readonly downPayment: MoneyView;
  readonly installmentAmount: MoneyView;
  /** Última cuota, que absorbe el residuo del reparto (`REQ-FX-001` RB-4). */
  readonly lastInstallmentAmount: MoneyView;
  readonly installmentsCount: number;
  readonly frequencyDays: number;
  readonly image: ImageSet | null;
  readonly categoryName: string;
  readonly tags: readonly string[];
  readonly inStock: boolean;
  /** Catálogo en cuyo contexto se está mostrando: define el enlace y la telemetría. */
  readonly catalogSlug: string;
}

export interface ProductDetail extends Omit<ProductCard, 'image'> {
  readonly description: string;
  readonly sku: string;
  readonly images: readonly ImageSet[];
  readonly specificationGroups: readonly SpecificationGroup[];
  readonly maxQuantity: number;
  readonly paymentAccounts: readonly PaymentAccountView[];
  readonly business: OrganizationBrand;
  readonly canBeReserved: boolean;
  readonly unavailableReason: string | null;
}

export interface SpecificationGroup {
  readonly group: string | null;
  readonly items: readonly { readonly label: string; readonly value: string }[];
}

export interface ImageSet {
  readonly thumb: string; readonly card: string; readonly zoom: string; readonly alt: string;
}
```

---

### REQ-CAT-002 · Búsqueda de productos dentro de un catálogo

**Historia de usuario**
> Como cliente con el enlace de un catálogo grande, quiero buscar por nombre o modelo dentro de él, para llegar al producto sin recorrer todo.

**Contratos de datos**
- Ruta `GET /c/{catalog:slug}?q=…` (misma página que `REQ-CAT-021`).
- Índice `FULLTEXT(name, description, sku)` en `products`.
- Scope `Product::scopeSearch(string $term)`, combinado con `scopeInCatalog(Catalog $catalog)`.

> **Cómo quedó (`T-034`).** `scopeSearch(FullTextTerm $term, SearchMode $mode)`: el término llega saneado y el modo lo decide `CatalogProductsQuery`, que es quien puede preguntar si el índice encontró algo. Tres lecturas de las reglas de abajo que conviene no reinterpretar: **el `LIKE` de RB-2 exige cada palabra** en el nombre o el SKU, y no el término entero —si no, «taladro media-pulgada» no encontraría «Taladro de media pulgada»—; **sin `MATCH` no hay relevancia** que ordenar, así que una búsqueda degradada sin `sort` sale en el orden curado (RB-4); y **RB-7 se cumple por lista blanca**: el término se parte en palabras de letras y cifras, y los únicos operadores de la expresión son el `+` y el `*` que pone `FullTextTerm`. Las palabras vacías (las del índice y las del español más comunes) se quitan de los dos modos, que es lo que da sentido a SP-1.

**Reglas de negocio y precondiciones**
- RB-1 La búsqueda está **acotada al catálogo** que se está viendo y al tenant de su organización. No existe búsqueda global de productos ni entre catálogos (`REQ-ACC-003` RB-5 explica por qué tampoco en el feed).
- RB-2 `FULLTEXT` de MySQL 8 en modo booleano; con término de menos de 3 caracteres o sin resultados, se degrada a `LIKE %term%` sobre `name` y `sku`.
- RB-3 Combinable con los filtros de categoría y etiquetas del catálogo (`REQ-CAT-006`).
- RB-4 Con término presente el orden es por relevancia; un `sort` explícito del usuario tiene prioridad.
- RB-5 Toda búsqueda emite `search_performed` con el término, el número de resultados y el `catalog_id` (`REQ-TLM-001` RB-3).
- RB-6 `debounce` de 300 ms; no se dispara una petición por tecla.
- RB-7 Los operadores booleanos de `FULLTEXT` (`+ - * " ( )`) se **escapan** antes de construir la consulta.

**Happy path**
1. El cliente abre un catálogo de 80 productos y escribe "taladro".
2. Tras 300 ms, Inertia recarga solo la parrilla con los 4 resultados, ordenados por relevancia.
3. Se emite `search_performed` con `{ term: 'taladro', results: 4, catalog_id: 12 }`.

**Sad paths y edge cases**
- SP-1 Término con solo signos o stopwords → se sanea; si queda vacío, se ignora el parámetro.
- SP-2 Cero resultados → estado vacío con las categorías presentes en ese catálogo y CTA al chat del negocio.
- SP-3 Término de más de 100 caracteres → se trunca sin error.
- SP-4 Acentos → deben coincidir con y sin ellos; collation `utf8mb4_unicode_ci` obligatoria.
- SP-5 Inyección de operadores booleanos → escapada (RB-7).
- SP-6 Búsqueda en un catálogo con productos de la organización que fueron despublicados → no aparecen (`REQ-CAT-007`).

**Fuera de alcance inmediato**
- Autocompletado y sugerencias.
- Corrección ortográfica y sinónimos.
- Meilisearch / Laravel Scout.
- Búsqueda entre catálogos o global.

**Gate de validación**
- Superficie esperada: `app/Models/Product.php` (`scopeSearch`, `scopeInCatalog`), `app/Support/FullTextTerm.php`, `resources/js/features/catalog/components/SearchInput.tsx`.
- Tipos esperados: `FullTextTerm`, parámetro `q` en `CatalogFilters`.
- Anti-`SHALLOW`: `FullTextTerm::sanitize()` (escapado, longitud, fallback) junto a su clase; `Product::scopeInCatalog()` junto al modelo.
- Tests: encuentra por nombre, SKU y acento; escapa operadores; degrada a `LIKE`; **un producto de otro catálogo de la misma organización no aparece** (test de acotación); un producto de otra organización tampoco (test de fuga); emite `search_performed` con `catalog_id`.

---

### REQ-CAT-003 · Ficha de producto dentro del catálogo

**Historia de usuario**
> Como cliente, quiero ver el detalle del producto con el desglose de cuánto pago de inicial y de cuotas, y dónde le pago al negocio, para decidir si lo aparto.

**Contratos de datos**
- Ruta `GET /c/{catalog:slug}/p/{product:slug}`.
- Props `{ product: ProductDetail, related: ProductCard[], catalog, business, rate }`.
- `ProductDetailResource` calcula `installmentAmount` con `Money::allocateEvenly()` y adjunta las cuentas de cobro activas.

> **De dónde sale la cuota, y qué de aquí no es de `T-035`.** El gate de abajo nombra `ScheduleGenerator` en la superficie esperada, y eso se lee al revés: la cuota de RB-2 la reparte **`FinancingPlan`** (`app/Domain/Catalog`, de `T-027`), que es la misma cuenta que ya enseñan la previsualización del backoffice y cada card de la parrilla. `ScheduleGenerator` es `REQ-LAY-004`, llega en `T-041`, y lo que le toca entonces es **probar que las dos cuentas coinciden hasta el céntimo**, no recalcular esta. Del resto del requisito, tres piezas tienen otro dueño: el motivo de indisponibilidad por límite de plan de RB-7 lo inserta `T-067` (`REQ-SUB-004` RB-4), `product_viewed` y la permanencia de RB-8 son de `T-084`, y el chat que SP-6 ofrece cuando no hay cuentas de cobro es de `T-080` —hasta entonces, el texto sin enlace—.

**Reglas de negocio y precondiciones**
- RB-1 El producto se resuelve **dentro del contexto del catálogo**: la URL lleva ambos slugs, y un producto que no pertenece a ese catálogo da `404`. No hay ficha de producto fuera de un catálogo.
- RB-2 La cuota mostrada es `(price − down_payment) / installments_count`, calculada en centavos con el residuo en la última cuota (`REQ-FX-005`); la UI indica "última cuota: $X" cuando difiere.
- RB-3 Se muestran precio, precio de comparación tachado si existe, cuota inicial, número de cuotas y frecuencia en lenguaje natural ("cada 15 días").
- RB-4 Todos los importes se proyectan en bimoneda con la **fuente de tasa de esa organización** (`REQ-FX-003`).
- RB-5 **Se muestran las cuentas de cobro activas del negocio** (`REQ-ORG-007`): el cliente debe saber dónde pagar antes de comprometerse. Es información nueva del pivote y es imprescindible.
- RB-6 Se muestra la identidad del negocio (`OrganizationBrand`): nombre y, si el plan lo permite, logo y color. En Free aparece el sello "Hecho con Apartar" (`REQ-CAT-022` RB-5).
- RB-7 El CTA principal es "Apartar" y abre el formulario (`REQ-LAY-001`). Se deshabilita con un motivo explícito cuando `canBeReserved` es falso, y `unavailableReason` distingue los casos: agotado, producto no apartable, o **el negocio no puede recibir apartados ahora** (límite de plan, `REQ-SUB-004` RB-4, con mensaje neutro que no expone el plan del negocio).
- RB-8 La visita emite `product_viewed` con `catalog_id` y `payload.source`, y arranca la medición de permanencia (`REQ-TLM-003`).
- RB-9 "Relacionados" son hasta 4 productos **del mismo catálogo**, excluyendo el actual, de la misma categoría si hay, y por orden curado si no.
- RB-10 `maxQuantity` es `min(stock, 10)` y viaja al formulario para que la validación de cliente y de servidor coincidan.

**Happy path**
1. El cliente pulsa un producto en el catálogo y ve galería, precio bimoneda, desglose del plan y ficha técnica.
2. Debajo ve "Formas de pago: Pago móvil Banesco 0414-1234567 · Ana Pérez · V-12.345.678".
3. Cambia el toggle de moneda y todos los importes se reproyectan sin recargar.
4. Pulsa "Apartar" y avanza al formulario con el producto y la cantidad preseleccionados.

**Sad paths y edge cases**
- SP-1 Producto que no está en ese catálogo → `404`, aunque exista y esté publicado.
- SP-2 Producto `draft` o `archived` → `404`.
- SP-3 Catálogo expirado o despublicado → `410` / `404` antes de resolver el producto (`REQ-CAT-021`).
- SP-4 Producto sin imágenes → placeholder con la inicial del nombre; nunca imagen rota.
- SP-5 Producto sin ficha técnica → la sección se omite entera, sin encabezado vacío.
- SP-6 Negocio sin cuentas de cobro activas → se muestra "consulta al negocio por el chat" en lugar de la lista, y aparece la alerta en el dashboard del negocio (`REQ-ORG-007` SP-4).
- SP-7 `down_payment >= price` o `installments_count = 0` → `canBeReserved` falso con el motivo; el CRUD debería impedir ese estado (`REQ-BOF-020` RB-1).
- SP-8 Sin tasa de cambio → solo USD (`REQ-FX-005`).
- SP-9 Slug del producto cambiado tras compartir el enlace → `404`; no hay redirección de slugs históricos.

**Fuera de alcance inmediato**
- Reseñas, valoraciones y preguntas de producto.
- Variantes (talla, color) y SKU hijos.
- Notificación "avísame cuando haya stock".
- Redirección de slugs históricos.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Catalog/ProductShowController.php`, `app/Http/Resources/ProductDetailResource.php`, `app/Domain/Layaway/ScheduleGenerator.php`, `resources/js/pages/catalog/ProductShow.tsx`.
- Tipos esperados: `ProductDetail`, `SpecificationGroup`, `ImageSet`, `PaymentAccountView`, `OrganizationBrand`, `MoneyView`.
- Anti-`SHALLOW`: `ProductDetailResource::toArray()` (importes, agrupación de ficha técnica, cuentas de cobro, motivo de indisponibilidad, relacionados) supera 4 líneas junto a su clase.
- Tests: la cuota mostrada coincide con el cronograma real que generaría el apartado; producto fuera del catálogo da `404`; producto de otra organización da `404` (fuga); las cuentas de cobro activas aparecen y las inactivas no; el motivo de límite de plan no revela el plan (`REQ-SUB-004` RB-4); sin ficha técnica no se renderiza la sección; toggle reproyecta sin petición.

---

### REQ-CAT-004 · Visualización de la ficha técnica dinámica

**Historia de usuario**
> Como cliente, quiero ver las especificaciones organizadas, para comparar características antes de apartar.

**Contratos de datos**
- Entidad `ProductSpecification` (`product_specifications`): `organization_id`, `product_id`, `group?`, `label`, `value`, `position`.
- `SpecificationGroup[]` dentro de `ProductDetail`.

**Reglas de negocio y precondiciones**
- RB-1 Modelo relacional, **no** JSON (`ASU-CAT-03`), para garantizar orden estable y permitir filtros por atributo en el futuro.
- RB-2 Agrupación por `group`; las de `group = null` se renderizan primero bajo el rótulo "General".
- RB-3 Dentro de cada grupo, orden por `position` y, a igualdad, por `id`.
- RB-4 `label` y `value` se renderizan como **texto plano escapado**: no se admite HTML ni Markdown.
- RB-5 En móvil, lista de pares apilados; desde `md`, tabla de dos columnas dentro de un contenedor con `overflow-x: auto` (el `body` nunca scrollea en horizontal).
- RB-6 La tabla es del tenant: `organization_id` presente y filtrado por el global scope (`REQ-ORG-002`).

**Happy path**
1. El negocio guardó 9 especificaciones en 3 grupos.
2. `ProductDetailResource` las agrupa y ordena.
3. La ficha renderiza 3 secciones colapsables, abiertas en escritorio y la primera abierta en móvil.

**Sad paths y edge cases**
- SP-1 `value` de más de 200 caracteres → se permite el salto de línea; nunca se recorta en silencio.
- SP-2 Grupos con distinta capitalización ("Motor" y "motor") → se agrupan por comparación *case-insensitive*, mostrando la primera variante encontrada.
- SP-3 `label` vacío → bloqueado en el CRUD; si existiera, se descarta al renderizar.
- SP-4 HTML inyectado en `value` → escapado obligatorio, con test explícito de XSS.

**Fuera de alcance inmediato**
- Plantillas de ficha técnica reutilizables por categoría.
- Tipado por especificación (numérico, unidad, booleano).
- Filtrado del catálogo por valor de especificación.

**Gate de validación**
- Superficie esperada: `app/Models/ProductSpecification.php`, `app/Http/Resources/ProductDetailResource.php`, `resources/js/features/catalog/components/SpecificationTable.tsx`.
- Tipos esperados: `ProductSpecification`, `SpecificationGroup`.
- Anti-`SHALLOW`: el método `groupSpecifications()` del resource (> 4 líneas) en su archivo.
- Tests: orden por `position`; `null` primero como "General"; agrupación *case-insensitive*; escapado de HTML; sin especificaciones devuelve array vacío; el global scope filtra por organización.

---

### REQ-CAT-005 · Galería de imágenes y conversiones asíncronas

**Historia de usuario**
> Como cliente en datos móviles, quiero que las imágenes carguen rápido y se vean nítidas al ampliar, para evaluar el producto sin gastar mis datos.

**Contratos de datos**
- `spatie/laravel-medialibrary` sobre la colección `images` de `Product`.
- Conversiones `thumb` 400, `card` 800, `zoom` 1600, WebP calidad 82.
- Job `TranscodeProductImage` (cola `default`); tipo `ImageSet`.

**Reglas de negocio y precondiciones**
- RB-1 Las conversiones se generan **en cola**, nunca en el request de subida.
- RB-2 La imagen de `position = 1` es la principal: se usa en las cards, en Open Graph del catálogo y en el feed.
- RB-3 Mientras una conversión no exista, se sirve el original con `loading="lazy"`; nunca un hueco roto.
- RB-4 `alt` obligatorio, con el valor por defecto `"{{ product.name }} — imagen {{ n }}"`.
- RB-5 Formatos: `jpg`, `png`, `webp`; máximo 8 MB por archivo; hasta 10 imágenes por producto.
- RB-6 **El peso de las imágenes consume la cuota de almacenamiento del plan** (`REQ-SUB-003` RB-2): superar el techo bloquea subidas nuevas del negocio (`REQ-SUB-008` SP-4).
- RB-7 Los archivos se almacenan bajo una ruta que incluye el `organization_id`, para que el aislamiento sea también físico y auditable.
- RB-8 Galería en móvil como carrusel táctil con puntos; en escritorio, miniaturas laterales y zoom al hover.

**Happy path**
1. El negocio sube 4 imágenes (`REQ-BOF-022`).
2. Se despachan 4 jobs de conversión; la ficha ya funciona sirviendo los originales.
3. Al completarse, se sirve WebP en tres tamaños con `srcset`, y el consumo de almacenamiento se actualiza.

**Sad paths y edge cases**
- SP-1 Archivo corrupto o que no es imagen real → el job falla, se registra y el medio se marca para revisión; el producto sigue publicable con las válidas.
- SP-2 Transparencia → se preserva el canal alfa en WebP.
- SP-3 Imagen de 8000 px → se redimensiona sin agotar memoria, con límite de memoria del job y driver declarado explícitamente.
- SP-4 Producto sin imágenes → placeholder en CSS, sin petición de red.
- SP-5 Borrado de la imagen principal → la siguiente por `position` la sustituye automáticamente.
- SP-6 Cuota de almacenamiento agotada → la subida se rechaza con el mensaje del plan; las imágenes existentes **no se tocan** (`REQ-SUB-008` RB-1).
- SP-7 Job de conversión de una organización suspendida → se procesa igual: es trabajo ya encolado y no afecta a la operación.

**Fuera de alcance inmediato**
- CDN y purga remota de caché.
- Vídeo de producto.
- Recorte y edición en el navegador.
- Biblioteca de medios reutilizable entre productos.

**Gate de validación**
- Superficie esperada: `app/Models/Product.php` (`registerMediaConversions`), `app/Jobs/TranscodeProductImage.php`, `resources/js/features/catalog/components/ProductGallery.tsx`, `resources/js/lib/image.ts`.
- Tipos esperados: `ImageSet`, colección `images` declarada, `StorageQuotaExceededException`.
- Anti-`SHALLOW`: `Product::registerMediaConversions()` (tres conversiones, > 4 líneas) junto al modelo.
- Tests: se registran tres conversiones; ocurren en cola; sin conversión se sirve el original; borrar la principal promueve la siguiente; `alt` por defecto; la ruta de almacenamiento incluye el `organization_id`; cuota agotada rechaza la subida sin borrar nada.

---

### REQ-CAT-006 · Taxonomía por organización y filtros del catálogo

**Historia de usuario**
> Como cliente dentro de un catálogo, quiero filtrar por categoría y etiquetas, para acotar lo que veo sin salir del catálogo.

**Contratos de datos**
- Entidades `Category` (`organization_id`, `name`, `slug`, `position`) y `Tag` (`organization_id`, `name`, `slug`), ambas con `UNIQUE(organization_id, slug)`.
- Filtros dentro de `GET /c/{catalog:slug}`: `category`, `tags[]`, `sort`, `q`, `cursor`.
- `CatalogFilters` (TS y PHP).

> **Cómo quedó (`T-034`).** `CatalogFilters` es `{ q, category, tags, sort, ignored }` **tal como se aplicó**, no como llegó: `ignored` dice que se quitó algo del enlace, que es el «aviso» de SP-1. Lo que se acepta es exactamente lo que ofrece el panel (`CatalogFacetsQuery`), y eso resuelve SP-1, SP-4 y SP-5 por el mismo camino. **SP-3 se lee como «un `sort` inválido cuenta como ausente»**: sin término cae a `curated`, y con término a la relevancia, porque un valor que no existe no es una elección del usuario que RB-4 tenga que respetar. `recent` ordena por `catalog_product.created_at`, la entrada **a este catálogo**. Y la recarga parcial de RB-7 es de `products` y `filters`: las facetas cuentan el catálogo entero, no la combinación activa, así que no cambian al filtrar y no se vuelven a pedir.

**Reglas de negocio y precondiciones**
- RB-1 **La taxonomía pertenece a cada organización.** Cada negocio define sus propias categorías y etiquetas; no hay taxonomía compartida en la plataforma. Es la razón por la que el feed no filtra por categoría (`REQ-ACC-003` RB-5): "Motores" de un negocio no es comparable con el de otro.
- RB-2 Un producto pertenece a **una** categoría (obligatoria) y a **N** etiquetas (opcionales).
- RB-3 Los filtros del catálogo ofrecen **solo las categorías y etiquetas presentes en ese catálogo**, con su conteo, no todo el vocabulario de la organización. Un filtro que devuelve cero no se muestra.
- RB-4 Filtro de etiquetas con semántica **OR**; el de categoría es único y excluyente.
- RB-5 Orden: `recent` (por incorporación al catálogo), `curated` (por `position` del catálogo, **default**), `price_asc`, `price_desc`, `name`.
- RB-6 El estado de los filtros vive en la query string y la URL es compartible y reproducible.
- RB-7 La navegación entre filtros usa Inertia partial reloads (`only: ['products', 'filters']`), preservando scroll y el estado del panel.
- RB-8 Se emite `product_list_filtered` con el `catalog_id` y los filtros aplicados.
- RB-9 **No existe** una página de categoría ni de etiqueta fuera de un catálogo: la antigua `/categorias/{slug}` desaparece con el pivote.

**Happy path**
1. El cliente abre un catálogo con 60 productos de 4 categorías.
2. El panel de filtros muestra esas 4 categorías con su conteo y las 12 etiquetas presentes.
3. Selecciona una categoría y dos etiquetas; Inertia recarga solo la parrilla.
4. Comparte la URL y el destinatario ve exactamente el mismo conjunto.

**Sad paths y edge cases**
- SP-1 Slug de categoría que no existe o no está en ese catálogo → se ignora el filtro con un aviso, sin `404`.
- SP-2 Combinación sin resultados → estado vacío con CTA "ver todo el catálogo".
- SP-3 `sort` desconocido → cae a `curated`.
- SP-4 Etiqueta borrada por el negocio con la URL ya compartida → el filtro se ignora (SP-1).
- SP-5 Categoría de otra organización en la query → se ignora; el global scope hace que no exista en ese contexto.
- SP-6 Catálogo cuyos productos son todos de una categoría → el filtro de categoría se oculta por inútil.

**Fuera de alcance inmediato**
- Categorías jerárquicas.
- Filtros por rango de precio o por número de cuotas.
- Etiquetas con semántica AND.
- Taxonomía compartida entre organizaciones.
- Páginas de categoría o etiqueta independientes del catálogo.

**Gate de validación**
- Superficie esperada: `app/Models/Category.php`, `app/Models/Tag.php`, `app/Queries/CatalogFacetsQuery.php`, `app/Http/Controllers/Catalog/CatalogShowController.php`, `resources/js/features/catalog/hooks/useCatalogFilters.ts`.
- Tipos esperados: `Category`, `Tag`, `CatalogFilters`, `CatalogFacetsQuery`.
- Anti-`SHALLOW`: `CatalogFacetsQuery::forCatalog()` (facetas presentes en el catálogo con conteos, exclusión de las vacías) y `useCatalogFilters` (query string, recarga parcial, concatenación de lotes) superan 4 líneas junto a sus tipos.
- Tests: las facetas son solo las presentes en el catálogo; filtro OR de etiquetas; categoría de otra organización se ignora (fuga); `sort` inválido cae a `curated`; la URL reproduce el conjunto; emite `product_list_filtered` con `catalog_id`; no existe ruta de categoría fuera del catálogo (assert de rutas).

---

### REQ-CAT-007 · Estados de publicación, disponibilidad y borrado seguro

**Historia de usuario**
> Como negocio, quiero preparar productos sin que se vean y retirar los antiguos sin perder el historial, para trabajar mi inventario con calma y sin romper los apartados vigentes.

**Contratos de datos**
- `ProductStatus`, `products.published_at`, `products.deleted_at` (`SoftDeletes`), `products.stock`.
- Scope `Product::scopePublished()`; `ChangeProductStatusAction`.
- **Las transiciones tienen ruta, una por botón** (`T-030`): `POST /backoffice/productos/{product}/publicar` —con `published_at` opcional, que es por donde entra la programación de RB-5—, `.../despublicar` y `.../restaurar`, junto a la `.../archivar` que `REQ-BOF-020` ya declaraba. Este requisito solo nombraba la `Action`, y sin rutas la matriz no tendría quien la pulsara; son cuatro y no una con parámetro de destino porque el estado al que se va no es un dato que el cliente elija: `archived → published` no existe (SP-4), y un endpoint que aceptara el destino invitaría a pedirlo. Ninguna sobrevive a la suspensión, como el resto del inventario (`REQ-BOF-020` RB-11).

**Reglas de negocio y precondiciones**
- RB-1 `draft`: invisible en todo catálogo y sin URL alcanzable. `published`: visible si `published_at <= now()` **y** está en un catálogo accesible. `archived`: invisible, conserva historial, no se puede apartar.
- RB-2 Un producto con apartados asociados **nunca** se elimina físicamente; el borrado es lógico y en la UI se llama "Archivar".
- RB-3 Archivar **no** afecta a los apartados vigentes, que operan sobre su snapshot (`REQ-LAY-002`).
- RB-4 `stock = 0` no cambia el estado: sigue `published`, visible en el catálogo con badge "Agotado" y con el apartado deshabilitado.
- RB-5 `published_at` futuro es válido y actúa como publicación programada; se evalúa en la query, sin job.
- RB-6 Un producto puede estar `published` y no ser visible por nadie si no pertenece a ningún catálogo publicado: **la visibilidad es la intersección** del estado del producto y la accesibilidad del catálogo. El backoffice lo indica ("publicado, en 0 catálogos").
- RB-7 Los productos archivados **no** se retiran de los catálogos: la relación se conserva y simplemente no se renderizan (`REQ-CAT-021` RB-2), para que desarchivar los devuelva sin recomponer catálogos.
- RB-8 `products` cuenta para el límite `products` del plan (`REQ-SUB-003` RB-2): archivados y borrados lógicamente **no** cuentan.
- RB-9 Todo cambio de estado o de precio se audita (`REQ-BOF-005`).

**Happy path**
1. El negocio crea el producto en `draft` y completa datos, imágenes y ficha técnica.
2. Lo publica y lo añade a un catálogo publicado; aparece de inmediato para quien tenga el enlace.
3. Meses después lo archiva: desaparece del catálogo, libera cupo del plan y los apartados en curso siguen intactos.

**Sad paths y edge cases**
- SP-1 URL directa de un producto `draft` o `archived` → `404`.
- SP-2 Intento de apartar un producto archivado con el formulario ya abierto → `422` "ya no está disponible". **La guarda es `ProductNotAvailableException::guard()`** (`T-030`) y su llamante es `CreateLayawayAction`, que es de `T-044`: el mecanismo se escribe con los estados y el recorrido con los apartados, y no se inventa un apartado para tener dónde llamarla.
- SP-3 Producto agotado con el formulario abierto → rechazado en `CreateLayawayAction` (`REQ-LAY-003` SP-1).
- SP-4 Restaurar un archivado → vuelve como `draft`, nunca directamente a `published`, y vuelve a consumir cupo del plan (si no hay, `422`).
- SP-5 `published_at` futuro → no aparece hasta la fecha; el backoffice lo marca "Programado".
- SP-6 Producto publicado en 0 catálogos → visible en el backoffice con el aviso de RB-6; no es un error.
- SP-7 Archivar el último producto de un catálogo publicado → el catálogo queda publicado y vacío, con aviso al negocio (`REQ-CAT-021` SP-4).

**Fuera de alcance inmediato**
- Publicación y archivado masivos.
- Despublicación programada.

**Gate de validación**
- Superficie esperada: `app/Models/Product.php`, `app/Actions/Product/ChangeProductStatusAction.php`, `app/Domain/Catalog/ProductStatus.php`, `app/Http/Requests/Backoffice/PublishProductRequest.php`, las tres rutas de arriba en `routes/backoffice.php` y sus acciones en `ProductController`, `resources/js/features/products/components/ProductRow.tsx`.
- Tipos esperados: `ProductStatus`, `ProductNotAvailableException`, `IncompleteProductException` (declarada en `REQ-BOF-020`, y es aquí donde se lanza: la completitud de su RB-5 vive entera en la transición a `published`).
- Anti-`SHALLOW`: las transiciones válidas, el ajuste del consumo del plan y la auditoría de `ChangeProductStatusAction` superan 4 líneas; `ProductStatus::isVisibleInCatalog()` y `canBeReserved()` junto al enum. **No hay un `handle()`** (`T-027`, `T-030`): la clase tiene un método por paso —`publish()`, `unpublish()`, `restore()`, `archive()`— sobre un `transitionTo()` privado que escribe la columna y audita una sola vez para todas. Un `handle($status)` haría del estado de destino un parámetro, y el destino no es un dato que el llamante elija: es lo que la matriz decide. El ajuste del cupo (RB-8) sigue sin estar aquí y es de `T-067`, con `T-066` enseñando a `measure()` a contar `products`.
- Tests: matriz de transiciones; `draft` y `archived` dan `404`; archivar no rompe apartados vigentes y libera cupo; restaurar deja `draft` y reclama cupo; `published_at` futuro no se lista; publicado en 0 catálogos no es visible pero no es error; la relación con el catálogo se conserva al archivar.
