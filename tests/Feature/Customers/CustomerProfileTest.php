<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Actions\Layaway\CancelLayawayAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Http\Controllers\Backoffice\CustomerShowController;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\LayawayCodeNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * El perfil de un cliente (`T-065`, `REQ-BOF-041`) y el reenvío del código
 * (`REQ-IDN-008` SP-5).
 */
final class CustomerProfileTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'America/Caracas'));
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-2: el balance del perfil **es** el del portal para la misma ficha
     * (equivalencia): comprometido, pagado, pendiente, cuotas vencidas y
     * próximo vencimiento.
     */
    public function test_the_balance_is_the_one_the_customer_sees_in_the_portal(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 8_000, 'remaining_cents' => 12_000, 'next_due_date' => '2026-09-05']);
        $this->installment($layaway, 1, '2026-09-05');
        $this->installment($layaway, 2, '2026-09-20');
        $customer = $this->customerOf($layaway);

        $profile = $this->actingAs($this->member)->get(route('customers.show', $customer))->viewData('page')['props']['totals'];
        $portal = $this->actingAs($customer, 'customer')->get(route('portal.dashboard'))->viewData('page')['props']['businesses'][0]['totals'];

        foreach (['committed', 'paid', 'remaining', 'overdueInstallments', 'nextDueDate'] as $key) {
            $this->assertEquals($portal[$key], $profile[$key], "«{$key}» no coincide con el portal.");
        }

        $this->assertSame(1, $profile['overdueInstallments']);
    }

    /**
     * RB-6, RB-7: el negocio ve el motivo íntegro de una cancelación y de un
     * rechazo y quién los hizo; el archivo se abre con un enlace firmado de 15
     * minutos.
     */
    public function test_the_business_sees_full_reasons_and_who_resolved(): void
    {
        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Active);
        $customer = $this->customerOf($cancelled);
        $this->withinOrganization($this->organization, fn () => app(CancelLayawayAction::class)->handle($cancelled, $this->member, 'El cliente pidió cancelar por una mudanza.'));
        $this->receiptFor($cancelled, ReceiptStatus::Rejected, [
            'customer_id' => $customer->getKey(),
            'rejection_reason_code' => RejectionReasonCode::NotLegible,
            'rejection_reason' => 'La imagen está cortada y no se lee la referencia.',
            'reviewed_by' => $this->member->getKey(),
            'reviewed_at' => now(),
        ]);
        $this->withoutOrganization();

        $response = $this->actingAs($this->member)->get(route('customers.show', $customer));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/customers/Show')
            ->where('layaways.history.0.cancellationReason', 'El cliente pidió cancelar por una mudanza.')
            ->where('layaways.history.0.cancelledBy', 'Ana Pérez')
            ->where('receipts.0.rejectionReason', 'La imagen está cortada y no se lee la referencia.')
            ->where('receipts.0.reviewedBy', 'Ana Pérez'));

        $url = $response->viewData('page')['props']['receipts'][0]['fileUrl'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(CarbonImmutable::now()->addMinutes(15)->getTimestamp(), (int) $query['expires']);
    }

    /**
     * SP-3: la URL de una ficha absorbida lleva a la superviviente con un
     * aviso; un id que nunca existió, `404`.
     */
    public function test_an_absorbed_record_redirects_to_its_survivor(): void
    {
        $survivor = $this->customerOf($this->layawayIn($this->organization, LayawayStatus::Active));
        $this->withinOrganization($this->organization, fn () => (new CustomerMerge)->forceFill([
            'absorbed_customer_id' => 999_999,
            'absorbed_document_number' => 'V11111111',
            'survivor_customer_id' => $survivor->getKey(),
        ])->save());
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('customers.show', 999_999))
            ->assertRedirect(route('customers.show', $survivor))
            ->assertSessionHas('status');
        $this->get(route('customers.show', 888_888))->assertNotFound();
    }

    /**
     * RB-9, SP-6, SP-8: la misma persona cliente de otro negocio —el mismo
     * documento— no deja ver nada de allí; su ficha de allí es `404`.
     */
    public function test_nothing_from_another_organization_is_visible(): void
    {
        $ours = $this->customerOf($this->layawayIn($this->organization, LayawayStatus::Active));
        $neighbour = $this->subscribe($this->neighbourOrganization());
        $theirs = $this->customerOf($this->layawayIn($neighbour, LayawayStatus::Active));
        DB::table('customers')->where('id', $theirs->getKey())->update(['document_number' => $ours->document_number]);
        $this->withoutOrganization();

        // Un comprobante del otro negocio que, por error, apuntara a nuestra
        // ficha: lo que lo deja fuera es el global scope, no el `customer_id`.
        $stray = $this->receiptFor(Layaway::query()->withoutTenantScope()->where('organization_id', $neighbour->getKey())->firstOrFail());
        DB::table('receipts')->where('id', $stray->getKey())->update(['customer_id' => $ours->getKey()]);
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('customers.show', $ours))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('layaways.active', 1)
            ->has('layaways.history', 0)
            ->has('receipts', 0)
            ->where('lists.receiptsTotal', 0));
        $this->get(route('customers.show', $theirs))->assertNotFound();
    }

    /**
     * SP-2: las listas se cortan en 20 —las más recientes— y «ver más» trae
     * 20 más; cuando ya está todo, no se ofrece.
     */
    public function test_long_lists_show_twenty_and_more_on_request(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $customer = $this->customerOf($layaway);
        $receipts = [];

        for ($i = 0; $i < 21; $i++) {
            $receipts[] = (int) $this->receiptFor($layaway, attributes: ['customer_id' => $customer->getKey()])->getKey();
        }

        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('customers.show', $customer))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('receipts', CustomerShowController::LIST_LIMIT)
            ->where('receipts.0.id', $receipts[20])
            ->where('lists.receiptsTotal', 21)
            ->where('lists.moreUrl', route('customers.show', [$customer, 'mostrar' => 40])));
        $this->get(route('customers.show', [$customer, 'mostrar' => 40]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('receipts', 21)
            ->where('lists.moreUrl', null));
        $this->get(route('customers.show', [$customer, 'mostrar' => 'todo']))->assertInertia(fn (AssertableInertia $page) => $page->has('receipts', 20));
    }

    /**
     * `REQ-IDN-008` SP-5 y `REQ-BOF-041` SP-7: el código se reenvía al correo
     * de la ficha; sin correo, el aviso de dictarlo. Un apartado terminal no
     * ofrece reenviarlo.
     */
    public function test_the_layaway_code_is_resent_to_the_customers_email(): void
    {
        Notification::fake();
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $customer = $this->customerOf($layaway);
        $this->actingAs($this->member)->from(route('customers.show', $customer));

        $this->post(route('layaways.resend-code', $layaway))->assertSessionHasErrors('resend');
        Notification::assertNothingSent();

        DB::table('customers')->where('id', $customer->getKey())->update(['email' => 'maria@example.com']);
        $this->post(route('layaways.resend-code', $layaway))->assertSessionHas('status', "Código {$layaway->code} reenviado a maria@example.com.");
        Notification::assertSentOnDemand(LayawayCodeNotification::class, fn (LayawayCodeNotification $notification, array $channels, object $notifiable): bool => $notification->code === $layaway->code
            && $notifiable->routes['mail'] === 'maria@example.com');

        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Cancelled->value]);
        $this->get(route('customers.show', $customer))->assertInertia(fn (AssertableInertia $page) => $page->where('layaways.history.0.resendCodeUrl', null));
    }

    // -------------------------------------------------------------------

    private function customerOf(Layaway $layaway): Customer
    {
        return Customer::query()->withoutGlobalScopes()->findOrFail($layaway->customer_id);
    }

    private function installment(Layaway $layaway, int $number, string $due): void
    {
        DB::table('installments')->insert([
            'organization_id' => $layaway->organization_id,
            'layaway_id' => $layaway->getKey(),
            'number' => $number,
            'due_date' => $due,
            'amount_cents' => 3_000,
            'paid_cents' => 0,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
