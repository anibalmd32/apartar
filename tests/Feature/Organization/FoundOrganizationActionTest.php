<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\FoundOrganizationAction;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\OwnedOrganizationLimitException;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class FoundOrganizationActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const EMAIL = 'mariela@ejemplo.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_a_team_member_founds_their_own_business_with_the_same_password(): void
    {
        $member = $this->membership(OrganizationRole::Member);

        $organization = app(FoundOrganizationAction::class)->handle($member, 'Zapatería Luz', '');

        $owner = User::withoutTenantScope()->where('organization_id', $organization->getKey())->sole();

        $this->assertSame('zapateria-luz', $organization->slug);
        $this->assertSame(OrganizationRole::Owner, $owner->role);
        $this->assertSame(self::EMAIL, $owner->email);
        $this->assertSame($member->name, $owner->name);
        $this->assertTrue(Hash::check('password', (string) $owner->password));
        $this->assertTrue(Auth::guard('web')->user()?->is($owner));
    }

    public function test_someone_who_already_owns_a_business_cannot_found_another(): void
    {
        $owner = $this->membership(OrganizationRole::Owner);

        $this->expectException(OwnedOrganizationLimitException::class);

        try {
            app(FoundOrganizationAction::class)->handle($owner, 'Zapatería Luz', '');
        } finally {
            $this->assertFalse(Organization::query()->where('slug', 'zapateria-luz')->exists());
        }
    }

    public function test_a_closed_business_does_not_count(): void
    {
        $owner = $this->membership(OrganizationRole::Owner, OrganizationStatus::Closed);

        $organization = app(FoundOrganizationAction::class)->handle($owner, 'Zapatería Luz', 'luz');

        $this->assertSame('luz', $organization->slug);
    }

    public function test_the_limit_comes_from_the_configuration(): void
    {
        config(['platform.max_owned_organizations' => 2]);
        $owner = $this->membership(OrganizationRole::Owner);

        app(FoundOrganizationAction::class)->handle($owner, 'Zapatería Luz', '');

        $this->expectException(OwnedOrganizationLimitException::class);

        app(FoundOrganizationAction::class)->handle($owner, 'Tercera', '');
    }

    private function membership(OrganizationRole $role, OrganizationStatus $status = OrganizationStatus::Active): User
    {
        $organization = Organization::factory()->create(['status' => $status]);
        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create(['email' => self::EMAIL, 'role' => $role]));
        $this->withoutOrganization();

        return $user;
    }
}
