<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Telemetry\TelemetryRetention;
use App\Models\Catalog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;

final readonly class PlanChangePreview
{
    public const MAX_NAMED_CATALOGS = 5;

    public const CONSEQUENCE_UNPUBLISH = 'unpublish';

    public const CONSEQUENCE_BLOCK_CREATION = 'block_creation';

    /**
     * @param  list<array{metric: string, used: int, limit: int, excess: int, consequence: string}>  $excesses
     * @param  list<array{name: string, slug: string, products: int}>  $catalogsOverLimit
     * @param  list<PlanFeature>  $lostFeatures
     * @param  list<PlanFeature>  $gainedFeatures
     */
    private function __construct(
        public Plan $target,
        public PlanChangeDirection $direction,
        public ?CarbonImmutable $effectiveAt,
        public array $excesses,
        public array $catalogsOverLimit,
        public int $catalogsOverLimitCount,
        public array $lostFeatures,
        public array $gainedFeatures,
        /**
         * @var array{fromDays: int, toDays: int}|null
         */
        public ?array $retentionLoss = null,
    ) {}

    public static function of(Subscription $subscription, Plan $target): self
    {
        $direction = PlanChangeDirection::between($subscription->plan, $target);

        if ($direction === PlanChangeDirection::Downgrade) {
            return self::forDowngrade($subscription, $target);
        }

        return new self(
            target: $target,
            direction: $direction,
            effectiveAt: $direction === PlanChangeDirection::Upgrade ? CarbonImmutable::now() : null,
            excesses: [],
            catalogsOverLimit: [],
            catalogsOverLimitCount: 0,
            lostFeatures: [],
            gainedFeatures: self::featuresMissingFrom($subscription->plan, $target),
        );
    }

    public static function forDowngrade(Subscription $subscription, Plan $target): self
    {
        $values = UsageCounter::query()->pluck('value', 'metric');
        $excesses = [];

        foreach (UsageMetric::cases() as $metric) {
            $limit = $target->limitFor($metric);
            $used = (int) ($values[$metric->value] ?? 0);

            if ($limit === null || $used <= $limit) {
                continue;
            }

            $excesses[] = [
                'metric' => $metric->value,
                'used' => $used,
                'limit' => $limit,
                'excess' => $used - $limit,
                'consequence' => $metric === UsageMetric::Catalogs ? self::CONSEQUENCE_UNPUBLISH : self::CONSEQUENCE_BLOCK_CREATION,
            ];
        }

        [$catalogs, $count] = self::catalogsAbove($target->maxProductsPerCatalog());

        return new self(
            target: $target,
            direction: PlanChangeDirection::Downgrade,
            effectiveAt: self::effectiveDateOf($subscription),
            excesses: $excesses,
            catalogsOverLimit: $catalogs,
            catalogsOverLimitCount: $count,
            lostFeatures: self::featuresMissingFrom($target, $subscription->plan),
            gainedFeatures: [],
            retentionLoss: self::retentionLoss($subscription->plan, $target),
        );
    }

    /**
     * @return array{fromDays: int, toDays: int}|null
     */
    private static function retentionLoss(Plan $current, Plan $target): ?array
    {
        $from = TelemetryRetention::daysFor($current);
        $to = TelemetryRetention::daysFor($target);

        return $to < $from ? ['fromDays' => $from, 'toDays' => $to] : null;
    }

    public static function effectiveDateOf(Subscription $subscription): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $end = $subscription->current_period_end?->toImmutable();

        if ($subscription->status->isDelinquent()) {
            return $now;
        }

        return $end !== null && $end->greaterThan($now) ? $end : $now;
    }

    public function hasExcess(): bool
    {
        return $this->excesses !== [] || $this->catalogsOverLimitCount > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'effectiveAt' => $this->effectiveAt?->toIso8601String(),
            'unpublishAt' => $this->direction === PlanChangeDirection::Downgrade && $this->effectiveAt !== null
                ? DowngradeOutcome::unpublishDateFor($this->effectiveAt)->toIso8601String()
                : null,
            'excesses' => $this->excesses,
            'productsPerCatalog' => $this->catalogsOverLimitCount === 0 ? null : [
                'limit' => $this->target->maxProductsPerCatalog(),
                'catalogs' => $this->catalogsOverLimit,
                'count' => $this->catalogsOverLimitCount,
            ],
            'lostFeatures' => array_map(fn (PlanFeature $feature): string => $feature->value, $this->lostFeatures),
            'gainedFeatures' => array_map(fn (PlanFeature $feature): string => $feature->value, $this->gainedFeatures),
            'retentionLoss' => $this->retentionLoss,
        ];
    }

    /**
     * @return array{0: list<array{name: string, slug: string, products: int}>, 1: int}
     */
    private static function catalogsAbove(?int $limit): array
    {
        if ($limit === null) {
            return [[], 0];
        }

        $over = Catalog::query()->has('products', '>', $limit);
        $count = (clone $over)->count();

        if ($count === 0) {
            return [[], 0];
        }

        $named = $over->withCount('products')->orderBy('name')->orderBy('id')->limit(self::MAX_NAMED_CATALOGS)->get()
            ->map(fn (Catalog $catalog): array => [
                'name' => $catalog->name,
                'slug' => $catalog->slug,
                'products' => (int) $catalog->getAttribute('products_count'),
            ])
            ->values()
            ->all();

        return [$named, $count];
    }

    /**
     * @return list<PlanFeature>
     */
    private static function featuresMissingFrom(Plan $lacks, Plan $has): array
    {
        return array_values(array_filter(
            PlanFeature::cases(),
            fn (PlanFeature $feature): bool => $has->hasFeature($feature) && ! $lacks->hasFeature($feature),
        ));
    }
}
