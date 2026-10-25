<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class SubscriberStatsQuery
{
    public const WEEKS = 12;

    /**
     * @param  Builder<CatalogSubscription>  $subscriptions
     * @return list<int>
     */
    private function weekly(Builder $subscriptions, CarbonImmutable $now): array
    {
        $weeks = array_fill(0, self::WEEKS, 0);
        $dates = $subscriptions->where('subscribed_at', '>=', $now->subDays(7 * self::WEEKS))->pluck('subscribed_at');

        foreach ($dates as $date) {
            $ago = intdiv((int) CarbonImmutable::parse($date)->diffInDays($now), 7);

            if ($ago < self::WEEKS) {
                $weeks[self::WEEKS - 1 - $ago]++;
            }
        }

        return $weeks;
    }

    /**
     * @return array{total: int, people: int, last7Days: int, byCatalog: list<array{catalogName: string, count: int}>, previous7Days: int, customers: int, weekly: list<int>}
     */
    public function forOrganization(): array
    {
        $catalogs = Catalog::query()->pluck('name', 'id');
        $ids = $catalogs->keys()->all();

        if ($ids === []) {
            return ['total' => 0, 'people' => 0, 'last7Days' => 0, 'byCatalog' => [], 'previous7Days' => 0, 'customers' => 0, 'weekly' => array_fill(0, self::WEEKS, 0)];
        }

        $base = fn () => CatalogSubscription::query()->whereIn('catalog_id', $ids);

        $byCatalog = $base()
            ->selectRaw('catalog_id, COUNT(*) as aggregate')
            ->groupBy('catalog_id')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'catalog_id');

        $now = CarbonImmutable::now();

        return [
            'total' => (int) $byCatalog->sum(),
            'people' => (int) $base()->distinct()->count('account_id'),
            'last7Days' => $base()->where('subscribed_at', '>=', $now->subDays(7))->count(),
            'previous7Days' => $base()->where('subscribed_at', '>=', $now->subDays(14))->where('subscribed_at', '<', $now->subDays(7))->count(),
            'customers' => Customer::query()->whereIn('account_id', $base()->select('account_id'))->distinct()->count('account_id'),
            'weekly' => $this->weekly($base(), $now),
            'byCatalog' => $byCatalog->map(fn ($count, $catalogId): array => [
                'catalogName' => (string) $catalogs->get($catalogId),
                'count' => (int) $count,
            ])->values()->all(),
        ];
    }
}
