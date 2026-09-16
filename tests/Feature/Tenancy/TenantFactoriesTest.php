<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CreatesWithinTenant`: cómo siembran las factories de modelos del tenant.
 *
 * El trait de `T-005` exige un tenant fijado para crear, así que sin esta
 * pieza ninguna factory podría persistir nada. Lo que se fija aquí es el orden
 * de resolución, porque de él depende que las tres guardas sigan mordiendo:
 * gana el contexto activo, luego la organización que traiga el modelo, y solo
 * si no hay ninguna se crea una nueva.
 */
final class TenantFactoriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El caso que mantiene en pie el andamiaje heredado: un test que solo
     * necesita "un usuario" no sabe nada de tenancy y sigue funcionando.
     */
    public function test_a_user_factory_without_context_creates_its_own_organization(): void
    {
        $this->withoutOrganization();

        $user = User::factory()->create();

        $this->assertSame((int) Organization::query()->sole()->getKey(), $user->organization_id);
    }

    public function test_a_payment_account_factory_without_context_creates_its_own_organization(): void
    {
        $this->withoutOrganization();

        $account = PaymentAccount::factory()->create();

        $this->assertSame((int) Organization::query()->sole()->getKey(), $account->organization_id);
    }

    /**
     * Dentro de un contexto, la factory siembra ahí y no inventa una
     * organización nueva.
     */
    public function test_a_factory_inside_a_context_seeds_into_it(): void
    {
        $organization = Organization::factory()->create();

        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->assertSame((int) $organization->getKey(), $user->organization_id);
        $this->assertSame(1, Organization::query()->count());
    }

    /**
     * Varios usuarios de una tirada caen todos en la misma organización: sin
     * esto, `count(3)` produciría tres negocios distintos.
     */
    public function test_many_users_land_in_the_same_organization(): void
    {
        $this->withoutOrganization();

        $users = User::factory()->count(3)->create();

        $this->assertCount(1, $users->pluck('organization_id')->unique());
        $this->assertSame(1, Organization::query()->count());
    }

    /**
     * La factory no deja el contexto tocado: `runFor()` restaura lo que había.
     */
    public function test_seeding_does_not_leak_the_tenant_into_the_test(): void
    {
        $this->withoutOrganization();

        User::factory()->create();

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    public function test_seeding_inside_a_context_leaves_it_untouched(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsOrganization($organization);

        User::factory()->create();

        $this->assertSame((int) $organization->getKey(), app(TenantContext::class)->organizationId());
    }

    /**
     * El rol por defecto es el de menos capacidades: un test que necesite un
     * `owner` tiene que pedirlo, y así ninguno pasa por accidente ejerciendo
     * permisos que no había declarado.
     */
    public function test_the_default_role_is_member(): void
    {
        $this->withoutOrganization();

        $this->assertSame(OrganizationRole::Member, User::factory()->create()->role);
        $this->assertSame(OrganizationRole::Owner, User::factory()->owner()->create()->role);
    }

    /**
     * `REQ-ORG-004` RB-3: el invitado existe sin contraseña utilizable.
     */
    public function test_an_invited_user_has_no_password_yet(): void
    {
        $this->withoutOrganization();

        $user = User::factory()->invited()->create();

        $this->assertNull($user->password);
        $this->assertNotNull($user->invited_at);
        $this->assertNull($user->email_verified_at);
    }

    public function test_a_disabled_user_keeps_its_row(): void
    {
        $this->withoutOrganization();

        $this->assertNotNull(User::factory()->disabled()->create()->disabled_at);
    }

    public function test_a_user_can_be_forced_to_rotate_its_password(): void
    {
        $this->withoutOrganization();

        $this->assertTrue(User::factory()->mustChangePassword()->create()->must_change_password);
    }

    /**
     * Cada método de cobro cambia su `identifier` a la vez: por separado
     * producirían una cuenta que `ValidPaymentIdentifier` rechazaría.
     *
     * Desde `T-015` la comparación es contra el enum y no contra la cadena: la
     * factory sigue sembrando el valor de la columna, y quien lo lee recibe el
     * `PaymentMethod` del cast.
     */
    public function test_payment_account_states_keep_method_and_identifier_together(): void
    {
        $this->withoutOrganization();
        $organization = Organization::factory()->create();

        $accounts = $this->withinOrganization($organization, fn (): array => [
            PaymentAccount::factory()->create(),
            PaymentAccount::factory()->transferencia()->create(),
            PaymentAccount::factory()->zelle()->create(),
            PaymentAccount::factory()->efectivo()->create(),
        ]);

        [$movil, $transferencia, $zelle, $efectivo] = $accounts;

        $this->assertSame(PaymentMethod::PagoMovil, $movil->method);
        $this->assertStringStartsWith('0414', $movil->identifier);

        $this->assertSame(PaymentMethod::Transferencia, $transferencia->method);
        $this->assertSame(PaymentMethod::Zelle, $zelle->method);
        $this->assertStringContainsString('@', $zelle->identifier);
        $this->assertNull($zelle->bank);

        $this->assertSame(PaymentMethod::EfectivoUsd, $efectivo->method);
    }

    public function test_an_inactive_payment_account_keeps_its_trail(): void
    {
        $this->withoutOrganization();

        $this->assertFalse(PaymentAccount::factory()->inactive()->create()->is_active);
    }
}
