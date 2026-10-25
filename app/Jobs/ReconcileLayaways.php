<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Layaway\ReconcileLayawaysAction;
use App\Domain\Layaway\ReconciliationReport;
use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileLayaways implements ShouldQueue
{
    use Queueable;
    use RunsForEveryOrganization;

    public int $tries = 3;

    public function __construct(
        public readonly bool $fix = false,
        public readonly ?int $organizationId = null,
    ) {
        $this->onQueue('default');
    }

    public const LOCK = 'layaways:reconcile:running';

    private const LOCK_SECONDS = 3600;

    public function handle(ReconcileLayawaysAction $reconcile): ?ReconciliationReport
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('layaways.reconciliation_skipped', ['reason' => 'otra pasada en curso']);

            return null;
        }

        try {
            return $this->run($reconcile);
        } finally {
            $lock->release();
        }
    }

    private function run(ReconcileLayawaysAction $reconcile): ReconciliationReport
    {
        $now = CarbonImmutable::now();
        $total = new ReconciliationReport;

        $this->forEachOrganization(function () use ($reconcile, $now, $total): void {
            $total->merge($reconcile->handle($this->fix, $now));
        });

        if (! $this->fix && $this->organizationId === null) {
            $total->remember($now);
        }

        $total->discrepancies() > 0
            ? Log::warning('layaways.reconciliation', $total->toArray())
            : Log::info('layaways.reconciliation', $total->toArray());

        return $total;
    }

    /**
     * @return iterable<Organization>
     */
    protected function organizationsToVisit(): iterable
    {
        return Organization::query()
            ->when($this->organizationId !== null, fn ($query) => $query->whereKey($this->organizationId))
            ->orderBy('id')
            ->cursor();
    }
}
