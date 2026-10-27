<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class FoundOrganizationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_a_team_member_sees_the_option_and_the_form(): void
    {
        $member = $this->membership(OrganizationRole::Member);

        $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teamMember.foundOrganizationUrl', route('organizations.create')));

        $this->actingAs($member)->get(route('organizations.create'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/organizations/Create'));
    }

    public function test_an_owner_does_not_see_the_option_and_is_turned_back(): void
    {
        $owner = $this->membership(OrganizationRole::Owner);

        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teamMember.foundOrganizationUrl', null));

        $this->actingAs($owner)->get(route('organizations.create'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Ya tienes un negocio propio. Por ahora cada persona puede tener uno solo.');

        $this->actingAs($owner)->post(route('organizations.store'), ['organization_name' => 'Otra', 'organization_slug' => ''])
            ->assertSessionHasErrors(['organization_name' => 'Ya tienes un negocio propio. Por ahora cada persona puede tener uno solo.']);
    }

    public function test_founding_enters_the_new_business(): void
    {
        $member = $this->membership(OrganizationRole::Member);

        $this->actingAs($member)
            ->post(route('organizations.store'), ['organization_name' => 'Zapatería Luz', 'organization_slug' => ''])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Creaste Zapatería Luz. Estás en tu negocio nuevo.');

        $organization = Organization::query()->where('slug', 'zapateria-luz')->sole();
        $owner = auth()->user();

        $this->assertInstanceOf(User::class, $owner);
        $this->assertSame((int) $organization->getKey(), $owner->organization_id);
        $this->assertNotNull($owner->last_login_at);
    }

    public function test_a_taken_link_is_answered_on_the_field(): void
    {
        $member = $this->membership(OrganizationRole::Member);
        Organization::factory()->create(['slug' => 'luz']);

        $this->actingAs($member)->getJson(route('organizations.slug', ['slug' => 'luz']))->assertJson(['slug' => 'luz', 'available' => false]);

        $this->actingAs($member)
            ->post(route('organizations.store'), ['organization_name' => 'Luz', 'organization_slug' => 'luz'])
            ->assertSessionHasErrors('organization_slug');
    }

    private function membership(OrganizationRole $role): User
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create(['email' => 'mariela@ejemplo.test', 'role' => $role]));
        $this->withoutOrganization();

        return $user;
    }
}
