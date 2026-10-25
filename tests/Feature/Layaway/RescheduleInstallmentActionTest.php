<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\DetectOverdueAction;
use App\Actions\Layaway\RescheduleInstallmentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\InvalidRescheduleException;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Models\AuditEntry;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class RescheduleInstallmentActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private const REASON = 'Pactado por chat con la clienta.';

    private Organization $organization;

    private User $actor;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);
        $this->actor = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->layaway = $this->layawayIn($this->organization);

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($this->layaway));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_date_between_the_contiguous_installments_is_accepted(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->reschedule(2, '2026-10-20');
        $this->assertSame('2026-10-20', $this->dueDateOf(2));

        $this->reschedule(2, '2026-10-25');
        $this->assertSame('2026-10-25', $this->dueDateOf(2));
        $this->assertSame(3_000, (int) $this->installment(2)->amount_cents->cents);
    }

    public function test_crossing_the_next_installment_is_rejected_with_the_valid_range(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        try {
            $this->reschedule(2, '2026-10-26');
            $this->fail('Se cruzó la cuota siguiente.');
        } catch (InvalidRescheduleException $exception) {
            $window = $exception->window;
            $this->assertNotNull($window);
            $this->assertSame('2026-10-02', $window->earliest->toDateString());
            $this->assertSame('2026-10-25', $window->latest?->toDateString());
            $this->assertStringContainsString('entre el 2 de octubre de 2026 y el 25 de octubre de 2026', $exception->getMessage());
        }

        $this->assertSame('2026-10-10', $this->dueDateOf(2));
    }

    public function test_past_today_or_before_the_previous_installment_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');

        foreach (['2026-09-19', '2026-09-20', '2026-09-24'] as $day) {
            try {
                $this->reschedule(2, $day);
                $this->fail("Se aceptó el {$day}.");
            } catch (InvalidRescheduleException) {
                $this->assertSame('2026-10-10', $this->dueDateOf(2), $day);
            }
        }
    }

    public function test_a_paid_installment_is_rejected(): void
    {
        DB::table('installments')->where('layaway_id', $this->layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');

        $this->expectException(InvalidRescheduleException::class);
        $this->expectExceptionMessage('La cuota 1 ya está pagada');

        $this->reschedule(1, '2026-09-28');
    }

    public function test_the_last_installment_has_no_upper_limit(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->reschedule(5, '2027-06-30');

        $this->assertSame('2027-06-30', $this->dueDateOf(5));
    }

    public function test_leaving_overdue_is_recalculated_and_audited(): void
    {
        CarbonImmutable::setTestNow('2026-09-30 12:00:00');
        $this->withinOrganization($this->organization, fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::now()));
        $this->assertSame('overdue', $this->installment(1)->status->value);
        $this->assertSame('overdue', DB::table('layaways')->where('id', $this->layaway->getKey())->value('status'));

        $this->reschedule(1, '2026-10-05');

        $this->assertSame('pending', $this->installment(1)->status->value);
        $row = DB::table('layaways')->where('id', $this->layaway->getKey())->sole();
        $this->assertSame('active', $row->status);
        $this->assertSame('2026-10-05', $row->next_due_date);

        $entry = $this->withinOrganization($this->organization, fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::InstallmentRescheduled->value)->sole());
        $this->assertSame((int) $this->actor->getKey(), (int) $entry->causer_id);
        $this->assertSame('2026-09-25', $entry->properties['changed']['installments.1.due_date']['before'] ?? null);
        $this->assertSame('2026-10-05', $entry->properties['changed']['installments.1.due_date']['after'] ?? null);
        $this->assertSame(self::REASON, $entry->properties['changed']['reason']['after'] ?? null);
    }

    public function test_dates_are_read_in_the_organization_timezone(): void
    {
        $this->organization->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 22:00:00', 'America/Caracas'));

        try {
            $this->reschedule(1, '2026-10-01');
            $this->fail('Se aceptó como futura una fecha que en el negocio ya es hoy.');
        } catch (InvalidRescheduleException) {
            $this->assertSame('2026-09-25', $this->dueDateOf(1));
        }

        $this->reschedule(1, '2026-10-02');

        $this->assertSame('2026-10-02', $this->dueDateOf(1));
    }

    public function test_a_layaway_that_is_not_collecting_is_rejected(): void
    {
        $this->withinOrganization($this->organization, fn () => app(LayawayStateMachine::class)->transition($this->layaway, LayawayStatus::Cancelled));
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');

        $this->expectException(InvalidRescheduleException::class);

        $this->reschedule(2, '2026-10-12');
    }

    public function test_the_reason_is_required(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->expectException(InvalidArgumentException::class);

        $this->reschedule(2, '2026-10-12', 'Porque sí');
    }

    private function reschedule(int $number, string $day, string $reason = self::REASON): void
    {
        $this->withinOrganization($this->organization, fn () => app(RescheduleInstallmentAction::class)->handle(
            $this->installment($number),
            CarbonImmutable::parse($day),
            $this->actor,
            $reason,
        ));
    }

    private function installment(int $number): Installment
    {
        return $this->withinOrganization($this->organization, fn (): Installment => Installment::query()
            ->where('layaway_id', $this->layaway->getKey())
            ->where('number', $number)
            ->firstOrFail());
    }

    private function dueDateOf(int $number): string
    {
        return $this->installment($number)->due_date->toDateString();
    }
}
