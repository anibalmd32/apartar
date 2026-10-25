<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CatalogProductOrder;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\CatalogProductLimitReachedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SyncCatalogProductsAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CatalogProductOrder $order,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @param  list<int>  $productIds
     *
     * @throws AuthorizationException
     * @throws CrossTenantRelationException
     * @throws ValidationException
     * @throws CatalogProductLimitReachedException
     */
    public function handle(Catalog $catalog, array $productIds, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        DB::transaction(function () use ($catalog, $productIds, $actor): void {
            Catalog::query()->whereKey($catalog->getKey())->lockForUpdate()->firstOrFail();

            $requested = $this->order->deduplicate($productIds);

            $this->assertEveryProductIsAddable($catalog, $requested);
            $this->assertNotEmptyingAPublishedCatalog($catalog, $requested);

            $current = $catalog->products()->pluck('products.id')->map(fn (mixed $id): int => (int) $id)->all();

            $this->limits->assertCatalogHolds(
                count($current),
                count($requested),
                addsNew: array_diff($requested, $current) !== [],
            );

            $this->order->apply($catalog, $requested);

            $catalog->touchedBy($actor);
        });
    }

    /**
     * @param  list<int>  $productIds
     *
     * @throws CrossTenantRelationException
     * @throws ValidationException
     */
    private function assertEveryProductIsAddable(Catalog $catalog, array $productIds): void
    {
        $alreadyIn = $catalog->products()
            ->pluck('products.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($productIds as $productId) {
            $product = Product::query()->find($productId);

            if (! $product instanceof Product) {
                throw CrossTenantRelationException::between(Catalog::class, Product::class);
            }

            if ($product->status === ProductStatus::Archived && ! in_array($productId, $alreadyIn, true)) {
                throw ValidationException::withMessages([
                    'products' => sprintf(
                        '«%s» está archivado y no se puede añadir a un catálogo. Restáuralo primero: vuelve a '
                        .'borrador y podrás publicarlo y ponerlo donde quieras.',
                        $product->name,
                    ),
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $productIds
     *
     * @throws ValidationException
     */
    private function assertNotEmptyingAPublishedCatalog(Catalog $catalog, array $productIds): void
    {
        if ($productIds !== [] || $catalog->visibility() === CatalogVisibility::Draft) {
            return;
        }

        throw ValidationException::withMessages([
            'products' => sprintf(
                'No puedes dejar «%s» sin productos mientras esté publicado: su enlace ya circula y quien lo '
                .'abra no vería nada. Despublícalo primero, o deja al menos un producto.',
                $catalog->name,
            ),
        ]);
    }
}
