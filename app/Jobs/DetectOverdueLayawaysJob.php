<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Layaway\OverdueSummary;
use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class DetectOverdueLayawaysJob implements ShouldQueue
{
    use Queueable;
    use RunsForEveryOrganization;

    public const LOCAL_HOUR = 6;

    public int $tries = 3;

    private ?CarbonImmutable $now = null;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('layaways-detect-overdue'))->dontRelease()->expireAfter(1800)];
    }

    public function handle(DetectOverdueAction $detect): void
    {
        $this->now = CarbonImmutable::now();
        $now = $this->now;
        $total = new OverdueSummary;

        $this->forEachOrganization(function (Organization $organization) use ($detect, $now, &$total): void {
            $total = $total->plus($detect->handle($now));
        });

        Log::info('layaways.overdue_detected', $total->toArray());
    }

    /**
     * @return iterable<int, Organization>
     */
    protected function organizationsToVisit(): iterable
    {
        $now = $this->now ?? CarbonImmutable::now();

        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
            if ($now->setTimezone($organization->timezone)->hour === self::LOCAL_HOUR) {
                yield $organization;
            }
        }
    }
}
