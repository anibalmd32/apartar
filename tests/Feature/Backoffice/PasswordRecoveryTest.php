<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PasswordRecoveryTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_the_request_page_renders(): void
    {
        $this->get('/backoffice/recuperar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/ForgotPassword'));
    }

    public function test_it_sends_the_link_to_a_team_member(): void
    {
        Notification::fake();

        $user = $this->teamMember();

        $this->post('/backoffice/recuperar', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_answer_does_not_reveal_whether_the_email_exists(): void
    {
        Notification::fake();

        $user = $this->teamMember();

        $this->post('/backoffice/recuperar', ['email' => $user->email]);
        $known = (string) session('status');

        $this->post('/backoffice/recuperar', ['email' => 'nadie@ejemplo.test']);
        $unknown = (string) session('status');

        $this->assertNotSame('', $known);
        $this->assertSame($known, $unknown);

        Notification::assertCount(1);
    }

    public function test_the_link_lets_the_member_choose_a_new_password(): void
    {
        $user = $this->teamMember();
        $token = $this->issueToken($user);

        $this->get("/backoffice/restablecer/{$token}?email=".urlencode($user->email))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/auth/ResetPassword')
                ->where('token', $token)
            );

        $this->post('/backoffice/restablecer', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Zapatos-Del-Valle-2026',
            'password_confirmation' => 'Zapatos-Del-Valle-2026',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('Zapatos-Del-Valle-2026', (string) $user->refresh()->password));
    }

    public function test_the_link_works_only_once(): void
    {
        $user = $this->teamMember();
        $token = $this->issueToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Zapatos-Del-Valle-2026',
            'password_confirmation' => 'Zapatos-Del-Valle-2026',
        ];

        $this->post('/backoffice/restablecer', $payload)->assertRedirect(route('login'));

        $this->post('/backoffice/restablecer', [
            ...$payload,
            'password' => 'Sillas-Del-Norte-2026',
            'password_confirmation' => 'Sillas-Del-Norte-2026',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(
            Hash::check('Zapatos-Del-Valle-2026', (string) $user->refresh()->password),
            'El segundo uso del enlace cambió la contraseña.',
        );
    }

    public function test_an_expired_link_is_refused(): void
    {
        $this->assertSame(60, config('auth.passwords.users.expire'));

        $user = $this->teamMember();
        $token = $this->issueToken($user);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->post('/backoffice/restablecer', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Zapatos-Del-Valle-2026',
            'password_confirmation' => 'Zapatos-Del-Valle-2026',
        ])->assertSessionHasErrors('email');
    }

    public function test_a_compromised_password_is_refused(): void
    {
        $this->fakePwnedPassword('Password123456');

        $user = $this->teamMember();
        $token = $this->issueToken($user);

        $this->post('/backoffice/restablecer', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Password123456',
            'password_confirmation' => 'Password123456',
        ])->assertSessionHasErrors('password');

        $this->assertPwnedServiceWasAsked('Password123456');
    }

    public function test_resetting_clears_a_pending_forced_change(): void
    {
        $user = $this->teamMember(['must_change_password' => true]);
        $token = $this->issueToken($user);

        $this->post('/backoffice/restablecer', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Zapatos-Del-Valle-2026',
            'password_confirmation' => 'Zapatos-Del-Valle-2026',
        ])->assertRedirect(route('login'));

        $this->assertFalse($user->refresh()->must_change_password);
    }

    private function issueToken(User $user): string
    {
        return app('auth.password.broker')->createToken($user);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function teamMember(array $state = []): User
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $user = $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->owner()->create($state),
        );

        $this->withoutOrganization();

        return $user;
    }
}
