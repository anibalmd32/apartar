<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Catalog\CatalogFilters;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\ImageSet;
use App\Domain\Subscription\LimitEnforcer;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogViewResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Catalog;
use App\Models\Product;
use App\Queries\CatalogFacetsQuery;
use App\Queries\CatalogProductsQuery;
use App\Support\CatalogCursor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * El enlace que un desconocido abre: la vista pública del catálogo
 * (`REQ-CAT-021`), con su búsqueda, sus filtros y su orden (`REQ-CAT-002`,
 * `REQ-CAT-006`).
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
 * ## Cuatro props, y quién pide cada una
 *
 * La primera carga las lleva todas. Después, la navegación entre filtros es
 * una recarga parcial de `products` y `filters` (`REQ-CAT-006` RB-7), y
 * «Cargar más» solo de `products`: ni el catálogo ni las facetas se vuelven a
 * mandar, porque no dependen de lo que se filtre. Las facetas **sí** se leen
 * en toda petición que traiga categoría o etiquetas, aunque no viajen: son la
 * lista de lo que se acepta (`CatalogFilters::restrictedTo()`), y lo que el
 * panel no ofrece no se aplica.
 *
 * ## Qué no hace, todavía
 *
 * `catalog_viewed` (RB-11), `search_performed` (`REQ-CAT-002` RB-5) y
 * `product_list_filtered` (`REQ-CAT-006` RB-8) los emite `T-084` con el bus de
 * telemetría, y el CTA de seguir (RB-8) llega con las cuentas de H6. Ni un
 * emisor que trague eventos ni un botón que no haga nada.
 */
final class CatalogShowController extends Controller
{
    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly CatalogProductsQuery $products,
        private readonly CatalogFacetsQuery $facets,
    ) {}

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

        // Cerradas, para que cada recarga parcial evalúe solo lo que pide, y
        // memorizadas, porque hay dos que se leen dos veces: las facetas, por
        // el panel y por los filtros; la vista, por las props y por Open Graph.
        $facets = null;
        $filters = null;
        $view = null;

        $readFacets = function () use (&$facets, $catalog): array {
            return $facets ??= $this->facets->forCatalog($catalog);
        };
        $readFilters = function () use (&$filters, $request, $readFacets): CatalogFilters {
            return $filters ??= $this->filters($request, $readFacets);
        };
        $readView = function () use (&$view, $catalog, $request): array {
            return $view ??= $this->catalogView($catalog, $request);
        };

        $response = Inertia::render('catalog/CatalogShow', [
            'catalog' => $readView,
            'products' => fn (): array => $this->grid($catalog, $readFilters(), $request),
            'filters' => fn (): array => $readFilters()->toArray(),
            'facets' => $readFacets,
        ]);

        // Open Graph (RB-10) solo en la primera carga, que es la única que
        // llega como HTML: el rastreador de WhatsApp no ejecuta JavaScript,
        // así que unas etiquetas puestas desde React no las vería nadie.
        return $request->header('X-Inertia') === null
            ? $response->withViewData(['meta' => $this->openGraph($readView(), $catalog->organization->name)])
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
     * Los filtros de la query string, restringidos a lo que el panel ofrece
     * (`REQ-CAT-006` SP-1, SP-4, SP-5).
     *
     * Sin categoría ni etiquetas en la petición, las facetas no se leen para
     * esto: una búsqueda o un cambio de orden no paga por comprobar nada.
     *
     * @param  callable(): array{categories: list<array{slug: string, name: string, count: int}>, tags: list<array{slug: string, name: string, count: int}>}  $facets
     */
    private function filters(Request $request, callable $facets): CatalogFilters
    {
        $filters = CatalogFilters::fromRequest($request);

        if ($filters->category === null && $filters->tags === []) {
            return $filters;
        }

        $present = $facets();

        return $filters->restrictedTo(
            array_column($present['categories'], 'slug'),
            array_column($present['tags'], 'slug'),
        );
    }

    /**
     * Un lote de la parrilla con los filtros aplicados (RB-4 a RB-6, SP-7,
     * SP-9).
     *
     * Qué se consulta lo decide `CatalogProductsQuery`; aquí solo se pinta:
     * doce cards con su categoría, sus etiquetas y sus fotos ya cargadas, y el
     * cursor del lote siguiente si lo hay. Sin `count(*)`: el cliente solo
     * necesita saber si hay más para ofrecer el botón.
     *
     * @return array{data: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function grid(Catalog $catalog, CatalogFilters $filters, Request $request): array
    {
        $page = $this->products->page($catalog, $filters, $request->query(CatalogCursor::NAME));

        return [
            'data' => array_map(
                fn (Product $product): array => (new ProductCardResource($product, $catalog))->resolve($request),
                $page['items'],
            ),
            'nextCursor' => $page['nextCursor'],
        ];
    }

    /**
     * La foto del primer producto visible en el orden curado, para el catálogo
     * sin portada (`REQ-BOF-024` SP-9).
     *
     * Solo se consulta cuando hace falta: con portada propia no se toca.
     */
    private function fallbackCover(Catalog $catalog): ?ImageSet
    {
        if ($catalog->cover_path !== null && $catalog->cover_path !== '') {
            return null;
        }

        $first = $this->products->visible($catalog)
            ->with('media')
            ->orderBy('catalog_position')
            ->orderBy('products.id')
            ->first();

        $media = $first?->getFirstMedia(Product::IMAGES);

        return $media instanceof Media ? ImageSet::of($media) : null;
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
