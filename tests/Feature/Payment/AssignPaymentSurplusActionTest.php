<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\AssignPaymentSurplusAction;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\InvalidSurplusDestinationException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\SurplusDestination;
use App\Domain\Payment\SurplusDestinationType;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class AssignPaymentSurplusActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const REASON = 'El sobrante se le da a Irma como recompensa.';

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->staff = $this->within(fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_assigns_the_surplus_to_a_team_member_without_touching_the_balance(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');
        $irma = $this->within(fn (): User => User::factory()->create(['name' => 'Irma Rojas']));
        $balanceBefore = $this->balanceOf($layaway);

        $assigned = $this->assign($payment, new SurplusDestination(SurplusDestinationType::TeamMember, $irma), self::REASON);

        $this->assertSame(SurplusDestinationType::TeamMember, $assigned->surplus_destination_type);
        $this->assertSame($irma->getKey(), $assigned->surplus_user_id);
        $this->assertSame('Irma Rojas', $assigned->surplus_team_member_name);
        $this->assertSame($this->staff->getKey(), $assigned->surplus_assigned_by);
        $this->assertSame('2026-09-10 10:00:00', $assigned->surplus_assigned_at?->toDateTimeString());
        $this->assertSame(1_000, $assigned->unallocated_cents->cents);
        $this->assertSame($balanceBefore, $this->balanceOf($layaway), 'El destino del sobrante no toca el saldo del apartado.');

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::PaymentSurplusAssigned->value)->sole());
        $changed = $entry->properties?->toArray()['changed'] ?? [];
        $this->assertSame($this->staff->getKey(), $entry->causer_id);
        $this->assertSame('team_member', $changed['surplus_destination_type']['after'] ?? null);
        $this->assertSame('Irma Rojas', $changed['surplus_team_member']['after'] ?? null);
        $this->assertSame(self::REASON, $changed['reason']['after'] ?? null);
    }

    public function test_it_assigns_the_surplus_to_the_fixed_destinations(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');

        $tip = $this->assign($payment, new SurplusDestination(SurplusDestinationType::Tip), self::REASON);

        $this->assertSame(SurplusDestinationType::Tip, $tip->surplus_destination_type);
        $this->assertNull($tip->surplus_user_id);
        $this->assertNull($tip->surplus_note);

        $cash = $this->assign($payment, new SurplusDestination(SurplusDestinationType::PettyCash), self::REASON);

        $this->assertSame(SurplusDestinationType::PettyCash, $cash->surplus_destination_type);

        $other = $this->assign($payment, new SurplusDestination(SurplusDestinationType::Other, note: 'Se compró un paquete de load'), self::REASON);

        $this->assertSame(SurplusDestinationType::Other, $other->surplus_destination_type);
        $this->assertSame('Se compró un paquete de load', $other->surplus_note);
    }

    public function test_a_member_of_another_organization_is_refused(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');
        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->create());

        $this->assertRefused('no es del equipo', fn () => $this->assign($payment, new SurplusDestination(SurplusDestinationType::TeamMember, $stranger), self::REASON));

        $this->assertNull($payment->fresh()?->surplus_destination_type);
    }

    public function test_other_without_a_note_is_refused(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');

        $this->assertRefused('necesita una nota', fn () => $this->assign($payment, new SurplusDestination(SurplusDestinationType::Other), self::REASON));
        $this->assertNull($payment->fresh()?->surplus_destination_type);
    }

    public function test_a_payment_without_surplus_is_refused(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '30.00');

        $this->assertRefused('no tiene sobrante', fn () => $this->assign($payment, new SurplusDestination(SurplusDestinationType::Tip), self::REASON));
    }

    public function test_a_closed_layaway_keeps_its_destination_and_cannot_change_it(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');
        $irma = $this->within(fn (): User => User::factory()->create(['name' => 'Irma Rojas']));
        $this->assign($payment, new SurplusDestination(SurplusDestinationType::TeamMember, $irma), self::REASON);

        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Cancelled->value]);

        $this->assertRefused('cancelado', fn () => $this->assign($payment, new SurplusDestination(SurplusDestinationType::Tip), self::REASON));

        $this->assertSame(SurplusDestinationType::TeamMember, $this->within(fn (): Payment => Payment::query()->findOrFail($payment->getKey()))->surplus_destination_type);
    }

    public function test_someone_of_another_organization_cannot_assign(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');
        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->expectException(AuthorizationException::class);

        $this->within(fn () => app(AssignPaymentSurplusAction::class)->handle(
            $payment,
            new SurplusDestination(SurplusDestinationType::Tip),
            $stranger,
            self::REASON,
        ));
    }

    public function test_the_destination_can_be_changed_and_removed(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');
        $irma = $this->within(fn (): User => User::factory()->create(['name' => 'Irma Rojas']));
        $this->assign($payment, new SurplusDestination(SurplusDestinationType::TeamMember, $irma), self::REASON);

        $changed = $this->assign($payment, new SurplusDestination(SurplusDestinationType::Tip), 'El negocio cambió de idea.');

        $this->assertSame(SurplusDestinationType::Tip, $changed->surplus_destination_type);
        $this->assertNull($changed->surplus_user_id);

        $removed = $this->within(fn (): Payment => app(AssignPaymentSurplusAction::class)->remove(
            $payment,
            $this->staff,
            'El sobrante vuelve a quedar sin asignar.',
        ));

        $this->assertNull($removed->surplus_destination_type);
        $this->assertNull($removed->surplus_user_id);
        $this->assertSame(1_000, $removed->unallocated_cents->cents);
        $this->assertSame(3, $this->within(fn (): int => AuditEntry::query()->where('event', AuditEvent::PaymentSurplusAssigned->value)->count()));
    }

    public function test_a_disabled_member_keeps_the_destination_and_shows_the_name(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '160.00');
        $irma = $this->within(fn (): User => User::factory()->create(['name' => 'Irma Rojas']));
        $this->assign($payment, new SurplusDestination(SurplusDestinationType::TeamMember, $irma), self::REASON);

        $irma->forceFill(['disabled_at' => CarbonImmutable::now()])->save();

        $stored = $this->within(fn (): Payment => Payment::query()->findOrFail($payment->getKey()));

        $this->assertSame(SurplusDestinationType::TeamMember, $stored->surplus_destination_type);
        $this->assertSame('Irma Rojas', $stored->surplus_team_member_name);
    }

    /**
     * @param  Closure(): mixed  $attempt
     */
    private function assertRefused(string $message, Closure $attempt): void
    {
        $exception = $this->refused($attempt);

        $this->assertInstanceOf(InvalidSurplusDestinationException::class, $exception);
        $this->assertStringContainsString($message, $exception->getMessage());
    }

    /**
     * @param  Closure(): mixed  $attempt
     */
    private function refused(Closure $attempt): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $exception) {
            return $exception;
        }

        $this->fail('La asignación tenía que haberse rechazado.');
    }

    private function assign(Payment $payment, SurplusDestination $destination, string $reason): Payment
    {
        return $this->within(fn (): Payment => app(AssignPaymentSurplusAction::class)->handle($payment, $destination, $this->staff, $reason));
    }

    private function pay(Layaway $layaway, string $amount): Payment
    {
        $cents = (int) bcmul($amount, '100', 0);
        $excess = max(0, $cents - 150_00);

        return $this->within(fn (): Payment => app(RegisterManualPaymentAction::class)->handle(
            $layaway,
            new ManualPaymentData(
                PaymentMethod::EfectivoUsd,
                null,
                $amount,
                Currency::USD,
                null,
                CarbonImmutable::now(),
                'Pago recibido en tienda por Ana.',
                (string) Str::uuid(),
                $excess > 0 ? $excess : null,
            ),
            $this->staff,
        ));
    }

    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->pay($layaway, '50.00');

        return $layaway;
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceOf(Layaway $layaway): array
    {
        return (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['status', 'paid_cents', 'remaining_cents', 'next_due_date']);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
