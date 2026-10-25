<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\DetectOverdueLayawaysJob;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DetectOverdueLayawaysJobTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_each_hour_it_visits_only_the_organizations_at_their_local_six(): void
    {
        $caracas = Organization::factory()->create(['timezone' => 'America/Caracas']);
        $tokyo = $this->neighbourOrganization(['timezone' => 'Asia/Tokyo']);
        $inCaracas = $this->activeLayaway($caracas);
        $inTokyo = $this->activeLayaway($tokyo);
        $this->withoutOrganization();

        CarbonImmutable::setTestNow('2026-10-05 06:00:00');
        app()->call([new DetectOverdueLayawaysJob, 'handle']);

        $this->assertSame('overdue', $this->statusOf($inCaracas));
        $this->assertSame('active', $this->statusOf($inTokyo));
        $this->assertFalse(app(TenantContext::class)->hasOrganization());

        CarbonImmutable::setTestNow('2026-10-05 17:00:00');
        app()->call([new DetectOverdueLayawaysJob, 'handle']);

        $this->assertSame('overdue', $this->statusOf($inTokyo));
    }

    public function test_it_runs_on_the_default_queue_three_times_without_overlapping(): void
    {
        $job = new DetectOverdueLayawaysJob;

        $this->assertSame('default', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertCount(1, array_filter($job->middleware(), fn (object $middleware): bool => $middleware instanceof WithoutOverlapping));
    }

    public function test_it_is_scheduled_every_hour(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $event): bool => $event->description === 'layaways:detect-overdue');

        $this->assertInstanceOf(ScheduledEvent::class, $event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function activeLayaway(Organization $organization): Layaway
    {
        $layaway = $this->layawayIn($organization);

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        CarbonImmutable::setTestNow();

        return $layaway;
    }

    private function statusOf(Layaway $layaway): string
    {
        return (string) DB::table('layaways')->where('id', $layaway->getKey())->value('status');
    }
}
