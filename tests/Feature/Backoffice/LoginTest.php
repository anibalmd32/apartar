<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationNotActiveException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_renders_without_asking_for_an_organization(): void
    {
        $this->get('/backoffice/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/Login'));
    }

    public function test_a_team_member_signs_in_and_lands_in_the_backoffice(): void
    {
        $user = $this->teamMember();

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(User::withoutTenantScope()->find($user->getKey())?->last_login_at);
    }

    public function test_it_never_asks_which_organization(): void
    {
        $this->get('/backoffice/login')
            ->assertOk()
            ->assertDontSee('organización', escape: false)
            ->assertDontSee('organization_id');
    }

    public function test_the_message_is_the_same_whatever_failed(): void
    {
        $user = $this->teamMember();

        $generic = (string) __('auth.failed');

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'otra-cosa-larga-123',
        ])->assertSessionHasErrors(['email' => $generic]);

        $this->flushSession();

        $this->post('/backoffice/login', [
            'email' => 'nadie@ejemplo.test',
            'password' => 'otra-cosa-larga-123',
        ])->assertSessionHasErrors(['email' => $generic]);

        $this->assertGuest();
    }

    public function test_the_sixth_attempt_is_throttled_with_retry_after(): void
    {
        $user = $this->teamMember();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/backoffice/login', [
                'email' => $user->email,
                'password' => 'la-equivocada-123',
            ])->assertSessionHasErrors('email');
        }

        $response = $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'la-equivocada-123',
        ]);

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_the_lockout_does_not_reach_the_person_at_the_next_desk(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        [$locked, $colleague] = $this->withinOrganization($organization, fn (): array => [
            User::factory()->create(['email' => 'bloqueada@ejemplo.test']),
            User::factory()->create(['email' => 'compa@ejemplo.test']),
        ]);
        $this->withoutOrganization();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/backoffice/login', ['email' => $locked->email, 'password' => 'mal-123']);
        }

        $this->flushSession();

        $this->post('/backoffice/login', [
            'email' => $colleague->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_a_failed_attempt_is_logged_without_the_password(): void
    {
        $logger = Log::spy();

        $this->post('/backoffice/login', [
            'email' => 'ana@ejemplo.test',
            'password' => 'secreto-larguisimo-123',
        ]);

        $logger->shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'backoffice.login.failed'
                    && $context['email'] === 'ana@ejemplo.test'
                    && array_key_exists('ip', $context)
                    && ! in_array('secreto-larguisimo-123', $context, true)
                    && ! array_key_exists('password', $context);
            });
    }

    public function test_a_disabled_member_cannot_sign_in(): void
    {
        $user = $this->teamMember(['disabled_at' => now()]);

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_closed_organization_rejects_the_login_with_the_reason(): void
    {
        $user = $this->teamMember(organizationState: ['status' => OrganizationStatus::Closed]);

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => OrganizationNotActiveException::CLOSED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_suspended_organization_still_lets_its_team_in(): void
    {
        $user = $this->teamMember(organizationState: [
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_platform_user_cannot_sign_in_here(): void
    {
        DB::table('platform_users')->insert([
            'name' => 'Operadora',
            'email' => 'operadora@plataforma.test',
            'password' => Hash::make('ContraseñaLarga123'),
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/backoffice/login', [
            'email' => 'operadora@plataforma.test',
            'password' => 'ContraseñaLarga123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_session_is_regenerated_on_the_way_in(): void
    {
        $user = $this->teamMember();

        $this->startSession();
        $before = session()->getId();

        $this->post('/backoffice/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotSame($before, session()->getId(), 'La sesión no se regeneró al autenticar.');
    }

    public function test_logging_out_invalidates_the_session(): void
    {
        $user = $this->teamMember();

        $this->actingAs($user);

        $this->post('/backoffice/logout')->assertRedirect(route('login'));

        $this->assertGuest();
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
