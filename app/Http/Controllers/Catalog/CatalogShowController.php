<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\ImageSet;
use App\Domain\Subscription\LimitEnforcer;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogViewResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Catalog;
use App\Models\Product;
use App\Support\CatalogCursor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * El enlace que un desconocido abre: la vista pública del catálogo
 * (`REQ-CAT-021`).
 *
 * Es la primera superficie del proyecto sin sesión y sin backoffice, y la que
 * sostiene el modelo multi-tenant en la cara pública (RB-3). Cuando este
 * controlador corre, `ResolveCatalogTenant` ya fijó la organización **desde el
 * catálogo** y el binding lo resolvió con el global scope puesto: ninguna de
 * las consultas de aquí escribe un `where organization_id`, y ninguna lo
 * necesita.
 *
 * ## Tres respuestas, y las decide `visibility()`
 *
 *  - `Draft` → `404`: nunca existió para el público, aunque se conozca el slug
 *    (SP-2). Una organización suspendida tiene sus catálogos despublicados, así
 *    que cae aquí sin rama propia (SP-5, `REQ-ORG-008` RB-2).
 *  - `Expired` → `410`: existió y terminó (SP-3). Ver `CatalogExpiredException`.
 *  - `Listed` y `Unlisted` → la página. `is_listed` **no** controla el acceso
 *    (`REQ-CAT-022` RB-3): solo si aparece en la vitrina.
 *
 * El `match` no lleva `default`: un quinto estado de visibilidad tiene que
 * decidir aquí su respuesta, no heredar una por descuido.
 *
 * ## Qué no hace, todavía
 *
 * Búsqueda, filtros y orden son `T-034` (`REQ-CAT-002`, `REQ-CAT-006`): la
 * parrilla sale en el **orden curado**, que es el defecto de RB-5, y las
 * facetas ya tienen su consulta (`CatalogFacetsQuery`) esperando el panel.
 * `catalog_viewed` (RB-11) lo emite `T-084` con el bus de telemetría, y el CTA
 * de seguir (RB-8) llega con las cuentas de H6. Ni un emisor que trague eventos
 * ni un botón que no haga nada.
 */
final class CatalogShowController extends Controller
{
    public function __construct(private readonly LimitEnforcer $limits) {}

    /**
     * @throws ModelNotFoundException<Catalog> si el catálogo es un borrador (SP-2, SP-5)
     * @throws CatalogExpiredException si su campaña terminó (SP-3)
     */
    public function __invoke(Request $request, Catalog $catalog): Response
    {
        match ($catalog->visibility()) {
            CatalogVisibility::Draft => throw (new ModelNotFoundException)->setModel(Catalog::class, [$catalog->slug]),
            CatalogVisibility::Expired => throw CatalogExpiredException::of($catalog),
            CatalogVisibility::Listed, CatalogVisibility::Unlisted => null,
        };

        // Cerrados para que la recarga parcial de «Cargar más» —que pide solo
        // `products`— no vuelva a leer el plan ni la portada. Y memorizado,
        // porque en la primera carga lo leen a la vez las props y Open Graph.
        $resolved = null;
        $view = function () use (&$resolved, $catalog, $request): array {
            return $resolved ??= $this->catalogView($catalog, $request);
        };

        $response = Inertia::render('catalog/CatalogShow', [
            'catalog' => $view,
            'products' => fn (): array => $this->products($catalog, $request),
        ]);

        // Open Graph (RB-10) solo en la primera carga, que es la única que
        // llega como HTML: el rastreador de WhatsApp no ejecuta JavaScript,
        // así que unas etiquetas puestas desde React no las vería nadie.
        return $request->header('X-Inertia') === null
            ? $response->withViewData(['meta' => $this->openGraph($view(), $catalog->organization->name)])
            : $response;
    }

    /**
     * El `CatalogView` de esta respuesta.
     *
     * @return array<string, mixed>
     */
    private function catalogView(Catalog $catalog, Request $request): array
    {
        return (new CatalogViewResource($catalog, $this->limits->plan(), $this->fallbackCover($catalog)))
            ->resolve($request);
    }

    /**
     * Un lote de la parrilla, en el orden curado (RB-4, RB-6, SP-7, SP-9).
     *
     * Lo que se consulta, entero: los productos del catálogo **publicados**
     * —`scopePublished()`, de `T-030`, que deja fuera `draft`, `archived` y
     * programados—, ordenados por `catalog_product.position` y desempatados
     * por `id`, doce a partir del cursor. Con la categoría, las etiquetas y las
     * fotos cargadas de una vez, para que doce cards no sean doce consultas.
     *
     * Los omitidos **conservan su relación** con el catálogo: el filtro está
     * en esta consulta y no en `Catalog::products()`, que no filtra a
     * propósito (`REQ-CAT-007` RB-7). Desarchivar uno lo devuelve a su sitio
     * sin recomponer nada.
     *
     * Sin `count(*)`: la paginación por cursor no necesita el total, y el
     * cliente solo necesita saber si hay más —`nextCursor`— para ofrecer el
     * botón.
     *
     * @return array{data: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function products(Catalog $catalog, Request $request): array
    {
        $query = $this->visibleProducts($catalog)->with(['category', 'tags', 'media']);

        foreach (CatalogCursor::COLUMNS as $column) {
            $query->orderBy($column);
        }

        $page = $query->cursorPaginate(
            CatalogCursor::PER_PAGE,
            ['*'],
            CatalogCursor::NAME,
            CatalogCursor::from($request->query(CatalogCursor::NAME)) ?? CatalogCursor::FIRST_PAGE,
        );

        /** @var list<Product> $items */
        $items = array_values($page->items());

        return [
            'data' => array_map(
                fn (Product $product): array => (new ProductCardResource($product, $catalog))->resolve($request),
                $items,
            ),
            'nextCursor' => $page->nextCursor()?->encode(),
        ];
    }

    /**
     * La foto del primer producto visible, para el catálogo sin portada
     * (`REQ-BOF-024` SP-9).
     *
     * Solo se consulta cuando hace falta: con portada propia no se toca.
     */
    private function fallbackCover(Catalog $catalog): ?ImageSet
    {
        if ($catalog->cover_path !== null && $catalog->cover_path !== '') {
            return null;
        }

        $first = $this->visibleProducts($catalog)
            ->with('media')
            ->orderBy(CatalogCursor::COLUMNS[0])
            ->first();

        $media = $first?->getFirstMedia(Product::IMAGES);

        return $media instanceof Media ? ImageSet::of($media) : null;
    }

    /**
     * Los productos del catálogo que el público puede ver.
     *
     * @return BelongsToMany<Product, Catalog>
     */
    private function visibleProducts(Catalog $catalog): BelongsToMany
    {
        return $catalog->products()->published();
    }

    /**
     * Las etiquetas Open Graph del enlace (RB-10).
     *
     * Es lo que hace que el enlace «se vea bien» en WhatsApp —nombre,
     * descripción y portada en la previsualización—, y WhatsApp es el canal
     * real de distribución. La descripción se recorta porque las
     * previsualizaciones cortan hacia los doscientos caracteres, y mejor
     * cortar entre palabras que a mitad de una.
     *
     * @param  array<string, mixed>  $view  el `CatalogView` ya resuelto
     * @param  string  $businessName  el nombre del negocio del catálogo
     * @return array{title: string, description: string, image: string|null, url: string, siteName: string}
     */
    private function openGraph(array $view, string $businessName): array
    {
        /** @var array{card: string}|null $cover */
        $cover = $view['cover'];
        $description = is_string($view['description']) && trim($view['description']) !== ''
            ? $view['description']
            : sprintf('Catálogo de %s. Aparta lo que te guste y págalo en cuotas.', $businessName);

        return [
            'title' => sprintf('%s · %s', (string) $view['name'], $businessName),
            'description' => Str::limit(trim($description), 200),
            'image' => $cover === null ? null : $cover['card'],
            'url' => (string) $view['shareUrl'],
            'siteName' => (string) config('app.name'),
        ];
    }
}
