<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Catalog\NewArrival;
use App\Domain\Catalog\SearchMode;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FeedQuery
{
    public const PAGE_SIZE = 20;

    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{items: list<array{product: Product, catalog: Catalog, organization: Organization, enteredAt: CarbonImmutable, isNew: bool}>, nextCursor: string|null}
     */
    public function forAccount(Account $account, FeedFilters $filters, ?string $cursor = null, int $limit = self::PAGE_SIZE): array
    {
        $after = FeedCursor::decode($cursor);
        $catalogIds = $account->subscriptions()->pluck('catalog_id')->map(fn ($id): int => (int) $id)->all();
        $byOrganization = [];

        foreach ($this->resolver->organizationsOfCatalogs($catalogIds) as $catalogId => $organization) {
            $byOrganization[(int) $organization->getKey()]['organization'] = $organization;
            $byOrganization[(int) $organization->getKey()]['catalogs'][] = $catalogId;
        }

        $candidates = [];

        foreach ($byOrganization as $group) {
            array_push($candidates, ...$this->context->runFor(
                $group['organization'],
                fn (): array => $this->pageOf($group['organization'], $group['catalogs'], $filters, $after, $limit),
            ));
        }

        usort($candidates, fn (array $a, array $b): int => FeedCursor::keyOf($b) <=> FeedCursor::keyOf($a));

        $items = array_slice($candidates, 0, $limit);
        $last = $items === [] ? null : $items[array_key_last($items)];

        return [
            'items' => $items,
            'nextCursor' => count($candidates) > $limit && $last !== null ? FeedCursor::encode($last) : null,
        ];
    }

    /**
     * @param  list<int>  $catalogIds
     * @return list<array{product: Product, catalog: Catalog, organization: Organization, enteredAt: CarbonImmutable, isNew: bool}>
     */
    private function pageOf(Organization $organization, array $catalogIds, FeedFilters $filters, ?FeedCursor $after, int $limit): array
    {
        /** @var Collection<int, Catalog> $catalogs */
        $catalogs = Catalog::query()
            ->whereIn('id', $catalogIds)
            ->when($filters->catalogSlug !== null, fn ($query) => $query->where('slug', $filters->catalogSlug))
            ->get()
            ->filter(fn (Catalog $catalog): bool => $catalog->visibility()->isPubliclyAccessible())
            ->keyBy('id');

        if ($catalogs->isEmpty()) {
            return [];
        }

        $entered = NewArrival::ENTERED_SQL;

        $ranked = Product::query()
            ->published()
            ->join('catalog_product', 'catalog_product.product_id', '=', 'products.id')
            ->whereIn('catalog_product.catalog_id', $catalogs->keys()->all())
            ->when($filters->term !== null, fn ($query) => $query->search($filters->term, SearchMode::Like))
            ->selectRaw("`products`.`id` as `product_id`, `catalog_product`.`catalog_id` as `catalog_id`, {$entered} as `entered_at`")
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY `products`.`id` ORDER BY {$entered} DESC, `catalog_product`.`catalog_id` DESC) as `position`")
            ->toBase();

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('position', 1)
            ->when($after !== null, fn ($query) => $after->constrain($query, (int) $organization->getKey()))
            ->orderByDesc('entered_at')
            ->orderByDesc('product_id')
            ->limit($limit + 1)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $products = Product::query()->with(['category', 'tags', 'media'])->whereIn('id', $rows->pluck('product_id')->all())->get()->keyBy('id');
        $page = [];

        foreach ($rows as $row) {
            $product = $products->get($row->product_id);
            $catalog = $catalogs->get($row->catalog_id);

            if (! $product instanceof Product || ! $catalog instanceof Catalog) {
                continue;
            }

            $enteredAt = CarbonImmutable::parse((string) $row->entered_at);

            $page[] = [
                'product' => $product,
                'catalog' => $catalog,
                'organization' => $organization,
                'enteredAt' => $enteredAt,
                'isNew' => NewArrival::isNew($enteredAt),
            ];
        }

        return $page;
    }
}
