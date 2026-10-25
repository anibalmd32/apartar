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

final class ProductShowController extends Controller
{
    public const RELATED = 4;

    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly CatalogProductsQuery $products,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
    ) {}

    /**
     * @throws ModelNotFoundException<Catalog>
     * @throws CatalogExpiredException
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
     * @return list<Product>
     */
    private function related(Catalog $catalog, Product $product): array
    {
        $query = $this->products->visible($catalog)
            ->with(['category', 'tags', 'media'])
            ->whereKeyNot($product->getKey());

        if ($product->category_id !== null) {
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
