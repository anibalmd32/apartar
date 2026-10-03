<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\MarkDeliveredAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayNotDeliverableException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\AuditEntry;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El negocio marca la entrega de un apartado pagado (`REQ-LAY-010`).
 */
final class MarkDeliveredActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->actor = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-1 y RB-3: un `completed` se marca, sigue `completed`, el cupo no se
     * mueve, no hay transición y queda auditado.
     */
    public function test_a_completed_layaway_is_marked_without_a_transition(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $counter = $this->counter();
        Event::fake([LayawayTransitioned::class]);
        CarbonImmutable::setTestNow('2026-10-02 15:30:00');

        $this->deliver($layaway);

        $row = DB::table('layaways')->where('id', $layaway->getKey())->sole();
        $this->assertSame('completed', $row->status);
        $this->assertSame('2026-10-02 15:30:00', $row->delivered_at);
        $this->assertSame($counter, $this->counter());
        Event::assertNotDispatched(LayawayTransitioned::class);
        $this->assertSame(1, $this->auditCount());
    }

    /**
     * SP-1 y SP-3: ni un vivo ni un terminal que no sea `completed`.
     */
    public function test_only_completed_can_be_delivered(): void
    {
        foreach ([LayawayStatus::PendingDownPayment, LayawayStatus::Active, LayawayStatus::Overdue, LayawayStatus::Cancelled, LayawayStatus::Expired] as $status) {
            $layaway = $this->layawayIn($this->organization, $status);

            try {
                $this->deliver($layaway);
                $this->fail("Se entregó un apartado {$status->value}.");
            } catch (LayawayNotDeliverableException $exception) {
                $this->assertSame($status, $exception->status);
            }

            $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('delivered_at'));
        }

        $this->assertSame(0, $this->auditCount());
    }

    /**
     * RB-2 y SP-2: la segunda pulsación no cambia la fecha ni audita otra vez,
     * y tampoco falla.
     */
    public function test_it_is_idempotent(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Completed);

        CarbonImmutable::setTestNow('2026-10-02 15:30:00');
        $this->deliver($layaway);
        CarbonImmutable::setTestNow('2026-10-03 09:00:00');
        $this->deliver($layaway);

        $this->assertSame('2026-10-02 15:30:00', DB::table('layaways')->where('id', $layaway->getKey())->value('delivered_at'));
        $this->assertSame(1, $this->auditCount());
    }

    /**
     * RB-5: la cola «completados sin entregar» devuelve los pagados que aún no
     * salieron de la tienda, y nada más.
     */
    public function test_the_completed_not_delivered_queue(): void
    {
        $waiting = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $delivered = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->deliver($delivered);

        $queue = $this->withinOrganization($this->organization, fn (): array => Layaway::query()->completedNotDelivered()->pluck('id')->all());

        $this->assertSame([(int) $waiting->getKey()], array_map('intval', $queue));
    }

    private function deliver(Layaway $layaway): void
    {
        $this->withinOrganization($this->organization, fn () => app(MarkDeliveredAction::class)->handle($layaway, $this->actor));
    }

    private function auditCount(): int
    {
        return $this->withinOrganization($this->organization, fn (): int => AuditEntry::query()->where('event', AuditEvent::LayawayDelivered->value)->count());
    }

    private function counter(): int
    {
        return (int) $this->withinOrganization(
            $this->organization,
            fn () => UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'),
        );
    }
}
