<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Recuperación de contraseña del equipo (`REQ-BOF-001` RB-12 y SP-10).
 *
 * Existe porque en un SaaS self-serve no hay a quién pedírselo: nadie va a
 * llamar a un administrador de la plataforma para que le restablezca la
 * contraseña a la dueña de una ferretería.
 */
final class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Y el aviso es **el mismo** con un correo que no existe: decir «ese correo
     * no está registrado» convertiría este formulario en el oráculo que el
     * login se cuida de no ser (SP-1).
     */
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

        // Y solo se envió uno: el aviso es el mismo, el correo no.
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

    /**
     * SP-10 · **de un solo uso**. El segundo intento con el mismo enlace no
     * cambia nada y ofrece pedir otro: quien lo sufre no hizo nada mal,
     * recargó.
     */
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

    /**
     * Un enlace caducado se comporta igual que uno usado. El plazo es de
     * **60 minutos** y vive en la configuración del broker, no en un número
     * suelto por el código.
     */
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

    /**
     * RB-6 · la contraseña nueva cumple la misma regla que en todas las
     * puertas del producto, incluida la de filtraciones.
     */
    public function test_a_compromised_password_is_refused(): void
    {
        $user = $this->teamMember();
        $token = $this->issueToken($user);

        $this->post('/backoffice/restablecer', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Password123456',
            'password_confirmation' => 'Password123456',
        ])->assertSessionHasErrors('password');
    }

    /**
     * Y al restablecer se apaga `must_change_password`: quien acaba de elegir
     * una contraseña ya hizo lo que se le pedía, y volver a exigírselo en la
     * pantalla siguiente sería un bucle.
     */
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
