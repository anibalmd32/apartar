# Módulo 12 — El catálogo como tienda (`CAT` 020+)

> Padre: [02-spec-consolidada.md](02-spec-consolidada.md) · Depende de: [04-tenancy-y-organizaciones.md](04-tenancy-y-organizaciones.md), [11-catalogo-y-productos.md](11-catalogo-y-productos.md), [15-cuentas-de-cliente-y-feed.md](15-cuentas-de-cliente-y-feed.md)
> Requisitos: `REQ-CAT-021` … `REQ-CAT-023`
>
> **El catálogo asciende a superficie principal.** Con el pivote deja de ser una colección curada dentro de una tienda y pasa a ser **la tienda entera** desde el punto de vista del cliente final: la única puerta de entrada, con su propia búsqueda, sus filtros y su orden. El antiguo `REQ-CAT-020` (sección pública global de catálogos) queda **eliminado**: no existe descubrimiento entre negocios.

## Contratos compartidos del módulo

```php
/** Estado derivado de is_listed, published_at y expires_at. */
enum CatalogVisibility: string
{
    case Draft = 'draft';         // published_at es null
    case Listed = 'listed';       // publicado y visible en la vitrina del negocio
    case Unlisted = 'unlisted';   // publicado, accesible solo por enlace directo
    case Expired = 'expired';     // expires_at ya pasó

    public function isPubliclyAccessible(): bool { /* Listed o Unlisted */ }
    public function appearsInStorefront(): bool { /* solo Listed */ }
}
```

> **El orden de evaluación es parte del contrato** (fijado en `T-025`, `Catalog::visibility()`), porque los tres criterios se solapan: `published_at` nulo da `Draft` **aunque `expires_at` ya haya pasado** —lo que nunca se publicó no caducó, y su enlace nunca circuló, así que la respuesta es `404` y no `410`—; después la expiración, por delante de `is_listed`; y solo entonces `is_listed` decide entre `Listed` y `Unlisted`. Es derivado y **no hay columna `visibility`**: una columna que dijera `listed` mientras las fechas dicen `expired` seguiría vendiendo una campaña terminada.
>
> **Y un `published_at` futuro cuenta como publicado, al revés que en un producto.** `REQ-CAT-022` deja la programación de la publicación fuera de alcance —solo se programa la expiración— y `Draft` está definido como «`published_at` es null», sin más; `PublishCatalogAction` fija la fecha al publicar, así que una fecha futura no la produce el producto normal. En cambio `REQ-CAT-007` RB-5 **sí** trata el `published_at` futuro del producto como publicación programada, evaluada en la consulta. La asimetría es real y es de las que se copian mal de un lado al otro: cada una tiene su test.

```ts
export interface CatalogView {
  readonly slug: string;
  readonly name: string;
  readonly description: string | null;
  readonly cover: ImageSet | null;
  readonly business: OrganizationBrand;
  readonly shareUrl: string;
  readonly expiresAt: string | null;
  readonly productsCount: number;
  /** Estado de la suscripción de la cuenta activa, si hay sesión de cuenta. */
  readonly subscription: { readonly isSubscribed: boolean; readonly subscribersCount: number } | null;
  /** true en plan Free: se renderiza el sello "Hecho con Apartar". */
  readonly showsPlatformBadge: boolean;
}
```

---

### REQ-CAT-021 · Vista pública del catálogo

**Historia de usuario**
> Como cliente que recibió un enlace por WhatsApp, quiero abrirlo y explorar todo lo que ese negocio ofrece, con búsqueda y filtros, para elegir qué apartar sin registrarme.

**Contratos de datos**
- Ruta `GET /c/{catalog:slug}`. Props `{ catalog: CatalogView, products: Paginated<ProductCard>, filters, facets, business, rate }`.
- Middleware `ResolveCatalogTenant`: **el catálogo es la fuente del tenant** en toda la superficie pública (`REQ-ORG-002` RB-4).
- `CatalogShowController`, `CatalogFacetsQuery` (`REQ-CAT-006`).

**Reglas de negocio y precondiciones**
- RB-1 Es **la única superficie de descubrimiento** del cliente final. No hay índice global de catálogos ni de productos; se llega por el enlace que comparte el negocio, o por su vitrina si la tiene activada (`REQ-ORG-006` RB-6).
- RB-2 Accesible **sin autenticación** para catálogos `Listed` y `Unlisted`. Sin contraseña de acceso.
- RB-3 **De este catálogo se deriva todo el contexto**: la organización, su taxonomía, su tasa, sus cuentas de cobro, su branding y el hilo de chat. Es la pieza que sostiene el modelo multi-tenant en la cara pública.
- RB-4 Solo se renderizan productos publicados y no archivados; los `draft` y `archived` se omiten en silencio, conservando su relación con el catálogo (`REQ-CAT-007` RB-7).
- RB-5 Ofrece **búsqueda** (`REQ-CAT-002`), **filtros** por categoría y etiquetas y **orden** (`REQ-CAT-006`), todo acotado al catálogo. Es lo que sustituye al antiguo listado global.
- RB-6 Paginación por cursor, 12 productos por lote, con "Cargar más" (mobile-first, sin números de página).
- RB-7 Cada card muestra precio, cuota inicial e importe de cuota en bimoneda, con badge "Agotado" si procede.
- RB-8 Muestra el CTA **"Seguir este catálogo"** (`REQ-ACC-002`), y con sesión de cuenta refleja si ya lo sigue.
- RB-9 Muestra la identidad del negocio y, en plan Free, el sello "Hecho con Apartar" (`REQ-CAT-022` RB-5).
- RB-10 Metadatos Open Graph con nombre, descripción y portada, para que el enlace se previsualice al compartirlo. Es lo que hace que el enlace "se vea bien" en WhatsApp, que es el canal real de distribución.
- RB-11 Emite `catalog_viewed` con el `catalog_id` y la organización (`REQ-TLM-001`).
- RB-12 El acceso a un catálogo **no** requiere que la organización esté activa para los apartados existentes, pero sí para mostrarse: una organización suspendida tiene sus catálogos despublicados (`REQ-ORG-008` RB-2).

**Happy path**
1. El cliente abre el enlace; el middleware resuelve el tenant desde el slug del catálogo.
2. Ve el nombre y el logo del negocio, 12 productos del orden curado y el panel de filtros con las facetas presentes.
3. Busca, filtra, carga más y abre un producto.
4. Pulsa "Seguir este catálogo" y entra con Google; vuelve al mismo punto ya siguiéndolo.

**Sad paths y edge cases**
- SP-1 Slug inexistente → `404`.
- SP-2 Catálogo `Draft` → `404`, aunque se conozca el slug.
- SP-3 Catálogo `Expired` → **`410 Gone`** con una página que lo explica y enlaza a la vitrina del negocio si está activada. Se diferencia deliberadamente de `404`: "existió" no es lo mismo que "no existe".
- SP-4 Catálogo publicado con todos sus productos despublicados → se renderiza con estado vacío que invita a escribir al negocio; el negocio recibe la alerta en su dashboard.
- SP-5 Catálogo de una organización suspendida → está despublicado, así que `404`; los apartados previos de sus clientes siguen accesibles (`REQ-SUB-007` RB-3).
- SP-6 Sin tasa de cambio → solo USD (`REQ-FX-005`).
- SP-7 Catálogo con 500 productos → paginación por cursor sin `count(*)`, con índice `(catalog_id, position)`.
- SP-8 Cliente con sesión de cuenta en un catálogo que no sigue → el botón aparece en estado "Seguir"; nada más cambia.
- SP-9 Cursor manipulado → se reinicia en la primera página en lugar de fallar.

**Fuera de alcance inmediato**
- Apartar el catálogo completo en una operación.
- Comentarios o reacciones sobre el catálogo.
- Catálogo con acceso por contraseña.
- Personalización del catálogo por cliente.

**Gate de validación**
- Superficie esperada: `app/Http/Controllers/Catalog/CatalogShowController.php`, `app/Http/Middleware/ResolveCatalogTenant.php`, `app/Http/Resources/CatalogViewResource.php`, `app/Models/Catalog.php`, `resources/js/pages/catalog/CatalogShow.tsx`.
- Tipos esperados: `Catalog`, `CatalogVisibility`, `CatalogView`, `ProductCard`, `CatalogExpiredException`.
- Anti-`SHALLOW`: `CatalogShowController::__invoke()` (resolución de visibilidad, decisión entre `404`/`410`/render, filtrado de productos, facetas, paginación) y `ResolveCatalogTenant::handle()` (fijación del tenant desde el slug) superan 4 líneas.
- Tests: `unlisted` accesible por URL; `draft` → `404`; `expired` → `410`; el tenant se resuelve desde el catálogo y todas las consultas quedan filtradas (test de fuga con dos organizaciones con catálogos distintos); productos `draft` omitidos conservando la relación; orden curado por defecto; OG tags presentes; sello en Free.

---

### REQ-CAT-022 · Publicación, visibilidad, enlace y expiración

**Historia de usuario**
> Como negocio, quiero publicar un catálogo con un enlace corto que caduque en una fecha, para hacer campañas de temporada sin acordarme de retirarlas.

**Contratos de datos**
- Campos `catalogs.slug` (**`UNIQUE` global**), `is_listed`, `published_at`, `expires_at`.
- `PublishCatalogAction`, `UnpublishCatalogAction`.
- Rutas `POST|DELETE /backoffice/catalogos/{catalog}/publicar`.

**Reglas de negocio y precondiciones**
- RB-1 El `slug` es **único en toda la plataforma** (`Q4`), no por organización: es la URL pública `apartar.store/c/{slug}` y no puede colisionar. Al crearse se sugiere del nombre y, si está tomado, se **desambigua con sufijo** (`ofertas-septiembre-2`) en lugar de solo rechazar.
- RB-2 El `slug` es editable **solo mientras el catálogo esté `Draft`**; tras la primera publicación queda inmutable, para no romper los enlaces ya difundidos.
- RB-3 `is_listed` controla exclusivamente la aparición en la **vitrina del negocio** (`REQ-ORG-006` RB-6). **No** controla el acceso por URL: un `Unlisted` es accesible por enlace, y la UI lo dice con esas palabras ("cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina").
- RB-4 Requisitos para publicar, todos verificados en la `Action`:
  1. al menos **un producto publicado** en el catálogo;
  2. al menos **una cuenta de cobro activa** en la organización (`REQ-ORG-007` RB-4);
  3. `expires_at` vacío o futuro;
  4. cupo disponible en el límite `catalogs` del plan (`REQ-SUB-004` RB-3);
  5. email del `owner` verificado (`REQ-ORG-001` RB-5);
  6. organización no suspendida.
- RB-5 **Sello de plataforma:** en plan Free el catálogo muestra "Hecho con Apartar" con enlace al registro; con la función `branding` se oculta y se aplican logo y color del negocio (`REQ-ORG-006` RB-5). Es la palanca de conversión de la capa gratuita, y es visible pero discreta.
- RB-6 `expires_at` es opcional; al pasar, la visibilidad se deriva a `Expired` **sin job** (se evalúa en la query).
- RB-7 Despublicar (`published_at = null`) devuelve el catálogo a `Draft`, libera cupo del plan y su URL pasa a dar `404`. La UI advierte antes de confirmar que los enlaces difundidos dejarán de funcionar.
- RB-8 Publicar y despublicar **no** afecta a los apartados existentes originados en ese catálogo (`REQ-LAY-002`).
- RB-9 Despublicar **no borra las suscripciones**: quedan inactivas y revivirán si se republica (`REQ-ACC-002` RB-8).
- RB-10 Todo cambio de publicación, visibilidad o expiración se audita.

**Happy path**
1. El negocio crea el catálogo, añade 12 productos y fija `expires_at` a fin de mes.
2. Publica en modo `Unlisted`; la UI valida los seis requisitos y le da la URL con botón de copiar.
3. La difunde por WhatsApp; el día 1 del mes siguiente el enlace responde `410` solo.

**Sad paths y edge cases**
- SP-1 Publicar sin productos publicados → `422` indicando qué falta.
- SP-2 Publicar sin cuenta de cobro activa → `422` con el enlace para crearla.
- SP-3 Publicar con el límite de catálogos del plan agotado → `422` con el plan que lo desbloquea (`REQ-SUB-004`).
- SP-4 Publicar sin email verificado → `422` con el reenvío de la verificación.
- SP-5 `expires_at` en el pasado → `422`.
- SP-6 Editar el `slug` de un catálogo publicado → `422` con el motivo.
- SP-7 Colisión de slug con uno de **otra organización** → se desambigua con sufijo (RB-1); los slugs no se reciclan ni se liberan al despublicar.
- SP-8 Republicar un catálogo expirado sin mover `expires_at` → `422`; hay que vaciarla o moverla al futuro.
- SP-9 Organización suspendida intentando publicar → `403` (`REQ-ORG-008` RB-3).
- SP-10 Catálogo con suscriptores que se despublica → `410` para nuevos visitantes, suscripciones inactivas, apartados intactos.

**Fuera de alcance inmediato**
- Enlaces con contraseña o token por destinatario.
- Programación de la publicación futura (solo se programa la expiración).
- Acortador de URLs propio y dominio propio (plan Business, siguiente iteración).
- Métricas de aperturas por enlace más allá de `catalog_viewed`.

**Gate de validación**
- Superficie esperada: `app/Actions/Catalog/PublishCatalogAction.php`, `app/Actions/Catalog/UnpublishCatalogAction.php`, `app/Http/Requests/Backoffice/PublishCatalogRequest.php`, `app/Models/Catalog.php`, `app/Support/UniqueCatalogSlug.php`, `resources/js/features/catalogs/components/ShareLinkField.tsx`.
- Tipos esperados: `CatalogVisibility`, `PublishCatalogData`, `UniqueCatalogSlug`, `SlugLockedException`, `CatalogNotPublishableException`.
- Anti-`SHALLOW`: `PublishCatalogAction::handle()` (los seis requisitos de RB-4, transición de visibilidad, consumo del plan, auditoría), `Catalog::visibility()` y `UniqueCatalogSlug::generate()` superan 4 líneas en sus archivos.
- Tests: un test por cada uno de los seis requisitos de RB-4; slug único global con desambiguación entre organizaciones; slug inmutable tras publicar; `expires_at` pasada rechazada; despublicar libera cupo y da `404`; expirado da `410`; suscripciones sobreviven a la despublicación; sello presente en Free y ausente con `branding`; auditoría escrita.

---

### REQ-CAT-023 · Composición y orden curado del catálogo

**Historia de usuario**
> Como negocio, quiero elegir qué productos entran en cada catálogo y en qué orden se ven, para poner primero lo que quiero vender.

**Contratos de datos**
- Tabla pivote `catalog_product` (`catalog_id`, `product_id`, `position`, `created_at`), PK compuesta.
- `SyncCatalogProductsAction(Catalog $catalog, list<int> $orderedProductIds)`.
- Rutas `PATCH /backoffice/catalogos/{catalog}/productos`.

**Reglas de negocio y precondiciones**
- RB-1 Relación N:M dentro de la organización: un producto puede pertenecer a varios catálogos del **mismo** negocio. Nunca a un catálogo de otra organización (validado en la `Action`, `REQ-ORG-002` RB-7).
- RB-2 `position` es contiguo desde 1; la acción **recalcula** todas las posiciones en cada guardado, sin dejar huecos.
- RB-3 `created_at` del pivote registra **cuándo entró el producto al catálogo**, y es el campo que ordena el feed y determina qué es "novedad" (`REQ-ACC-003` RB-2 y RB-4). No se actualiza al reordenar: reordenar no convierte un producto viejo en novedad.
- RB-4 Un producto solo aparece una vez por catálogo (PK compuesta).
- RB-5 Se pueden añadir productos `draft`: no se renderizan hasta publicarse, lo que permite preparar una campaña completa antes de lanzarla.
- RB-6 El número de productos por catálogo está limitado por el plan (`max_products_per_catalog`, `REQ-SUB-004` RB-3). El tope se comprueba sobre el total del pivote, incluidos los `draft`.
- RB-7 Publicar exige al menos un producto **publicado** (`REQ-CAT-022` RB-4).
- RB-8 El reordenamiento es drag & drop con persistencia explícita ("Guardar orden"), no autoguardado por arrastre.
- RB-9 Quitar un producto del catálogo **no** afecta a los apartados originados desde él (`REQ-LAY-002`).

**Happy path**
1. El negocio busca productos por nombre o SKU y añade 12 al catálogo.
2. Los arrastra para fijar el orden y pulsa "Guardar orden".
3. La acción reescribe el pivote en una transacción con posiciones 1..12, conservando el `created_at` de cada uno.

**Sad paths y edge cases**
- SP-1 `product_id` inexistente, archivado o **de otra organización** → `422` sin aplicar nada (transacción); el de otra organización además no existe bajo el global scope.
- SP-2 IDs duplicados en la petición → se deduplican preservando la primera aparición.
- SP-3 Superar `max_products_per_catalog` → `422` con el plan que lo desbloquea y el número exacto de excedente.
- SP-4 Dos usuarios reordenando el mismo catálogo → gana el último guardado; la UI muestra la marca de última modificación y su autor.
- SP-5 Quitar del catálogo un producto con apartados vigentes → permitido y sin efecto sobre ellos.
- SP-6 Reordenar un catálogo publicado → efecto inmediato en la vista pública; no genera novedades ni digests (RB-3).
- SP-7 Guardar con 0 productos en un catálogo publicado → `422`: dejaría el catálogo publicado y vacío. En `Draft` sí se permite.
- SP-8 Añadir un producto que ya está en otros 5 catálogos del mismo negocio → permitido (RB-1).

**Fuera de alcance inmediato**
- Reglas automáticas de composición ("todos los de la etiqueta X").
- Orden dinámico por métricas de telemetría.
- Duplicar un catálogo.
- Productos destacados dentro del catálogo.

**Gate de validación**
- Superficie esperada: `app/Actions/Catalog/SyncCatalogProductsAction.php`, `app/Http/Requests/Backoffice/SyncCatalogProductsRequest.php`, `resources/js/features/catalogs/components/CatalogProductSorter.tsx`, `resources/js/features/catalogs/hooks/useSortableProducts.ts`.
- Tipos esperados: `SyncCatalogProductsAction`, `CatalogProductOrder`, `CrossTenantRelationException`, `PlanLimitReachedException`.
- Anti-`SHALLOW`: `SyncCatalogProductsAction::handle()` (deduplicación, validación de pertenencia al tenant, tope de plan, reescritura de posiciones preservando `created_at`, transacción) supera 4 líneas.
- Tests: posiciones contiguas desde 1; `created_at` **no** cambia al reordenar (test explícito de RB-3); deduplicación; producto de otra organización rechazado (fuga); tope de plan; `draft` admitido; vaciar un catálogo publicado rechazado; producto en varios catálogos del mismo negocio.
