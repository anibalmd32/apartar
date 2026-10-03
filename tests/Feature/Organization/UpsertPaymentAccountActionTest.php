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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dónde le pagan al negocio (`REQ-ORG-007` RB-3, RB-5, RB-6, SP-2, SP-3, SP-4,
 * SP-6).
 *
 * Lo que se comprueba aquí es lo que la pantalla no puede: que el techo del
 * plan se aplica **con el enforcer y no contando filas**, que borrar una cuenta
 * con comprobantes no borra nada, y que las dos situaciones que SP-4 y SP-6
 * declaran legítimas **se permiten** — que es tan importante como que las
 * ilegítimas se rechacen—.
 */
final class UpsertPaymentAccountActionTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * SP-2 · un `member` no gobierna dónde va el dinero. Es la capacidad más
     * sensible del `owner` y por eso no vive con la cobranza: un empleado
     * registra pagos, pero no decide a qué cuenta llegan.
     */
    public function test_a_member_cannot_declare_a_payment_account(): void
    {
        $this->subscribedOwner();
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($this->data(), $member);
    }

    /**
     * **RB-5 · el techo del plan lo aplica `LimitEnforcer`, no esta acción.**
     *
     * Es la segunda vez que se aplica un límite y la razón exacta por la que
     * `T-013` adelantó el enforcer a H0: contar filas aquí habría repetido el
     * apaño que allí se evitó.
     */
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

    /**
     * Y el contador se mueve **en la misma transacción que escribe**
     * (`REQ-SUB-003` RB-3): quien mire la pantalla un instante después ve la
     * cifra de ahora, no la de antes de guardar.
     */
    public function test_the_usage_counter_moves_with_the_write(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(), $owner);

        $this->assertSame(1, $this->counter());
    }

    /**
     * **Crear una cuenta desactivada no gasta cupo, y activarla sí.**
     *
     * La métrica cuenta las **activas**, así que la simetría es la misma que la
     * de `EnableTeamMemberAction`: sin ella, desactivar y reactivar sería la
     * forma de rebasar cualquier plan sin pagarlo.
     */
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

    /**
     * Editar una cuenta que ya está activa **no vuelve a pedir cupo**: cambiar
     * el titular no es añadir nada, y pedirlo daría un `422` a quien no está
     * creando.
     */
    public function test_editing_an_active_account_does_not_ask_for_a_slot(): void
    {
        $owner = $this->subscribedOwner(maxPaymentAccounts: 1);

        $account = $this->action()->handle($this->data(), $owner);

        $updated = $this->action()->handle($this->data(holderName: 'Ana Pérez'), $owner, $account);

        $this->assertSame('Ana Pérez', $updated->holder_name);
        $this->assertSame(1, PaymentAccount::query()->count());
    }

    /**
     * **RB-6 y SP-3 · borrar una cuenta con comprobantes la desactiva.**
     *
     * Se ejercita sustituyendo `RecordedReceipts` en el contenedor, como desde
     * `T-015`, antes de que existiera `receipts`; la consulta de verdad la
     * prueba `RecordedReceiptsTest` desde `T-054`. Y la excepción **no es un
     * fallo**: la operación se completó de otra forma, y SP-3 exige informarlo.
     */
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

        // El aviso llega después de que la desactivación esté confirmada:
        // lanzar dentro de la transacción la habría deshecho.
        $this->assertSame(0, $this->counter(), 'Una cuenta desactivada deja de ocupar sitio en el plan.');
    }

    /**
     * Y sin comprobantes, borrar borra: conservar filas que nadie referencia
     * sería confundir trazabilidad con no tirar nada.
     */
    public function test_deleting_an_account_without_receipts_removes_it(): void
    {
        $owner = $this->subscribedOwner();
        $account = $this->action()->handle($this->data(), $owner);

        $this->action()->delete($account, $owner);

        $this->assertDatabaseMissing('payment_accounts', ['id' => $account->getKey()]);
        $this->assertSame(0, $this->counter());
    }

    /**
     * **SP-6 · dos cuentas idénticas se avisan y se permiten.** Hay negocios
     * con dos cuentas en el mismo banco; bloquearlo sería imponer una regla que
     * el mundo real no cumple.
     *
     * El aviso compara el identificador **reducido**: quien escribe
     * `04141234567` teniendo guardado `0414-1234567` sí está duplicando.
     */
    public function test_a_duplicate_account_is_warned_and_allowed(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(), $owner);
        $twin = $this->action()->handle($this->data(identifier: '04141234567'), $owner);

        $this->assertSame(2, PaymentAccount::query()->count(), 'SP-6 permite dos cuentas idénticas.');

        $warnings = $this->action()->warningsFor($twin);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('otra cuenta de Pago móvil', $warnings[0]);
    }

    /**
     * **SP-4 · desactivar la última activa se permite, con advertencia, y no
     * despublica nada.**
     *
     * Un ajuste rutinario de configuración que tumbara la vitrina del negocio
     * sería un castigo desproporcionado: se avisa de que los clientes no verán
     * dónde pagar y se deja decidir.
     */
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

    /**
     * Una cuenta nueva va **al final** y guardar una existente **no la mueve**:
     * editar el titular no puede cambiar de sitio la cuenta que los clientes
     * llevan meses viendo la primera.
     */
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

    // -------------------------------------------------------------------

    private function action(): UpsertPaymentAccountAction
    {
        return app(UpsertPaymentAccountAction::class);
    }

    /**
     * Sustituye la consulta de comprobantes por una que dice que sí los hay.
     *
     * Sin sembrar un comprobante con su apartado, su ficha y su archivo: lo que
     * aquí se prueba es la degradación del borrado, no la consulta.
     */
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

    /**
     * El consumo materializado de `payment_accounts`.
     */
    private function counter(): int
    {
        return (int) UsageCounter::query()->where('metric', UsageMetric::PaymentAccounts)->value('value');
    }
}
