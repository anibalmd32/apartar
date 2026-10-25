<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Carbon;

final class SubscriberListQuery
{
    public const PER_PAGE = 50;

    /**
     * @return array{rows: list<array{displayName: string, avatarUrl: string|null, subscribedAt: string, catalogName: string, isCustomer: bool, customerUrl: string|null}>, nextPageUrl: string|null}
     */
    public function forOrganization(): array
    {
        $catalogs = Catalog::query()->pluck('name', 'id');

        if ($catalogs->isEmpty()) {
            return ['rows' => [], 'nextPageUrl' => null];
        }

        /** @var Paginator<int, CatalogSubscription> $page */
        $page = CatalogSubscription::query()
            ->join('accounts', 'accounts.id', '=', 'catalog_subscriptions.account_id')
            ->whereIn('catalog_subscriptions.catalog_id', $catalogs->keys()->all())
            ->orderByDesc('catalog_subscriptions.subscribed_at')
            ->orderByDesc('catalog_subscriptions.id')
            ->select([
                'catalog_subscriptions.catalog_id',
                'catalog_subscriptions.account_id',
                'catalog_subscriptions.subscribed_at',
                'accounts.display_name',
                'accounts.avatar_url',
            ])
            ->simplePaginate(self::PER_PAGE);

        /** @var list<CatalogSubscription> $items */
        $items = $page->items();
        $accountIds = array_values(array_unique(array_map(fn (CatalogSubscription $row): int => (int) $row->account_id, $items)));
        $customers = $accountIds === [] ? collect() : Customer::query()->whereIn('account_id', $accountIds)->pluck('id', 'account_id');

        return [
            'rows' => array_map(function (CatalogSubscription $row) use ($catalogs, $customers): array {
                $customerId = $customers->get((int) $row->account_id);

                return [
                    'displayName' => (string) $row->getAttribute('display_name'),
                    'avatarUrl' => $row->getAttribute('avatar_url'),
                    'subscribedAt' => Carbon::parse($row->subscribed_at)->toIso8601String(),
                    'catalogName' => (string) $catalogs->get($row->catalog_id),
                    'isCustomer' => $customerId !== null,
                    'customerUrl' => $customerId === null ? null : route('customers.show', ['customer' => $customerId]),
                ];
            }, $items),
            'nextPageUrl' => $page->nextPageUrl(),
        ];
    }
}
