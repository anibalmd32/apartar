<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\ChangePasswordAction;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ChangePasswordActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_the_new_password_works_in_every_business_and_lifts_the_forced_change(): void
    {
        $one = $this->subscribe(Organization::factory()->create());
        $two = $this->subscribe(Organization::factory()->create());
        $first = $this->withinOrganization($one, fn (): User => User::factory()->owner()->create(['email' => 'mariela@ejemplo.test', 'must_change_password' => true]));
        $second = $this->withinOrganization($two, fn (): User => User::factory()->create(['email' => 'mariela@ejemplo.test', 'role' => OrganizationRole::Member]));
        $stranger = $this->withinOrganization($two, fn (): User => User::factory()->create(['email' => 'ana@ejemplo.test']));
        $this->withoutOrganization();

        app(ChangePasswordAction::class)->handle($first, 'Cl4ve-Segura-Nueva');

        $this->assertTrue(Hash::check('Cl4ve-Segura-Nueva', $first->refresh()->password));
        $this->assertFalse($first->must_change_password);
        $this->assertTrue(Hash::check('Cl4ve-Segura-Nueva', User::withoutTenantScope()->findOrFail($second->getKey())->password));
        $this->assertTrue(Hash::check('password', User::withoutTenantScope()->findOrFail($stranger->getKey())->password));
    }
}
