<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\RecordedReceipts;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentAccountPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_payment_accounts' => 10]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_the_page_carries_the_six_methods_and_the_plan_usage(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/cuentas-de-cobro')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/payment-accounts/Index')
                ->has('accounts', 0)
                ->has('methods', 6)
                ->where('methods.0.value', 'pago_movil')
                ->where('methods.0.identifierLabel', 'Teléfono')
                ->where('usage.limit', 10)
                ->where('usage.used', 0));
    }

    public function test_every_method_accepts_its_own_identifier(): void
    {
        $owner = $this->owner();

        foreach ($this->identifiers() as [$method, $valid, $_invalid]) {
            $this->actingAs($owner)
                ->post('/backoffice/cuentas-de-cobro', $this->payload($method, $valid))
                ->assertRedirect('/backoffice/cuentas-de-cobro');
        }

        $this->assertSame(6, $this->withinOrganization($this->organization, fn (): int => PaymentAccount::query()->count()));
    }

    public function test_every_method_rejects_an_identifier_of_the_wrong_shape(): void
    {
        $owner = $this->owner();

        foreach ($this->identifiers() as [$method, $_valid, $invalid]) {
            $this->actingAs($owner)
                ->postJson('/backoffice/cuentas-de-cobro', $this->payload($method, $invalid))
                ->assertStatus(422)
                ->assertJsonValidationErrors('identifier');
        }

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => PaymentAccount::query()->count()));
    }

    public function test_the_holder_and_the_document_are_required(): void
    {
        $payload = $this->payload(PaymentMethod::Zelle, 'pagos@eltornillo.com');

        unset($payload['holder_name'], $payload['holder_document']);

        $this->actingAs($this->owner())
            ->postJson('/backoffice/cuentas-de-cobro', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['holder_name', 'holder_document']);
    }

    public function test_the_holder_document_must_be_a_valid_cedula_or_rif(): void
    {
        $payload = ['holder_document' => 'cualquier cosa'] + $this->payload(PaymentMethod::Zelle, 'pagos@eltornillo.com');

        $this->actingAs($this->owner())
            ->postJson('/backoffice/cuentas-de-cobro', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['holder_document']);
    }

    public function test_instructions_are_capped_at_three_hundred_characters(): void
    {
        $payload = $this->payload(PaymentMethod::PagoMovil, '0414-1234567');
        $payload['instructions'] = str_repeat('a', 301);

        $this->actingAs($this->owner())
            ->postJson('/backoffice/cuentas-de-cobro', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('instructions');
    }

    public function test_the_plan_ceiling_answers_422_with_the_upgrade(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            $free = Plan::factory()->create(['max_payment_accounts' => 1, 'name' => 'Free']);

            Subscription::query()->firstOrFail()->forceFill(['plan_id' => $free->getKey()])->save();
        });

        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/backoffice/cuentas-de-cobro', $this->payload(PaymentMethod::PagoMovil, '0414-1234567'))
            ->assertRedirect('/backoffice/cuentas-de-cobro');

        $this->actingAs($owner)
            ->postJson('/backoffice/cuentas-de-cobro', $this->payload(PaymentMethod::Zelle, 'pagos@eltornillo.com'))
            ->assertStatus(422)
            ->assertJsonPath('limit.metric', 'payment_accounts')
            ->assertJsonPath('upgradeUrl', '/backoffice/suscripcion');
    }

    public function test_deleting_an_account_with_receipts_reports_the_deactivation(): void
    {
        $account = $this->existingAccount();

        $this->app->bind(RecordedReceipts::class, fn (): RecordedReceipts => new class extends RecordedReceipts
        {
            public function existFor(PaymentAccount $account): bool
            {
                return true;
            }
        });

        $this->actingAs($this->owner())
            ->delete('/backoffice/cuentas-de-cobro/'.$account->getKey())
            ->assertRedirect('/backoffice/cuentas-de-cobro')
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'no se ha eliminado'));

        $this->assertDatabaseHas('payment_accounts', ['id' => $account->getKey(), 'is_active' => false]);
    }

    public function test_deactivating_the_last_active_account_is_allowed_with_a_warning(): void
    {
        $account = $this->existingAccount();

        $payload = $this->payload(PaymentMethod::PagoMovil, $account->identifier);
        $payload['is_active'] = false;

        $this->actingAs($this->owner())
            ->put('/backoffice/cuentas-de-cobro/'.$account->getKey(), $payload)
            ->assertRedirect('/backoffice/cuentas-de-cobro')
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'siguen publicados'));

        $this->assertFalse($account->refresh()->is_active);
    }

    public function test_the_order_is_kept(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/backoffice/cuentas-de-cobro', $this->payload(PaymentMethod::PagoMovil, '0414-1234567'));
        $this->actingAs($owner)->post('/backoffice/cuentas-de-cobro', $this->payload(PaymentMethod::Zelle, 'pagos@eltornillo.com'));

        $this->actingAs($owner)
            ->get('/backoffice/cuentas-de-cobro')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accounts.0.method', 'pago_movil')
                ->where('accounts.1.method', 'zelle'));
    }

    public function test_a_member_cannot_open_or_change_payment_accounts(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $account = $this->existingAccount();

        $this->actingAs($member)->get('/backoffice/cuentas-de-cobro')->assertForbidden();

        $this->actingAs($member)
            ->post('/backoffice/cuentas-de-cobro', $this->payload(PaymentMethod::PagoMovil, '0414-1234567'))
            ->assertForbidden();

        $this->actingAs($member)
            ->delete('/backoffice/cuentas-de-cobro/'.$account->getKey())
            ->assertForbidden();
    }

    public function test_an_account_from_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): PaymentAccount => PaymentAccount::factory()->create(),
        );

        $this->actingAs($this->owner())
            ->delete('/backoffice/cuentas-de-cobro/'.$foreign->getKey())
            ->assertNotFound();
    }

    public function test_payment_accounts_do_not_survive_a_suspension(): void
    {
        $this->organization->forceFill([
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ])->save();

        $this->actingAs($this->owner())->get('/backoffice/cuentas-de-cobro')->assertForbidden();
    }

    /**
     * @return list<array{PaymentMethod, string, string}>
     */
    private function identifiers(): array
    {
        return [
            [PaymentMethod::PagoMovil, '0414-1234567', '+1 305 555 0199'],
            [PaymentMethod::Transferencia, '0134-0123-45-0123456789', '1234'],
            [PaymentMethod::Zelle, 'pagos@eltornillo.com', '0414-1234567'],
            [PaymentMethod::BinancePay, '384756291', 'mi-wallet'],
            [PaymentMethod::EfectivoUsd, 'Av. Bolívar, local 4, Valencia', 'aquí'],
            [PaymentMethod::EfectivoVes, 'Calle 12 con carrera 3, Barquisimeto', 'ahí'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentMethod $method, string $identifier): array
    {
        return [
            'method' => $method->value,
            'bank' => $method->isCash() ? null : 'Banesco',
            'holder_name' => 'Carlos Rojas',
            'holder_document' => 'V-12345678',
            'identifier' => $identifier,
            'instructions' => null,
            'is_active' => true,
        ];
    }

    private function existingAccount(): PaymentAccount
    {
        $account = $this->withinOrganization(
            $this->organization,
            fn (): PaymentAccount => PaymentAccount::factory()->create(),
        );

        $this->recalculateUsage($this->organization);

        return $account;
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }
}
