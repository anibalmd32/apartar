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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * `DetectOverdueLayawaysJob`: las 06:00 de cada organización
 * (`REQ-LAY-007` RB-5, `REQ-INF-003` RB-5 ⏰).
 *
 * El recálculo está probado en `DetectOverdueActionTest`; aquí se afirma lo
 * que es del job: que cada hora visita **solo** a quien le toca, con su
 * tenant y limpio al terminar, en su cola y programado cada hora.
 */
final class DetectOverdueLayawaysJobTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * `REQ-INF-003` RB-5b: a las 06:00 de Caracas son las 19:00 en Tokio. La
     * pasada de esa hora evalúa al negocio de Caracas y no al de Tokio, aunque
     * los dos tengan una cuota pasada de la gracia.
     */
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

        // Las 06:00 de Tokio son las 17:00 del día anterior en Caracas.
        CarbonImmutable::setTestNow('2026-10-05 17:00:00');
        app()->call([new DetectOverdueLayawaysJob, 'handle']);

        $this->assertSame('overdue', $this->statusOf($inTokyo));
    }

    /**
     * `REQ-INF-003` RB-1 y RB-4: cola `default`, tres intentos, sin solaparse.
     */
    public function test_it_runs_on_the_default_queue_three_times_without_overlapping(): void
    {
        $job = new DetectOverdueLayawaysJob;

        $this->assertSame('default', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertCount(1, array_filter($job->middleware(), fn (object $middleware): bool => $middleware instanceof WithoutOverlapping));
    }

    /**
     * ⏰: se despacha cada hora y es el job quien filtra por hora local.
     */
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
