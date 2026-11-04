<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

final class PricingController extends Controller
{
    private const MB_PER_GB = 1024;

    /**
     * @var list<string>
     */
    private const LIMITS = [
        'users',
        'catalogs',
        'products',
        'products_per_catalog',
        'active_layaways',
        'storage_mb',
        'payment_accounts',
    ];

    public function __invoke(): Response
    {
        $plans = Plan::query()
            ->public()
            ->get()
            ->map(fn (Plan $plan): array => $this->planView($plan))
            ->values()
            ->all();

        return Inertia::render('marketing/Pricing', [
            'plans' => $plans,
            'rate' => $this->latestBcvRate(),
        ]);
    }

    /**
     * @return array{code: string, name: string, price: string, priceCents: int, period: string, isFree: bool, limits: list<array{key: string, label: string, limit: int|null}>, features: list<string>}
     */
    private function planView(Plan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'price' => $this->price($plan),
            'priceCents' => $plan->price_cents,
            'period' => $this->period($plan),
            'isFree' => $plan->price_cents === 0,
            'limits' => array_map(fn (string $key): array => $this->limit($plan, $key), self::LIMITS),
            'features' => array_map(
                fn (PlanFeature $feature): string => $feature->label(),
                $plan->enabledFeatures(),
            ),
        ];
    }

    /**
     * @return array{date: string, rate: string}|null
     */
    private function latestBcvRate(): ?array
    {
        $rate = ExchangeRate::query()
            ->where('source', RateSource::Bcv->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first(['rate', 'published_at']);

        return $rate === null ? null : ['date' => $rate->published_at->format('Y-m-d'), 'rate' => $rate->rate];
    }

    private function price(Plan $plan): string
    {
        $amount = MoneyFormatter::format(new Money($plan->price_cents));

        return $plan->price_cents % 100 === 0 ? (string) preg_replace('/,00$/', '', $amount) : $amount;
    }

    private function period(Plan $plan): string
    {
        if ($plan->price_cents === 0) {
            return 'para siempre';
        }

        return match ($plan->interval) {
            'monthly' => 'al mes',
            'yearly' => 'al año',
            default => '',
        };
    }

    /**
     * @return array{key: string, label: string, limit: int|null}
     */
    private function limit(Plan $plan, string $key): array
    {
        if ($key === 'products_per_catalog') {
            $limit = $plan->maxProductsPerCatalog();

            return [
                'key' => $key,
                'label' => $limit === 1 ? 'producto por catálogo' : 'productos por catálogo',
                'limit' => $limit,
            ];
        }

        $metric = UsageMetric::from($key);
        $limit = $plan->limitFor($metric);

        if ($metric === UsageMetric::StorageMb && $limit !== null && $limit >= self::MB_PER_GB && $limit % self::MB_PER_GB === 0) {
            return ['key' => $key, 'label' => 'GB de almacenamiento', 'limit' => intdiv($limit, self::MB_PER_GB)];
        }

        return [
            'key' => $key,
            'label' => $metric->label($limit ?? 2),
            'limit' => $limit,
        ];
    }
}
