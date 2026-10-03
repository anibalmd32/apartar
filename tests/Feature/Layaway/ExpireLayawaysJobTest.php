<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Domain\Payment\LayawayReceipts;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ExpireLayawaysJob;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * `ExpireLayawaysJob`: el recorrido de todas las organizaciones y su
 * programación (`REQ-LAY-006` RB-6, `REQ-INF-003`).
 *
 * El trabajo de cada organización ya está probado en
 * `ExpireLayawaysActionTest`; aquí se afirma lo que es del job: que pasa por
 * **todas**, con el tenant de cada una y limpio al terminar, en la cola que
 * le toca y cada hora.
 */
final class ExpireLayawaysJobTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-6 y SP-7: dos negocios, los dos con un apartado vencido; el job los
     * expira a ambos, cada uno en su tenant, y no deja ninguno fijado.
     */
    public function test_it_expires_across_every_organization_and_leaves_the_context_clean(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $first = Organization::factory()->create();
        $second = $this->neighbourOrganization();
        $ours = $this->pendingUntil($first, '2026-09-13 10:00:00');
        $theirs = $this->pendingUntil($second, '2026-09-13 10:00:00');
        $open = $this->pendingUntil($second, '2026-09-20 10:00:00');
        $this->withoutOrganization();

        CarbonImmutable::setTestNow('2026-09-13 11:00:00');
        app()->call([new ExpireLayawaysJob, 'handle']);

        $this->assertSame('expired', $this->statusOf($ours));
        $this->assertSame('expired', $this->statusOf($theirs));
        $this->assertSame('pending_down_payment', $this->statusOf($open));
        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    /**
     * Lo retenido por un comprobante se registra por organización: es la señal
     * de que un negocio tiene comprobantes sin revisar con la ventana vencida.
     */
    public function test_it_logs_the_layaways_held_by_a_receipt(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $organization = Organization::factory()->create();
        $this->pendingUntil($organization, '2026-09-13 10:00:00');
        $this->withoutOrganization();

        $this->app->instance(LayawayReceipts::class, new class extends LayawayReceipts
        {
            public function holdExpiration(Layaway $layaway): bool
            {
                return true;
            }
        });
        $logger = Log::spy();

        CarbonImmutable::setTestNow('2026-09-13 11:00:00');
        app()->call([new ExpireLayawaysJob, 'handle']);

        $logger->shouldHaveReceived('info')->with('layaways.expiration_held', Mockery::on(
            fn (array $context): bool => $context === ['organization_id' => (int) $organization->getKey(), 'expired' => 0, 'held' => 1],
        ));
        $logger->shouldHaveReceived('info')->with('layaways.expired', ['expired' => 0, 'held' => 1]);
    }

    /**
     * `REQ-INF-003` RB-1, RB-4 y SP-4: cola `default`, tres intentos y sin
     * solaparse consigo mismo.
     */
    public function test_it_runs_on_the_default_queue_three_times_without_overlapping(): void
    {
        $job = new ExpireLayawaysJob;

        $this->assertSame('default', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertCount(1, array_filter($job->middleware(), fn (object $middleware): bool => $middleware instanceof WithoutOverlapping));
    }

    /**
     * `REQ-INF-003` RB-5: `layaways:expire`, cada hora y sin solaparse.
     */
    public function test_it_is_scheduled_every_hour(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $event): bool => $event->description === 'layaways:expire');

        $this->assertInstanceOf(ScheduledEvent::class, $event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function pendingUntil(Organization $organization, string $expiresAt): Layaway
    {
        $layaway = $this->layawayIn($organization);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => $expiresAt]);

        return $layaway;
    }

    private function statusOf(Layaway $layaway): string
    {
        return (string) DB::table('layaways')->where('id', $layaway->getKey())->value('status');
    }
}
