<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class UsageCounters
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<int>  $organizationIds
     * @return Collection<string, UsageCounter>
     */
    public function lock(array $organizationIds, ?UsageMetric $metric = null): Collection
    {
        return UsageCounter::query()->withoutTenantScope()
            ->whereIn('organization_id', $organizationIds)
            ->when($metric !== null, fn ($query) => $query->where('metric', $metric))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (UsageCounter $counter): string => self::key($counter->organization_id, $counter->metric));
    }

    public function create(int $organizationId, UsageMetric $metric, int $value, CarbonImmutable $at): void
    {
        $organization = Organization::query()->withTrashed()->findOrFail($organizationId);

        $this->context->runFor($organization, fn () => UsageCounter::query()->create([
            'metric' => $metric,
            'value' => $value,
            'computed_at' => $at,
        ]));
    }

    public function correct(UsageCounter $counter, int $value, CarbonImmutable $at): void
    {
        UsageCounter::query()->withoutTenantScope()
            ->whereKey($counter->getKey())
            ->update(['value' => $value, 'computed_at' => $at]);
    }

    /**
     * @param  list<int>  $counterIds
     */
    public function stamp(array $counterIds, CarbonImmutable $at): void
    {
        if ($counterIds === []) {
            return;
        }

        UsageCounter::query()->withoutTenantScope()
            ->whereIn('id', $counterIds)
            ->update(['computed_at' => $at]);
    }

    public static function key(int $organizationId, UsageMetric $metric): string
    {
        return $organizationId.':'.$metric->value;
    }
}
