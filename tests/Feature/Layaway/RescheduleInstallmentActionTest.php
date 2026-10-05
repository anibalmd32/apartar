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

/**
 * El negocio mueve la fecha de una cuota (`REQ-LAY-011`).
 *
 * El apartado es el del *happy path* de `REQ-LAY-004`, activado el 10/09 en
 * Caracas: cinco cuotas de $30 que vencen el 25/09, el 10/10, el 25/10, el
 * 09/11 y el 24/11.
 */
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

    /**
     * RB-1 y RB-3: dentro de la ventana entre las cuotas contiguas se mueve, y
     * los dos bordes cuentan; solo cambia `due_date`.
     */
    public function test_a_date_between_the_contiguous_installments_is_accepted(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->reschedule(2, '2026-10-20');
        $this->assertSame('2026-10-20', $this->dueDateOf(2));

        $this->reschedule(2, '2026-10-25');
        $this->assertSame('2026-10-25', $this->dueDateOf(2));
        $this->assertSame(3_000, (int) $this->installment(2)->amount_cents->cents);
    }

    /**
     * SP-1: cruzar la cuota siguiente se rechaza, con el rango válido dicho.
     */
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

    /**
     * SP-2 y RB-3: la fecha tiene que ser futura, y tampoco vale antes que la
     * cuota anterior.
     */
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

    /**
     * SP-3: una cuota pagada no se mueve.
     */
    public function test_a_paid_installment_is_rejected(): void
    {
        DB::table('installments')->where('layaway_id', $this->layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');

        $this->expectException(InvalidRescheduleException::class);
        $this->expectExceptionMessage('La cuota 1 ya está pagada');

        $this->reschedule(1, '2026-09-28');
    }

    /**
     * SP-4: la última cuota no tiene tope.
     */
    public function test_the_last_installment_has_no_upper_limit(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->reschedule(5, '2027-06-30');

        $this->assertSame('2027-06-30', $this->dueDateOf(5));
    }

    /**
     * RB-5 y el *happy path*: la cuota en mora que se mueve al futuro sale de
     * `overdue`, el apartado vuelve a `active` y `next_due_date` es la fecha
     * nueva. Queda auditado con la fecha de antes, la de después y el motivo.
     */
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

    /**
     * RB-8: «futura» es en el calendario del negocio, no en el de la
     * aplicación. Con el negocio en Tokio, a las 22:00 del 30/09 de Caracas
     * —la zona de la aplicación— allí ya es el 1/10: el 1/10 es hoy y no
     * vale, y el 2/10 sí. Con un negocio en la misma zona que la aplicación
     * este caso no distinguiría nada (se comprobó: pasaba con la zona quitada).
     */
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

    /**
     * Un apartado que ya no se cobra no tiene cronograma que reprogramar.
     */
    public function test_a_layaway_that_is_not_collecting_is_rejected(): void
    {
        $this->withinOrganization($this->organization, fn () => app(LayawayStateMachine::class)->transition($this->layaway, LayawayStatus::Cancelled));
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');

        $this->expectException(InvalidRescheduleException::class);

        $this->reschedule(2, '2026-10-12');
    }

    /**
     * RB-4: sin motivo no se reprograma.
     */
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
