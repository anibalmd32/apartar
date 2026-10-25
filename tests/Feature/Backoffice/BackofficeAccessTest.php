<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationNotActiveException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class BackofficeAccessTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_the_tenant_is_fixed_from_the_session_and_filters_every_query(): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'])
            ->get('__backoffice/equipo', fn (): array => [
                'organization' => app(TenantContext::class)->organizationId(),
                'users' => User::query()->count(),
            ]);

        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $actor = $this->withinOrganization($own, function (): User {
            User::factory()->create();

            return User::factory()->owner()->create();
        });

        $this->withinOrganization($neighbour, fn () => User::factory()->count(3)->create());
        $this->withoutOrganization();

        $this->actingAs($actor)
            ->getJson('/__backoffice/equipo')
            ->assertOk()
            ->assertJson([
                'organization' => $own->getKey(),
                'users' => 2,
            ]);
    }

    public function test_the_dashboard_renders_with_the_team_member_in_the_shared_props(): void
    {
        $actor = $this->teamMember();

        $this->actingAs($actor)
            ->get('/backoffice')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/Dashboard')
                ->where('teamMember.role', 'owner')
                ->where('teamMember.organization.status', 'active')
            );
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get('/backoffice')->assertRedirect(route('login'));
    }

    public function test_a_member_disabled_mid_session_loses_it_on_the_next_request(): void
    {
        $actor = $this->teamMember();

        $this->signIn($actor);
        $this->get('/backoffice')->assertOk();

        $this->withinOrganization(
            $actor->organization,
            fn () => User::query()->whereKey($actor->getKey())->update(['disabled_at' => now()]),
        );

        $this->nextRequest();

        $this->get('/backoffice')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_pending_password_change_hijacks_every_page(): void
    {
        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->get('/backoffice')->assertRedirect(route('password.change'));

        $this->get('/backoffice/cambiar-contrasena')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/ChangePassword'));
    }

    public function test_changing_the_password_releases_the_navigation(): void
    {
        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->post('/backoffice/cambiar-contrasena', [
            'password' => 'Zapatos-Del-Valle-2026',
            'password_confirmation' => 'Zapatos-Del-Valle-2026',
        ])->assertRedirect(route('dashboard'));

        $this->get('/backoffice')->assertOk();
    }

    public function test_a_compromised_password_is_refused_in_the_forced_change(): void
    {
        $this->fakePwnedPassword('Password123456');

        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->post('/backoffice/cambiar-contrasena', [
            'password' => 'Password123456',
            'password_confirmation' => 'Password123456',
        ])->assertSessionHasErrors('password');

        $this->assertPwnedServiceWasAsked('Password123456');
    }

    public function test_logging_out_still_works_with_a_pending_password_change(): void
    {
        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->post('/backoffice/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_closed_organization_ends_the_open_session(): void
    {
        $actor = $this->teamMember();

        $this->signIn($actor);
        $this->get('/backoffice')->assertOk();

        $actor->organization->update(['status' => OrganizationStatus::Closed]);

        $this->nextRequest();

        $this->get('/backoffice')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => OrganizationNotActiveException::CLOSED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_an_expired_inertia_request_gets_a_409_to_the_login(): void
    {
        Route::middleware('web')->post('__backoffice/caducada', fn () => abort(419));

        $response = $this->withHeader('X-Inertia', 'true')->post('/__backoffice/caducada');

        $response->assertStatus(409);
        $this->assertSame(route('login'), $response->headers->get('X-Inertia-Location'));
    }

    public function test_a_plain_request_keeps_its_own_status(): void
    {
        Route::middleware('web')->post('__backoffice/caducada-normal', fn () => abort(419));

        $this->post('/__backoffice/caducada-normal')->assertStatus(419);
    }

    private function signIn(User $user): void
    {
        $this->post('/backoffice/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $organizationState
     */
    private function teamMember(array $state = [], array $organizationState = []): User
    {
        $organization = Organization::factory()->create($organizationState);
        $this->neighbourOrganization();

        $user = $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->owner()->create($state),
        );

        $this->withoutOrganization();

        return $user;
    }
}
