<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\UpsertPaymentAccountAction;
use App\Actions\Organization\UpsertPaymentAccountData;
use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\RecordedReceipts;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UpsertPaymentAccountActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    public function test_the_owner_declares_a_payment_account(): void
    {
        $owner = $this->subscribedOwner();

        $account = $this->action()->handle($this->data(), $owner);

        $this->assertSame(PaymentMethod::PagoMovil, $account->method);
        $this->assertSame('0414-1234567', $account->identifier);
        $this->assertTrue($account->is_active);
        $this->assertSame((int) $this->organization->getKey(), $account->organization_id);
    }

    public function test_a_member_cannot_declare_a_payment_account(): void
    {
        $this->subscribedOwner();
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($this->data(), $member);
    }

    public function test_the_plan_ceiling_is_enforced(): void
    {
        $owner = $this->subscribedOwner(maxPaymentAccounts: 1);

        $this->action()->handle($this->data(), $owner);

        try {
            $this->action()->handle($this->data(identifier: '0424-7654321'), $owner);
            $this->fail('La segunda cuenta debió rebotar contra el techo del plan.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::PaymentAccounts, $exception->metric);
            $this->assertSame(1, $exception->limit);
            $this->assertStringContainsString('cuenta de cobro', $exception->getMessage());
        }

        $this->assertSame(1, PaymentAccount::query()->count());
    }

    public function test_the_usage_counter_moves_with_the_write(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(), $owner);

        $this->assertSame(1, $this->counter());
    }

    public function test_activating_a_dormant_account_consumes_a_slot(): void
    {
        $owner = $this->subscribedOwner(maxPaymentAccounts: 1);

        $this->action()->handle($this->data(), $owner);
        $dormant = $this->action()->handle($this->data(identifier: '0424-7654321', isActive: false), $owner);

        $this->assertSame(2, PaymentAccount::query()->count(), 'Una cuenta desactivada no ocupa sitio en el plan.');
        $this->assertSame(1, $this->counter());

        $this->expectException(PlanLimitReachedException::class);

        $this->action()->handle($this->data(identifier: '0424-7654321'), $owner, $dormant);
    }

    public function test_editing_an_active_account_does_not_ask_for_a_slot(): void
    {
        $owner = $this->subscribedOwner(maxPaymentAccounts: 1);

        $account = $this->action()->handle($this->data(), $owner);

        $updated = $this->action()->handle($this->data(holderName: 'Ana Pérez'), $owner, $account);

        $this->assertSame('Ana Pérez', $updated->holder_name);
        $this->assertSame(1, PaymentAccount::query()->count());
    }

    public function test_deleting_an_account_with_receipts_deactivates_it(): void
    {
        $owner = $this->subscribedOwner();
        $account = $this->action()->handle($this->data(), $owner);

        $this->withReceipts();

        try {
            $this->action()->delete($account, $owner);
            $this->fail('Borrar una cuenta con comprobantes debió informar de la degradación.');
        } catch (PaymentAccountInUseException $exception) {
            $this->assertStringContainsString('no se ha eliminado', $exception->getMessage());
            $this->assertStringContainsString('0414-1234567', $exception->getMessage());
        }

        $this->assertDatabaseHas('payment_accounts', [
            'id' => $account->getKey(),
            'is_active' => false,
        ]);

        $this->assertSame(0, $this->counter(), 'Una cuenta desactivada deja de ocupar sitio en el plan.');
    }

    public function test_deleting_an_account_without_receipts_removes_it(): void
    {
        $owner = $this->subscribedOwner();
        $account = $this->action()->handle($this->data(), $owner);

        $this->action()->delete($account, $owner);

        $this->assertDatabaseMissing('payment_accounts', ['id' => $account->getKey()]);
        $this->assertSame(0, $this->counter());
    }

    public function test_a_duplicate_account_is_warned_and_allowed(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(), $owner);
        $twin = $this->action()->handle($this->data(identifier: '04141234567'), $owner);

        $this->assertSame(2, PaymentAccount::query()->count(), 'Se permiten dos cuentas idénticas.');

        $warnings = $this->action()->warningsFor($twin);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('otra cuenta de Pago móvil', $warnings[0]);
    }

    public function test_deactivating_the_last_active_account_warns_without_unpublishing(): void
    {
        $owner = $this->subscribedOwner();

        $account = $this->action()->handle($this->data(), $owner);
        $account = $this->action()->handle($this->data(isActive: false), $owner, $account);

        $warnings = $this->action()->warningsFor($account);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('sin ninguna cuenta de cobro activa', $warnings[0]);
        $this->assertStringContainsString('siguen publicados', $warnings[0]);

        $this->assertFalse($account->refresh()->is_active);
    }

    public function test_position_orders_and_survives_an_edit(): void
    {
        $owner = $this->subscribedOwner();

        $first = $this->action()->handle($this->data(), $owner);
        $second = $this->action()->handle($this->data(identifier: '0424-7654321'), $owner);

        $this->assertLessThan($second->position, $first->position);

        $this->action()->handle($this->data(holderName: 'Ana Pérez'), $owner, $first);

        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            PaymentAccount::query()->orderBy('position')->orderBy('id')->pluck('id')->all(),
        );
    }

    private function action(): UpsertPaymentAccountAction
    {
        return app(UpsertPaymentAccountAction::class);
    }

    private function withReceipts(): void
    {
        $this->app->bind(RecordedReceipts::class, fn (): RecordedReceipts => new class extends RecordedReceipts
        {
            public function existFor(PaymentAccount $account): bool
            {
                return true;
            }
        });
    }

    private function data(
        PaymentMethod $method = PaymentMethod::PagoMovil,
        string $identifier = '0414-1234567',
        string $holderName = 'Carlos Rojas',
        bool $isActive = true,
    ): UpsertPaymentAccountData {
        return new UpsertPaymentAccountData(
            method: $method,
            bank: 'Banesco',
            holderName: $holderName,
            holderDocument: 'V-12345678',
            identifier: $identifier,
            instructions: null,
            isActive: $isActive,
        );
    }

    private function subscribedOwner(?int $maxPaymentAccounts = 5): User
    {
        $plan = Plan::factory()->create(['max_payment_accounts' => $maxPaymentAccounts]);

        Subscription::factory()->forPlan($plan)->create();

        return User::factory()->owner()->create();
    }

    private function counter(): int
    {
        return (int) UsageCounter::query()->where('metric', UsageMetric::PaymentAccounts)->value('value');
    }
}
