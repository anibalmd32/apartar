<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TeamInvitation;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MultiOrganizationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const EMAIL = 'mariela@ejemplo.test';

    public function test_the_login_enters_the_last_business_used(): void
    {
        [$first, $second] = $this->twoMemberships();
        $second->forceFill(['last_login_at' => CarbonImmutable::now()->subDay()])->saveQuietly();

        $this->post('/backoffice/login', ['email' => self::EMAIL, 'password' => 'password'])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($second);
        $this->assertNotSame($first->getKey(), auth()->id());
    }

    public function test_the_switcher_lists_and_switches_only_to_own_businesses(): void
    {
        [$first, $second] = $this->twoMemberships();
        $stranger = $this->subscribe(Organization::factory()->create());
        $this->withinOrganization($stranger, fn () => User::factory()->owner()->create(['email' => 'otra@ejemplo.test']));
        $this->withoutOrganization();

        $this->actingAs($first)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('teamMember.organizations', 2)
            ->where('teamMember.organizations.0.current', true)
            ->where('teamMember.organizations.1.role', 'member'));

        $this->actingAs($first)->post(route('organizations.switch', $second->organization))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($second);
        $this->assertNotNull($second->refresh()->last_login_at);

        $this->actingAs($first)->post(route('organizations.switch', $stranger))->assertNotFound();
    }

    public function test_a_disabled_membership_or_a_closed_business_is_not_offered(): void
    {
        [$first, $second] = $this->twoMemberships();
        $second->forceFill(['disabled_at' => now()])->saveQuietly();

        $this->actingAs($first)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('teamMember.organizations', []));
        $this->actingAs($first)->post(route('organizations.switch', $second->organization))->assertNotFound();

        $second->forceFill(['disabled_at' => null])->saveQuietly();
        $second->organization->forceFill(['status' => OrganizationStatus::Closed])->save();
        $this->actingAs($first)->post(route('organizations.switch', $second->organization))->assertNotFound();
    }

    public function test_an_invitation_for_someone_with_an_account_is_accepted_with_their_password(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $this->withinOrganization($organization, fn () => User::factory()->owner()->create(['email' => self::EMAIL]));
        $other = $this->subscribe(Organization::factory()->create());
        $invitation = TeamInvitation::issueFor($other);
        $invited = $this->withinOrganization($other, fn (): User => User::factory()->invited($invitation)->create(['email' => self::EMAIL, 'role' => OrganizationRole::Member]));
        $this->withoutOrganization();

        $this->get('/invitacion/'.$invitation->plainToken)->assertInertia(fn (AssertableInertia $page) => $page->where('hasAccount', true));

        $this->post('/invitacion/'.$invitation->plainToken, ['password' => 'otra-cosa'])->assertSessionHasErrors(['password' => 'Esa no es tu contraseña de Apartar.']);
        $this->assertNull($invited->refresh()->password);

        $this->post('/invitacion/'.$invitation->plainToken, ['password' => 'password'])->assertRedirect();
        $this->assertTrue(Hash::check('password', (string) $invited->refresh()->password));
        $this->assertAuthenticatedAs($invited);
    }

    public function test_changing_the_password_changes_it_in_every_business(): void
    {
        [$first, $second] = $this->twoMemberships();
        $first->forceFill(['must_change_password' => true])->saveQuietly();

        $this->actingAs($first)->post(route('password.change.store'), [
            'password' => 'Nueva-Contrasena-2026',
            'password_confirmation' => 'Nueva-Contrasena-2026',
        ]);

        $this->assertTrue(Hash::check('Nueva-Contrasena-2026', (string) $second->refresh()->password));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function twoMemberships(): array
    {
        $one = $this->subscribe(Organization::factory()->create(['name' => 'Boutique Mariela']));
        $two = $this->subscribe(Organization::factory()->create(['name' => 'Zapatería Luz']));
        $first = $this->withinOrganization($one, fn (): User => User::factory()->owner()->create(['email' => self::EMAIL]));
        $second = $this->withinOrganization($two, fn (): User => User::factory()->create(['email' => self::EMAIL, 'role' => OrganizationRole::Member]));
        $this->withoutOrganization();

        return [$first, $second];
    }
}
