<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageDrift;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RecalculateUsage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const LOCK_SECONDS = 3600;

    public function __construct(
        public readonly ?int $organizationId = null,
        public readonly ?UsageMetric $metric = null,
    ) {
        $this->onQueue('telemetry');
    }

    public static function lockFor(?UsageMetric $metric, ?int $organizationId = null): string
    {
        return 'subscriptions:recalculate-usage:'.($metric->value ?? 'all').':'.($organizationId ?? 'all').':running';
    }

    /**
     * @return array{organizations: int, drifts: list<UsageDrift>}|null
     */
    public function handle(UsageCalculator $usage): ?array
    {
        $lock = Cache::lock(self::lockFor($this->metric, $this->organizationId), self::LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('usage.recalculation_skipped', ['reason' => 'otra pasada en curso', 'metric' => $this->metric?->value]);

            return null;
        }

        try {
            return $this->run($usage);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{organizations: int, drifts: list<UsageDrift>}
     */
    private function run(UsageCalculator $usage): array
    {
        $organizations = 0;
        $drifts = [];

        Organization::query()
            ->withTrashed()
            ->select('id')
            ->when($this->organizationId !== null, fn ($query) => $query->whereKey($this->organizationId))
            ->chunkById(UsageCalculator::BATCH, function (Collection $batch) use ($usage, &$organizations, &$drifts): void {
                $ids = $batch->map(fn (Organization $organization): int => (int) $organization->getKey())->values()->all();

                $organizations += count($ids);
                array_push($drifts, ...$usage->recalculateMany($ids, $this->metric));
            });

        Log::info('usage.recalculated', [
            'metric' => $this->metric->value ?? 'all',
            'organizations' => $organizations,
            'drifts' => count($drifts),
        ]);

        return ['organizations' => $organizations, 'drifts' => $drifts];
    }
}
