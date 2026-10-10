<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Catalog\PublicCatalogAccess;
use App\Domain\Subscription\LimitEnforcer;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogViewResource;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductDetailResource;
use App\Models\Catalog;
use App\Models\Product;
use App\Queries\ActivePaymentAccountsQuery;
use App\Queries\CatalogProductsQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La ficha de un producto dentro de su catálogo (`REQ-CAT-003`): donde el
 * cliente decide si aparta.
 *
 * ## Los cuatro `404` se deciden antes de llegar aquí
 *
 * Cuando este controlador corre, `ResolveCatalogTenant` ya fijó la
 * organización desde el catálogo, y `Catalog::resolveChildRouteBinding()` ya
 * resolvió `{product:slug}`: primero el catálogo (`PublicCatalogAccess`,
 * SP-3), después el producto **por la relación con ese catálogo** y solo si
 * está publicado. Lo que no está en el catálogo (SP-1), lo `draft` o archivado
 * (SP-2), el slug que cambió (SP-9) y lo de otra organización dan el mismo
 * `404`, sin decir cuál fue. La guarda se repite aquí porque no cuesta una
 * consulta, y porque un *binding* es lo primero que alguien desactiva sin
 * saber qué sostenía.
 *
 * ## Tres props, y las dos que el contrato añadía de más
 *
 * `catalog` (`CatalogView`), `product` (`ProductDetail`) y `related`
 * (`ProductCard[]`). El contrato de [11] añadía **`business`** —que ya viaja
 * en `catalog.business`— y **`rate`** —que viaja sola en cada respuesta por
 * `HandleInertiaRequests` (`CurrencyPageProps`), la misma instancia de
 * `CurrentRate` con la que `ProjectsMoney` proyectó cada importe—. Declararla
 * otra vez aquí abriría la puerta a un sello con una tasa y unas cifras con
 * otra. Las dos decisiones están escritas bajo `REQ-CAT-003`.
 *
 * El plan del negocio se lee con `LimitEnforcer::plan()`, como en la vista del
 * catálogo, **solo para la marca y el sello** (RB-6). Ninguna decisión de
 * límite se toma aquí: el motivo «el negocio no puede recibir apartados» es de
 * `T-067` (`UnavailableReason`).
 *
 * ## Qué no hace, todavía
 *
 * `product_viewed` y la permanencia (RB-8) son de `T-084`, y el chat de SP-6
 * de `T-080`. El formulario al que lleva «Apartar» (RB-7) llegó con `T-045b`:
 * es `LayawayController`.
 */
final class ProductShowController extends Controller
{
    /**
     * Relacionados como mucho (RB-9).
     */
    public const RELATED = 4;

    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly CatalogProductsQuery $products,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
    ) {}

    /**
     * @throws ModelNotFoundException<Catalog> si el catálogo es un borrador (SP-3)
     * @throws CatalogExpiredException si su campaña terminó (SP-3)
     */
    public function __invoke(Request $request, Catalog $catalog, Product $product): Response
    {
        PublicCatalogAccess::ensureOpen($catalog);

        $product->load(['category', 'tags', 'media', 'specifications']);

        return Inertia::render('catalog/ProductShow', [
            'catalog' => CatalogViewResource::forVisitor($catalog, $this->limits->plan(), $this->products)->resolve($request),
            'product' => (new ProductDetailResource($product, $catalog, $this->paymentAccounts->all(), $this->limits->offersLayaways()))->resolve($request),
            'related' => array_map(
                fn (Product $related): array => (new ProductCardResource($related, $catalog))->resolve($request),
                $this->related($catalog, $product),
            ),
        ]);
    }

    /**
     * Hasta cuatro del **mismo catálogo**, sin el actual (RB-9).
     *
     * Los de su categoría primero y, si no llegan a cuatro, los demás; dentro
     * de cada grupo, el orden curado. Una sola consulta, con la base de la
     * parrilla (`CatalogProductsQuery::visible()`): un producto que el negocio
     * no puso en este catálogo no es un relacionado, aunque sea suyo. Y sin
     * `count(*)`.
     *
     * @return list<Product>
     */
    private function related(Catalog $catalog, Product $product): array
    {
        $query = $this->products->visible($catalog)
            ->with(['category', 'tags', 'media'])
            ->whereKeyNot($product->getKey());

        if ($product->category_id !== null) {
            // `(category_id = ?) desc`: 1 los de su categoría, 0 los demás.
            $query->orderByRaw('(products.category_id = ?) desc', [$product->category_id]);
        }

        /** @var list<Product> $related */
        $related = $query
            ->orderBy('catalog_position')
            ->orderBy('products.id')
            ->limit(self::RELATED)
            ->get()
            ->all();

        return $related;
    }
}
