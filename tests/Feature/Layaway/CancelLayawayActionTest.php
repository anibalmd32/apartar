<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\CancelLayawayAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Events\Layaway\LayawayCancelled;
use App\Models\AuditEntry;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\Concerns\AssertsStockLockOrder;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El negocio cancela un apartado (`REQ-LAY-009`).
 */
final class CancelLayawayActionTest extends TestCase
{
    use AssertsStockLockOrder, CreatesLayaways, RefreshDatabase;

    private const REASON = 'El cliente dejó de responder hace un mes.';

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
     * RB-3, RB-4: desde los tres estados vivos cancela, devuelve la unidad y el
     * cupo, marca `cancelled_at`, guarda el motivo y emite `LayawayCancelled`.
     */
    public function test_it_cancels_from_the_three_live_states_and_gives_stock_and_quota_back(): void
    {
        Event::fake([LayawayCancelled::class]);

        foreach ([LayawayStatus::PendingDownPayment, LayawayStatus::Active, LayawayStatus::Overdue] as $status) {
            $product = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create(['stock' => 3]));
            $layaway = $this->layawayIn($this->organization, $status, $product);
            $counter = $this->counter();

            $this->cancel($layaway);

            $row = DB::table('layaways')->where('id', $layaway->getKey())->sole();
            $this->assertSame('cancelled', $row->status, $status->value);
            $this->assertNotNull($row->cancelled_at);
            $this->assertSame(self::REASON, $row->cancellation_reason);
            $this->assertSame(3, (int) DB::table('products')->where('id', $product->getKey())->value('stock'), $status->value);
            $this->assertSame($counter - 1, $this->counter(), $status->value);
        }

        Event::assertDispatchedTimes(LayawayCancelled::class, 3);
    }

    /**
     * SP-1: un terminal no se cancela, y no se toca nada —ni sus comprobantes—.
     */
    public function test_it_fails_on_terminal_layaways(): void
    {
        $this->fakeReceipts();

        foreach ([LayawayStatus::Completed, LayawayStatus::Cancelled, LayawayStatus::Expired] as $status) {
            $layaway = $this->layawayIn($this->organization, $status);

            try {
                $this->cancel($layaway);
                $this->fail("Se canceló un apartado {$status->value}.");
            } catch (IllegalLayawayTransitionException $exception) {
                $this->assertSame($status, $exception->from);
            }

            $this->assertSame($status->value, DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        }

        $this->assertSame([], $this->rejectedFor);
        $this->assertSame(0, $this->auditCount());
    }

    /**
     * RB-2, SP-2: sin un motivo de 10 caracteres no se cancela.
     */
    public function test_the_reason_is_required(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->cancel($layaway, 'Moroso.');
        } finally {
            $this->assertSame('active', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        }
    }

    /**
     * SP-3: los comprobantes en revisión se rechazan en la misma operación, con
     * el apartado ya bloqueado y **antes** de transicionar.
     */
    public function test_it_rejects_the_pending_receipts(): void
    {
        $this->fakeReceipts();
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        $this->cancel($layaway);

        $this->assertSame([[(int) $layaway->getKey(), 'active']], $this->rejectedFor);
        $entry = $this->auditEntry();
        $this->assertSame(2, $entry->properties['changed']['receipts_rejected']['after'] ?? null);
    }

    /**
     * RB-5: lo pagado se queda como estaba, para constancia, y el apartado
     * conserva sus cuotas.
     */
    public function test_paid_cents_and_the_schedule_are_preserved(): void
    {
        $layaway = $this->layawayIn($this->organization);
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        CarbonImmutable::setTestNow();
        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 11_000]);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);

        $this->cancel($layaway);

        $this->assertSame(11_000, (int) DB::table('layaways')->where('id', $layaway->getKey())->value('paid_cents'));
        $this->assertSame(5, DB::table('installments')->where('layaway_id', $layaway->getKey())->count());
        $this->assertSame('paid', DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->value('status'));
    }

    /**
     * RB-2: la auditoría lleva quién, el estado de antes y el motivo.
     */
    public function test_it_is_audited_with_the_reason(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Overdue);

        $this->cancel($layaway);

        $entry = $this->auditEntry();
        $this->assertSame((int) $this->actor->getKey(), (int) $entry->causer_id);
        $this->assertSame((int) $layaway->getKey(), (int) $entry->subject_id);
        $this->assertSame('overdue', $entry->properties['changed']['status']['before'] ?? null);
        $this->assertSame(self::REASON, $entry->properties['changed']['cancellation_reason']['after'] ?? null);
    }

    /**
     * RB-9: se puede cancelar con la organización suspendida.
     */
    public function test_it_works_with_the_organization_suspended(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->cancel($layaway);

        $this->assertSame('cancelled', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
    }

    /**
     * RB-1: solo alguien del negocio. Un usuario de otra organización no puede,
     * aunque la llamada no venga de una ruta.
     */
    public function test_only_a_member_of_the_business_can_cancel(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $stranger = $this->withinOrganization(Organization::factory()->create(), fn (): User => User::factory()->create());

        $this->expectException(AuthorizationException::class);

        $this->withinOrganization($this->organization, fn () => app(CancelLayawayAction::class)->handle($layaway, $stranger, self::REASON));
    }

    /**
     * `REQ-LAY-003` RB-8: el producto antes que el apartado. La acción coge el
     * apartado antes de llamar a la máquina, así que el orden es suyo.
     */
    public function test_it_locks_the_product_before_the_layaway(): void
    {
        $this->fakeReceipts();
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        $this->assertLocksTheProductFirst(fn () => $this->cancel($layaway), 'CancelLayawayAction');
    }

    /** @var list<array{int, string}> */
    private array $rejectedFor = [];

    /**
     * Los comprobantes no existen hasta `T-054`: el colaborador se sustituye y
     * apunta con qué apartado, y en qué estado, se le llamó.
     */
    private function fakeReceipts(): void
    {
        $this->app->instance(LayawayReceipts::class, new class(function (int $id, string $status): void {
            $this->rejectedFor[] = [$id, $status];
        }) extends LayawayReceipts
        {

            /** @param Closure(int, string): void $record */
            public function __construct(private readonly Closure $record) {}

            public function rejectPendingOnCancellation(Layaway $layaway): int
            {
                $status = DB::table('layaways')->where('id', $layaway->getKey())->value('status');
                ($this->record)((int) $layaway->getKey(), is_string($status) ? $status : '');

                return 2;
            }
        });
    }

    private function cancel(Layaway $layaway, string $reason = self::REASON): void
    {
        $this->withinOrganization($this->organization, fn () => app(CancelLayawayAction::class)->handle($layaway, $this->actor, $reason));
    }

    private function auditEntry(): AuditEntry
    {
        return $this->withinOrganization($this->organization, fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::LayawayCancelled->value)->sole());
    }

    private function auditCount(): int
    {
        return $this->withinOrganization($this->organization, fn (): int => AuditEntry::query()->where('event', AuditEvent::LayawayCancelled->value)->count());
    }

    private function counter(): int
    {
        return (int) $this->withinOrganization(
            $this->organization,
            fn () => UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'),
        );
    }
}
