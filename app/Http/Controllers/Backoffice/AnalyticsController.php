<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Customer;
use App\Queries\CatalogAnalyticsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController extends Controller
{
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    public function __invoke(Request $request, CatalogAnalyticsQuery $analytics, LimitEnforcer $limits): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Customer::class);

        $days = in_array($request->integer('periodo'), self::PERIODS, true) ? $request->integer('periodo') : self::DEFAULT_PERIOD;
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);
        $full = $limits->hasFeature(PlanFeature::FullAnalytics);
        $catalogs = Catalog::query()->orderBy('name')->get(['id', 'name', 'slug']);
        $chosen = $full ? $catalogs->firstWhere('slug', $request->query('catalogo')) : null;
        $catalogId = $chosen instanceof Catalog ? (int) $chosen->getKey() : null;
        $numbers = $analytics->forOrganization($from, $to, $catalogId);

        return Inertia::render('backoffice/analytics/Index', [
            'period' => $days,
            'periods' => self::PERIODS,
            'full' => $full,
            'catalog' => $chosen instanceof Catalog ? $chosen->slug : null,
            'catalogOptions' => $full ? $catalogs->map(fn (Catalog $catalog): array => ['value' => $catalog->slug, 'label' => $catalog->name])->values()->all() : [],
            'previous' => $full ? $analytics->totals($from->subDays($days), $from, $catalogId) : null,
            'analytics' => $full ? $numbers : [
                'totals' => $numbers['totals'],
                'funnel' => null,
                'catalogs' => null,
                'topProducts' => null,
                'searchesWithoutResults' => null,
                'monthly' => null,
            ],
        ]);
    }
}
