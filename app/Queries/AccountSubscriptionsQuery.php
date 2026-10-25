<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Organization;
use App\Models\Product;
use App\Support\CatalogShareLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AccountSubscriptionsQuery
{
    public const NEW_DAYS = 14;

    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<SubscriptionRow>
     */
    public function forAccount(Account $account): array
    {
        /** @var Collection<int, CatalogSubscription> $subscriptions */
        $subscriptions = $account->subscriptions()->orderByDesc('subscribed_at')->orderByDesc('id')->get();

        if ($subscriptions->isEmpty()) {
            return [];
        }

        $owners = $this->resolver->organizationsOfCatalogs($subscriptions->pluck('catalog_id')->map(fn ($id): int => (int) $id)->all());
        $since = CarbonImmutable::now()->subDays(self::NEW_DAYS);

        /** @var array<int, array{catalog: Catalog, organization: Organization, total: int, fresh: int}> $byCatalog */
        $byCatalog = [];

        foreach ($this->groupByOrganization($owners) as [$organization, $catalogIds]) {
            $this->context->runFor($organization, function () use ($organization, $catalogIds, $since, &$byCatalog): void {
                $counts = Product::query()
                    ->published()
                    ->join('catalog_product', 'catalog_product.product_id', '=', 'products.id')
                    ->whereIn('catalog_product.catalog_id', $catalogIds)
                    ->groupBy('catalog_product.catalog_id')
                    ->selectRaw('catalog_product.catalog_id as catalog_id, count(*) as total, sum(catalog_product.created_at >= ?) as fresh', [$since])
                    ->toBase()
                    ->get()
                    ->keyBy('catalog_id');

                foreach (Catalog::query()->whereIn('id', $catalogIds)->get() as $catalog) {
                    $row = $counts->get($catalog->getKey());

                    $byCatalog[(int) $catalog->getKey()] = [
                        'catalog' => $catalog,
                        'organization' => $organization,
                        'total' => (int) ($row->total ?? 0),
                        'fresh' => (int) ($row->fresh ?? 0),
                    ];
                }
            });
        }

        $rows = [];

        foreach ($subscriptions as $subscription) {
            $found = $byCatalog[$subscription->catalog_id] ?? null;

            if ($found === null) {
                continue;
            }

            $catalog = $found['catalog'];
            $active = $catalog->visibility()->isPubliclyAccessible();

            $rows[] = new SubscriptionRow(
                catalogId: (int) $catalog->getKey(),
                catalogSlug: $catalog->slug,
                catalogName: $catalog->name,
                businessName: $found['organization']->name,
                active: $active,
                muted: $subscription->muted_at !== null,
                productsCount: $active ? $found['total'] : 0,
                newCount: $active ? $found['fresh'] : 0,
                subscribedAt: $subscription->subscribed_at,
                url: $active ? CatalogShareLink::of($catalog->slug) : null,
            );
        }

        return $rows;
    }

    /**
     * @param  array<int, Organization>  $owners
     * @return list<array{Organization, list<int>}>
     */
    private function groupByOrganization(array $owners): array
    {
        $groups = [];

        foreach ($owners as $catalogId => $organization) {
            $groups[(int) $organization->getKey()][0] = $organization;
            $groups[(int) $organization->getKey()][1][] = $catalogId;
        }

        return array_values($groups);
    }
}
