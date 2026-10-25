<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\TelemetryEvent;
use App\Models\TelemetryMonthlyAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class CatalogAnalyticsQuery
{
    public const FUNNEL = [
        TelemetryEventName::CatalogViewed,
        TelemetryEventName::ProductViewed,
        TelemetryEventName::LayawayFormStarted,
        TelemetryEventName::LayawayCreated,
    ];

    public const TOP = 10;

    public const MONTHS = 12;

    private ?int $catalogId = null;

    /**
     * @return array{totals: array{visits: int, visitors: int, layaways: int}, funnel: list<array{event: string, count: int}>, catalogs: list<array{name: string, slug: string, visits: int, layaways: int}>, topProducts: list<array{name: string, views: int}>, searchesWithoutResults: list<array{term: string, count: int}>, monthly: list<array{month: string, visits: int, subscriptions: int}>}
     */
    public function forOrganization(CarbonImmutable $from, CarbonImmutable $to, ?int $catalogId = null): array
    {
        $this->catalogId = $catalogId;

        $counts = $this->between($from, $to)
            ->whereIn('event_name', array_map(fn (TelemetryEventName $name): string => $name->value, self::FUNNEL))
            ->selectRaw('event_name, COUNT(*) AS aggregate')
            ->groupBy('event_name')
            ->toBase()
            ->pluck('aggregate', 'event_name');

        $count = fn (TelemetryEventName $name): int => (int) ($counts[$name->value] ?? 0);

        return [
            'totals' => [
                'visits' => $count(TelemetryEventName::CatalogViewed),
                'visitors' => $this->visitors($from, $to),
                'layaways' => $count(TelemetryEventName::LayawayCreated),
            ],
            'funnel' => array_map(fn (TelemetryEventName $name): array => ['event' => $name->value, 'count' => $count($name)], self::FUNNEL),
            'catalogs' => $this->catalogs($from, $to),
            'topProducts' => $this->topProducts($from, $to),
            'searchesWithoutResults' => $this->searchesWithoutResults($from, $to),
            'monthly' => $this->monthly($to),
        ];
    }

    /**
     * @return array{visits: int, visitors: int, layaways: int}
     */
    public function totals(CarbonImmutable $from, CarbonImmutable $to, ?int $catalogId = null): array
    {
        $this->catalogId = $catalogId;

        $counts = $this->between($from, $to)
            ->whereIn('event_name', [TelemetryEventName::CatalogViewed->value, TelemetryEventName::LayawayCreated->value])
            ->selectRaw('event_name, COUNT(*) AS aggregate')
            ->groupBy('event_name')
            ->toBase()
            ->pluck('aggregate', 'event_name');

        return [
            'visits' => (int) ($counts[TelemetryEventName::CatalogViewed->value] ?? 0),
            'visitors' => $this->visitors($from, $to),
            'layaways' => (int) ($counts[TelemetryEventName::LayawayCreated->value] ?? 0),
        ];
    }

    private function visitors(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $this->between($from, $to)->where('visitor_id', '!=', ServerTelemetry::NO_VISITOR)->toBase()->distinct()->count('visitor_id');
    }

    /**
     * @return Builder<TelemetryEvent>
     */
    private function between(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TelemetryEvent::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($this->catalogId !== null, fn (Builder $query) => $query->where('catalog_id', $this->catalogId));
    }

    /**
     * @return list<array{name: string, slug: string, visits: int, layaways: int}>
     */
    private function catalogs(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->between($from, $to)
            ->whereNotNull('catalog_id')
            ->whereIn('event_name', [TelemetryEventName::CatalogViewed->value, TelemetryEventName::LayawayCreated->value])
            ->selectRaw('catalog_id, event_name, COUNT(*) AS aggregate')
            ->groupBy('catalog_id', 'event_name')
            ->toBase()
            ->get();

        $catalogs = Catalog::query()->whereIn('id', $rows->pluck('catalog_id')->unique()->all())->get(['id', 'name', 'slug'])->keyBy('id');

        return $catalogs->map(fn (Catalog $catalog): array => [
            'name' => $catalog->name,
            'slug' => $catalog->slug,
            'visits' => (int) $rows->first(fn (object $row): bool => (int) $row->catalog_id === $catalog->id && $row->event_name === TelemetryEventName::CatalogViewed->value)?->aggregate,
            'layaways' => (int) $rows->first(fn (object $row): bool => (int) $row->catalog_id === $catalog->id && $row->event_name === TelemetryEventName::LayawayCreated->value)?->aggregate,
        ])->sortByDesc('visits')->values()->all();
    }

    /**
     * @return list<array{name: string, views: int}>
     */
    private function topProducts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $views = $this->between($from, $to)
            ->where('event_name', TelemetryEventName::ProductViewed->value)
            ->where('subject_type', 'product')
            ->whereNotNull('subject_id')
            ->selectRaw('subject_id, COUNT(*) AS aggregate')
            ->groupBy('subject_id')
            ->orderByDesc('aggregate')
            ->limit(self::TOP)
            ->toBase()
            ->pluck('aggregate', 'subject_id');

        $names = Product::query()->withTrashed()->whereIn('id', $views->keys()->all())->pluck('name', 'id');

        return $views->map(fn ($count, $id): array => ['name' => (string) ($names[$id] ?? 'Producto eliminado'), 'views' => (int) $count])->values()->all();
    }

    /**
     * @return list<array{term: string, count: int}>
     */
    private function searchesWithoutResults(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->between($from, $to)
            ->where('event_name', TelemetryEventName::SearchPerformed->value)
            ->where('payload->results', 0)
            ->selectRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.term'))) AS term, COUNT(*) AS aggregate")
            ->groupBy('term')
            ->orderByDesc('aggregate')
            ->limit(self::TOP)
            ->toBase()
            ->get()
            ->filter(fn (object $row): bool => is_string($row->term) && $row->term !== '')
            ->map(fn (object $row): array => ['term' => (string) $row->term, 'count' => (int) $row->aggregate])
            ->values()
            ->all();
    }

    /**
     * @return list<array{month: string, visits: int, subscriptions: int}>
     */
    private function monthly(CarbonImmutable $to): array
    {
        $first = $to->startOfMonth()->subMonths(self::MONTHS - 1);
        $events = [TelemetryEventName::CatalogViewed->value, TelemetryEventName::CatalogSubscribed->value, TelemetryEventName::CatalogUnsubscribed->value];

        $series = [];

        $detail = TelemetryEvent::query()
            ->where('occurred_at', '>=', $first)
            ->whereIn('event_name', $events)
            ->selectRaw("event_name, DATE_FORMAT(occurred_at, '%Y-%m') AS ym, COUNT(*) AS aggregate")
            ->groupBy('event_name', 'ym')
            ->toBase()
            ->get();

        $kept = TelemetryMonthlyAggregate::query()
            ->where('year_month', '>=', $first->format('Y-m'))
            ->whereIn('event_name', $events)
            ->toBase()
            ->get(['event_name', 'year_month AS ym', 'count AS aggregate']);

        foreach ($detail->concat($kept) as $row) {
            $series[$row->ym][$row->event_name] = ($series[$row->ym][$row->event_name] ?? 0) + (int) $row->aggregate;
        }

        $months = [];

        for ($month = $first; $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = [
                'month' => $key,
                'visits' => $series[$key][TelemetryEventName::CatalogViewed->value] ?? 0,
                'subscriptions' => ($series[$key][TelemetryEventName::CatalogSubscribed->value] ?? 0) - ($series[$key][TelemetryEventName::CatalogUnsubscribed->value] ?? 0),
            ];
        }

        return $months;
    }
}
