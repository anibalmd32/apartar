<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Subscription\ApplyDowngradeAction;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Queries\Platform\DowngradeCandidates;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyScheduledDowngrades implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @return array{applied: int, unpublished: int}
     */
    public function handle(DowngradeCandidates $candidates, TenantContext $context, ApplyDowngradeAction $downgrade): array
    {
        $tally = ['applied' => 0, 'unpublished' => 0];

        foreach ($candidates->organizationIds(CarbonImmutable::now()) as $organizationId) {
            $organization = Organization::query()->find($organizationId);

            if (! $organization instanceof Organization) {
                continue;
            }

            try {
                $outcome = $context->runFor($organization, fn () => $downgrade->handle(Subscription::query()->firstOrFail()));

                $tally['applied'] += (int) $outcome->applied();
                $tally['unpublished'] += count($outcome->unpublished);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Log::info('subscriptions.downgrades_applied', $tally);

        return $tally;
    }
}
