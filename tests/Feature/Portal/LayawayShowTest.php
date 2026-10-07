<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * El detalle del apartado en el portal (`REQ-LAY-021`).
 *
 * El apartado de casi todos los casos es el del *happy path* de
 * `REQ-LAY-004`: $200 con $50 de inicial, cinco cuotas de $30 cada 15 días,
 * activado el 10/09 en Caracas; la primera vence el 25/09.
 */
final class LayawayShowTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private Layaway $layaway;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente', 'timezone' => 'America/Caracas']));
        $this->layaway = $this->layawayIn($this->organization);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->withinOrganization($this->organization, fn () => PaymentAccount::factory()->create(['holder_name' => 'Ana Pérez']));
        $this->withoutOrganization();
        $this->actingAs($this->customer, 'customer');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-1 y SP-1: el código de otra persona del mismo negocio y uno que no
     * existe son el mismo `404`.
     */
    public function test_a_foreign_or_unknown_code_is_not_found(): void
    {
        $foreign = $this->layawayIn($this->organization);
        $this->withoutOrganization();

        $this->get(route('portal.layaways.show', ['code' => $foreign->code]))->assertNotFound();
        $this->get(route('portal.layaways.show', ['code' => 'AP-ZZZZZZZZ']))->assertNotFound();
    }

    /**
     * RB-2, RB-6 y RB-7: esperando la inicial, se dice de qué negocio es, no
     * hay cronograma y sí la inicial, el plazo y dónde pagar.
     */
    public function test_pending_down_payment_shows_instructions_and_payment_accounts_instead_of_a_schedule(): void
    {
        // Un plazo que todavía no venció: uno pasado ya no se enseña (SP-2).
        CarbonImmutable::setTestNow('2026-09-12 10:00:00');
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['expires_at' => '2026-09-13 10:00:00']);

        $this->show()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/LayawayShow')
            ->where('business.name', 'Ferretería Oriente')
            ->where('layaway.code', $this->layaway->code)
            ->where('layaway.downPayment.cents', 5_000)
            ->where('layaway.expiresAt', '13 de septiembre a las 10:00 a. m.')
            ->has('installments', 0)
            ->where('nextPaymentSuggestion.kind', 'down_payment')
            ->where('nextPaymentSuggestion.amount.cents', 5_000)
            ->has('paymentAccounts', 1)
            ->where('paymentAccounts.0.holderName', 'Ana Pérez')
            ->where('receiptUrl', route('portal.receipts.create', ['code' => $this->layaway->code])));
    }

    /**
     * RB-4 y RB-9: el cronograma entero y, con la cuota 1 pagada, la
     * sugerencia es la 2 —la más antigua sin cubrir, como el reparto FIFO—.
     */
    public function test_the_schedule_and_the_fifo_suggestion(): void
    {
        $this->activate();
        DB::table('installments')->where('layaway_id', $this->layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);
        DB::table('installments')->where('layaway_id', $this->layaway->getKey())->where('number', 2)->update(['paid_cents' => 1_000, 'status' => 'partially_paid']);

        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->show()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('installments', 5)
            ->where('installments.0.tone', 'paid')
            ->where('installments.1.dueDate', '2026-10-10')
            ->where('installments.1.pending.cents', 2_000)
            ->where('nextPaymentSuggestion.kind', 'installment')
            ->where('nextPaymentSuggestion.installmentNumber', 2)
            ->where('nextPaymentSuggestion.amount.cents', 2_000)
            ->where('layaway.expiresAt', null)
            ->has('paymentAccounts', 1));
    }

    /**
     * RB-4: `daysUntilDue` en el calendario del negocio, en el límite de la
     * medianoche de Caracas. A las 23:59 del 24/09 la cuota del 25 vence
     * mañana; un minuto después, vence hoy. Y el 26 ya venció (rojo), aunque
     * esté dentro de la gracia.
     */
    public function test_days_until_due_follow_the_local_midnight(): void
    {
        $this->activate();

        foreach (['2026-09-24 23:59:00' => [1, 'soon'], '2026-09-25 00:01:00' => [0, 'soon'], '2026-09-26 00:01:00' => [-1, 'overdue'], '2026-09-15 12:00:00' => [10, 'pending']] as $now => [$days, $tone]) {
            CarbonImmutable::setTestNow($now);

            $this->show()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('installments.0.daysUntilDue', $days)
                ->where('installments.0.tone', $tone));
        }
    }

    /**
     * SP-4, el caso explícito: el motivo que escribió el negocio **no** sale
     * en ninguna parte de la respuesta; el texto es neutro.
     */
    public function test_the_cancellation_reason_does_not_reach_the_customer(): void
    {
        $this->withinOrganization($this->organization, fn () => app(LayawayStateMachine::class)->transition($this->layaway, LayawayStatus::Cancelled));
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['cancellation_reason' => 'Cliente moroso, no fiarle más']);
        $this->withoutOrganization();

        $response = $this->show()->assertOk();

        $this->assertStringNotContainsString('moroso', (string) $response->getContent());
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaway.statusMessage', 'Este apartado fue cancelado por el negocio. Si tienes dudas, escríbele.')
            ->where('nextPaymentSuggestion', null)
            ->has('paymentAccounts', 0)
            // `T-059` · un apartado que ya no cobra no ofrece subir comprobante.
            ->where('receiptUrl', null));
    }

    /**
     * SP-2 (`T-059`) · esperando la inicial con el plazo vencido: con un
     * comprobante en revisión, la reserva está protegida y se dice; sin él,
     * no se promete nada. En los dos casos, un plazo pasado no se enseña como
     * plazo.
     */
    public function test_an_expired_window_with_a_receipt_in_review_says_the_reservation_is_protected(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 10:00:00');
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['expires_at' => '2026-09-13 10:00:00']);

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaway.expiresAt', null)
            ->where('layaway.statusMessage', null));

        $this->receiptFor($this->layaway);
        $this->withoutOrganization();

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaway.expiresAt', null)
            ->where('layaway.statusMessage', 'Tu comprobante está en revisión: tu reserva está protegida aunque haya pasado el plazo de la inicial.')
            ->where('receiptUrl', route('portal.receipts.create', ['code' => $this->layaway->code])));
    }

    /**
     * SP-8: con el negocio suspendido el detalle funciona entero, con dónde
     * pagar incluido.
     */
    public function test_it_works_with_the_business_suspended(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->show()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('paymentAccounts', 1));
    }

    /**
     * RB-10 y SP-9: el catálogo de origen se enlaza solo si sigue abierto.
     */
    public function test_the_origin_catalog_is_linked_only_while_open(): void
    {
        $catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create(['slug' => 'ofertas']));
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['catalog_id' => $catalog->getKey()]);

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page->where('catalogUrl', route('catalog.show', ['catalog' => 'ofertas'])));

        DB::table('catalogs')->where('id', $catalog->getKey())->update(['published_at' => null]);

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page->where('catalogUrl', null));
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function show(): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('portal.layaways.show', ['code' => $this->layaway->code]));
    }

    private function activate(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($this->layaway));
        $this->withoutOrganization();
        CarbonImmutable::setTestNow();
    }
}
