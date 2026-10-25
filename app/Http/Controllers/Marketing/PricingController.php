<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

final class PricingController extends Controller
{
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

        return Inertia::render('marketing/Pricing', ['plans' => $plans]);
    }

    /**
     * @return array{code: string, name: string, priceLabel: string, isFree: bool, limits: list<array{key: string, label: string, limit: int|null}>, features: list<string>}
     */
    private function planView(Plan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'priceLabel' => $this->priceLabel($plan),
            'isFree' => $plan->price_cents === 0,
            'limits' => array_map(fn (string $key): array => $this->limit($plan, $key), self::LIMITS),
            'features' => array_map(
                fn (PlanFeature $feature): string => $feature->label(),
                $plan->enabledFeatures(),
            ),
        ];
    }

    private function priceLabel(Plan $plan): string
    {
        if ($plan->price_cents === 0) {
            return 'Gratis';
        }

        $amount = MoneyFormatter::format(new Money($plan->price_cents));

        return match ($plan->interval) {
            'monthly' => $amount.' al mes',
            'yearly' => $amount.' al año',
            default => $amount,
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

        return [
            'key' => $key,
            'label' => $metric->label($limit ?? 2),
            'limit' => $limit,
        ];
    }
}
