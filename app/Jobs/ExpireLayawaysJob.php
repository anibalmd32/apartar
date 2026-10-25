<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Layaway\ExpireLayawaysAction;
use App\Domain\Layaway\ExpirationOutcome;
use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ExpireLayawaysJob implements ShouldQueue
{
    use Queueable;
    use RunsForEveryOrganization;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('layaways-expire'))->dontRelease()->expireAfter(1800)];
    }

    public function handle(ExpireLayawaysAction $expire): void
    {
        $now = CarbonImmutable::now();
        $total = new ExpirationOutcome;

        $this->forEachOrganization(function (Organization $organization) use ($expire, $now, &$total): void {
            $outcome = $expire->handle($now);

            if ($outcome->held > 0) {
                Log::info('layaways.expiration_held', ['organization_id' => (int) $organization->getKey()] + $outcome->toArray());
            }

            $total = $total->plus($outcome);
        });

        Log::info('layaways.expired', $total->toArray());
    }
}
