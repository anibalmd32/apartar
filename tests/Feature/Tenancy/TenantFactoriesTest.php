<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\User;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TenantFactoriesTest extends TestCase
{
    use ReusesMigratedDatabase;

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

    public function test_a_factory_inside_a_context_seeds_into_it(): void
    {
        $organization = Organization::factory()->create();

        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->assertSame((int) $organization->getKey(), $user->organization_id);
        $this->assertSame(1, Organization::query()->count());
    }

    public function test_many_users_land_in_the_same_organization(): void
    {
        $this->withoutOrganization();

        $users = User::factory()->count(3)->create();

        $this->assertCount(1, $users->pluck('organization_id')->unique());
        $this->assertSame(1, Organization::query()->count());
    }

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

    public function test_the_default_role_is_member(): void
    {
        $this->withoutOrganization();

        $this->assertSame(OrganizationRole::Member, User::factory()->create()->role);
        $this->assertSame(OrganizationRole::Owner, User::factory()->owner()->create()->role);
    }

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
