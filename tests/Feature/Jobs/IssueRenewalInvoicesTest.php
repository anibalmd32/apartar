<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Actions\Subscription\IssueInvoiceAction;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\IssueRenewalInvoices;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Queries\Platform\RenewableSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsSubscriptions;
use Tests\TestCase;

/**
 * La renovación (`REQ-SUB-006` RB-8, RB-9, `T-069`): la factura del período
 * siguiente sale siete días antes del fin, con vencimiento ese día; una sola
 * vez por período; nunca para Free.
 */
final class IssueRenewalInvoicesTest extends TestCase
{
    use BillsSubscriptions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** RB-8 · siete días antes, sí; ocho, todavía no. Y vence al acabar el período. */
    public function test_the_renewal_is_issued_seven_days_before_the_period_ends(): void
    {
        CarbonImmutable::setTestNow('2026-10-24 23:00:00');
        $this->assertSame(0, $this->renew());

        CarbonImmutable::setTestNow('2026-10-25 00:00:00');
        $this->assertSame(1, $this->renew());

        $invoice = $this->billed(fn (): SubscriptionInvoice => SubscriptionInvoice::query()->sole());
        $this->assertSame(InvoiceKind::Renewal, $invoice->kind);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 00:00:00'), $invoice->period_start);
        $this->assertEquals(CarbonImmutable::parse('2026-12-01 00:00:00'), $invoice->period_end);
        $this->assertSame(1_500, $invoice->amount_cents);
    }

    /**
     * El vencimiento es el día en que acaba el período **en la zona del
     * negocio**: el período acaba a las 00:00 de la plataforma del día 1, que
     * en Caracas es todavía el 31.
     */
    public function test_the_due_date_is_the_end_of_the_period_in_the_business_calendar(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 12:00:00');
        $this->renew();

        $end = CarbonImmutable::parse('2026-11-01 00:00:00');
        $this->assertSame(
            $end->setTimezone('America/Caracas')->toDateString(),
            $this->billed(fn (): string => SubscriptionInvoice::query()->sole()->due_date->toDateString()),
        );
    }

    /** Idempotente: una segunda pasada no emite otra; una anulada no cuenta. */
    public function test_it_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 12:00:00');

        $this->assertSame(1, $this->renew());
        $this->assertSame(0, $this->renew());

        $this->billed(fn () => SubscriptionInvoice::query()->update(['status' => InvoiceStatus::Void]));
        $this->assertSame(1, $this->renew(), 'Anular y volver a emitir el mismo período es legítimo.');
    }

    /**
     * RB-9 · Free nunca: ni un plan sin precio ni una bajada a Free que vence
     * al acabar el período. Y quien baja a otro plan de pago renueva **ese**.
     */
    public function test_free_is_never_invoiced_and_a_scheduled_downgrade_bills_its_plan(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 12:00:00');
        $free = Plan::factory()->create(['code' => 'free', 'position' => 1, 'price_cents' => 0]);
        $cheaper = Plan::factory()->create(['position' => 2, 'price_cents' => 900]);

        $this->billed(fn () => Subscription::query()->update(['scheduled_plan_id' => $free->getKey(), 'scheduled_plan_at' => '2026-11-01 00:00:00']));
        $this->assertSame(0, $this->renew(), 'Baja a Free al acabar el período: no hay nada que renovar.');

        $this->billed(fn () => Subscription::query()->update(['scheduled_plan_id' => $cheaper->getKey()]));
        $this->assertSame(1, $this->renew());
        $this->assertSame(900, $this->billed(fn (): int => SubscriptionInvoice::query()->sole()->amount_cents));

        $this->billed(fn () => SubscriptionInvoice::query()->update(['status' => InvoiceStatus::Void]));
        $this->billed(fn () => Subscription::query()->update(['plan_id' => $free->getKey(), 'scheduled_plan_id' => null, 'scheduled_plan_at' => null]));
        $this->assertSame(0, $this->renew(), 'En Free no se factura.');
    }

    /**
     * Una pasada que no corrió un día no deja a nadie sin factura —el
     * período ya acabado también entra—; y una organización que falla no
     * tumba a las demás.
     */
    public function test_a_missed_day_is_recovered_and_a_failure_does_not_stop_the_pass(): void
    {
        // Sin RIF y sin facturas: su primera factura no se puede emitir.
        $broken = Organization::factory()->create(['document' => null]);
        $this->withinOrganization($broken, fn () => Subscription::factory()->forPlan($this->paidPlan)->create(['current_period_end' => CarbonImmutable::parse('2026-10-28 00:00:00')]));

        $other = Organization::factory()->create();
        $this->withinOrganization($other, fn () => Subscription::factory()->forPlan($this->paidPlan)->create(['current_period_end' => CarbonImmutable::parse('2026-10-20 00:00:00')]));

        CarbonImmutable::setTestNow('2026-10-26 12:00:00');
        $this->assertSame(2, $this->renew());

        $this->assertSame(1, $this->withinOrganization($other, fn (): int => SubscriptionInvoice::query()->count()), 'Su período ya acabó y la pasada lo recupera.');
        $this->assertSame(0, $this->withinOrganization($broken, fn (): int => SubscriptionInvoice::query()->count()));
    }

    /**
     * RB-8 · «siete días antes» se cuenta en días del negocio: un período que
     * acaba a las 14:32 del día 10 se factura en la pasada de las 06:00 del
     * día 3, no del 4.
     */
    public function test_seven_days_are_business_days_not_hours(): void
    {
        $this->billedOrganization->forceFill(['timezone' => 'UTC'])->save();
        $this->billed(fn () => Subscription::query()->update(['current_period_end' => CarbonImmutable::parse('2026-11-10 14:32:00', 'UTC')]));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));

        $this->assertSame(1, $this->renew());
    }

    /**
     * El período no deriva al día 28: el de un alta del 31 de enero acaba el
     * 28 de febrero, y su renovación vuelve al 31 de marzo.
     */
    public function test_the_renewal_keeps_the_anchor_day(): void
    {
        $this->billed(fn () => Subscription::query()->update([
            'current_period_start' => CarbonImmutable::parse('2027-01-31 10:00:00'),
            'current_period_end' => CarbonImmutable::parse('2027-02-28 10:00:00'),
        ]));
        CarbonImmutable::setTestNow('2027-02-24 12:00:00');

        $this->renew();

        $this->assertEquals(CarbonImmutable::parse('2027-03-31 10:00:00'), $this->billed(fn (): mixed => SubscriptionInvoice::query()->sole()->period_end));
    }

    /**
     * Lo que no toca: una suscripción cerrada no renueva; una factura de
     * **otro** período no bloquea la del siguiente; y una bajada programada
     * para después del fin del período no cambia el plan de esta renovación.
     */
    public function test_what_does_not_count(): void
    {
        CarbonImmutable::setTestNow('2026-10-26 12:00:00');
        $this->invoiceFor(['kind' => InvoiceKind::PlanChange, 'period_start' => '2026-10-01 00:00:00', 'period_end' => '2026-11-01 00:00:00', 'status' => InvoiceStatus::Paid, 'due_date' => '2026-10-01']);

        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Cancelled]));
        $this->assertSame(0, $this->renew(), 'Cerrada no renueva.');

        $cheaper = Plan::factory()->create(['position' => 1, 'price_cents' => 900]);
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Grace, 'scheduled_plan_id' => $cheaper->getKey(), 'scheduled_plan_at' => '2026-12-01 00:00:00']));
        $this->assertSame(1, $this->renew(), 'Una factura de otro período no bloquea.');
        $this->assertSame(1_500, $this->billed(fn (): int => SubscriptionInvoice::query()->where('kind', InvoiceKind::Renewal)->sole()->amount_cents), 'La bajada vence después: se renueva el plan de hoy.');
    }

    /** Una organización que revienta no tumba la pasada: las demás se facturan. */
    public function test_an_organization_that_blows_up_does_not_stop_the_others(): void
    {
        $yearly = Plan::factory()->create(['price_cents' => 9_900, 'interval' => 'yearly']);
        $broken = Organization::factory()->create();
        $this->withinOrganization($broken, fn () => Subscription::factory()->forPlan($yearly)->create(['current_period_end' => CarbonImmutable::parse('2026-10-30 00:00:00')]));
        $healthy = Organization::factory()->create();
        $this->withinOrganization($healthy, fn () => Subscription::factory()->forPlan($this->paidPlan)->create(['current_period_end' => CarbonImmutable::parse('2026-10-30 00:00:00')]));

        CarbonImmutable::setTestNow('2026-10-26 12:00:00');

        $this->assertSame(2, $this->renew(), 'Nuestra organización y la sana; la del plan anual que nadie sabe medir, no.');
        $this->assertSame(1, $this->withinOrganization($healthy, fn (): int => SubscriptionInvoice::query()->count()));
    }

    /** Va en la programación diaria, con su nombre y en la cola ordinaria. */
    public function test_it_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'subscriptions:issue-renewal-invoices');

        $this->assertNotNull($event);
        $this->assertSame('0 6 * * *', $event->expression);
        $this->assertSame('default', (new IssueRenewalInvoices)->queue);
    }

    private function renew(): int
    {
        return (new IssueRenewalInvoices)->handle(app(RenewableSubscriptions::class), app(TenantContext::class), app(IssueInvoiceAction::class));
    }
}
