<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Queries\Platform\UsageAggregates;
use App\Queries\Platform\UsageCounters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

final class UsageCalculator
{
    public const BATCH = 200;

    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageAggregates $aggregates,
        private readonly UsageCounters $counters,
    ) {}

    public function recalculate(Organization $organization, ?UsageMetric $metric = null): void
    {
        $this->recalculateMany([(int) $organization->getKey()], $metric);
    }

    public function refresh(Organization $organization, UsageMetric $metric): void
    {
        $this->recalculateMany([(int) $organization->getKey()], $metric, logsDrift: false);
    }

    public function measure(UsageMetric $metric): int
    {
        $organization = $this->context->organizationId();

        return $this->aggregates->measure($metric, [$organization])[$organization];
    }

    /**
     * @throws LogicException
     */
    public function recountLocked(UsageMetric $metric): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(sprintf(
                'recountLocked(%s) se llamó fuera de una transacción: el contador se recuenta bloqueado dentro '
                .'de la transacción que lo va a mover, o la cifra puede cambiar antes de usarla.',
                $metric->value,
            ));
        }

        $organization = $this->context->organizationId();
        $counter = $this->counters->lock([$organization], $metric)->get(UsageCounters::key($organization, $metric));
        $now = CarbonImmutable::now();
        $measured = max(0, $this->aggregates->measure($metric, [$organization], $now, locking: true)[$organization]);

        if ($counter === null) {
            $this->counters->create($organization, $metric, $measured, $now);
        } elseif ($counter->value !== $measured) {
            $this->counters->correct($counter, $measured, $now);
        }

        return $measured;
    }

    public function megabytesAdded(int $bytes): int
    {
        $organization = $this->context->organizationId();
        $current = $this->aggregates->bytesPer([$organization], locking: true)[$organization];

        return UsageAggregates::megabytes($current + $bytes) - UsageAggregates::megabytes($current);
    }

    /**
     * @param  list<int>  $organizationIds
     * @return list<UsageDrift>
     */
    public function recalculateMany(array $organizationIds, ?UsageMetric $metric = null, bool $logsDrift = true): array
    {
        $metrics = $metric === null ? UsageMetric::cases() : [$metric];

        $drifts = DB::transaction(function () use ($organizationIds, $metrics, $metric, $logsDrift): array {
            $stored = $this->counters->lock($organizationIds, $metric);
            $now = CarbonImmutable::now();

            $drifts = [];
            $untouched = [];

            foreach ($metrics as $each) {
                foreach ($this->aggregates->measure($each, $organizationIds, $now) as $organization => $measured) {
                    $measured = max(0, $measured);
                    $counter = $stored->get(UsageCounters::key($organization, $each));

                    if ($counter === null) {
                        $this->counters->create($organization, $each, $measured, $now);

                        continue;
                    }

                    if ($counter->value === $measured) {
                        $untouched[] = (int) $counter->getKey();

                        continue;
                    }

                    $this->counters->correct($counter, $measured, $now);

                    $drift = new UsageDrift($organization, $each, $counter->value, $measured);

                    if ($logsDrift && $drift->isUnexpected()) {
                        $drifts[] = $drift;
                    }
                }
            }

            $this->counters->stamp($untouched, $now);

            return $drifts;
        }, attempts: 3);

        foreach ($drifts as $drift) {
            Log::warning('usage_counter.drift', $drift->toArray());
        }

        return $drifts;
    }
}
