<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class NavigationCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_the_capabilities_travel_with_the_team_member(): void
    {
        $owner = $this->memberWith(OrganizationRole::Owner);

        $this->actingAs($owner)
            ->get('/backoffice')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teamMember.role', 'owner')
                ->has('teamMember.capabilities', 13));
    }

    public function test_a_member_only_receives_the_shared_half(): void
    {
        $member = $this->memberWith(OrganizationRole::Member);

        $response = $this->actingAs($member)->get('/backoffice')->assertOk();

        $capabilities = $this->capabilitiesIn($response);

        $this->assertCount(6, $capabilities);
        $this->assertNotContains('team', $capabilities);
        $this->assertNotContains('settings', $capabilities);
        $this->assertNotContains('subscription', $capabilities);
        $this->assertContains('collections', $capabilities);
        $this->assertContains('receipts', $capabilities);
    }

    public function test_the_shared_props_mirror_the_enum_exactly(): void
    {
        foreach ([OrganizationRole::Owner, OrganizationRole::Member] as $role) {
            $expected = array_map(
                fn (OrganizationCapability $capability): string => $capability->value,
                $role->capabilities(),
            );

            $this->app->make('auth')->forgetGuards();

            $response = $this->actingAs($this->memberWith($role))->get('/backoffice')->assertOk();

            $this->assertSame($expected, $this->capabilitiesIn($response), 'El rol '.$role->value.' no recibe lo que dice el enum.');
        }
    }

    public function test_every_section_of_the_menu_names_a_real_capability(): void
    {
        $layout = (string) file_get_contents(resource_path('js/layouts/backoffice/sections.ts'));

        preg_match_all("/capability: '([a-z_]+)'/", $layout, $matches);

        $declared = array_values(array_diff($matches[1], ['any']));

        $known = array_map(
            fn (OrganizationCapability $capability): string => $capability->value,
            OrganizationCapability::cases(),
        );

        $this->assertNotSame([], $declared, 'El menú no declara ninguna capacidad: ¿volvió el ownerOnly?');
        $this->assertSame([], array_values(array_diff($declared, $known)));
    }

    public function test_no_section_declares_its_role_by_hand(): void
    {
        foreach (['js/layouts/backoffice-layout.tsx', 'js/layouts/backoffice/sections.ts', 'js/layouts/backoffice/nav.tsx'] as $file) {
            $this->assertStringNotContainsString(
                'ownerOnly:',
                (string) file_get_contents(resource_path($file)),
                $file.' vuelve a declarar el rol a mano: la frontera es de OrganizationCapability.',
            );
        }
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return list<string>
     */
    private function capabilitiesIn(TestResponse $response): array
    {
        /** @var array{props: array{teamMember: array{capabilities: list<string>}}} $page */
        $page = $response->viewData('page');

        return $page['props']['teamMember']['capabilities'];
    }

    private function memberWith(OrganizationRole $role): User
    {
        $user = $this->withinOrganization(
            $this->organization,
            fn (): User => User::factory()->create(['role' => $role]),
        );

        $this->withoutOrganization();

        return $user;
    }
}
