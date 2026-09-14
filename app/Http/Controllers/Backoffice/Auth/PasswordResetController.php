<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recuperación de contraseña del equipo (`REQ-BOF-001` RB-12, SP-10).
 *
 * Existe porque en un SaaS self-serve **no hay a quién pedírselo**: no hay un
 * administrador de la plataforma al que llamar para que restablezca la
 * contraseña de la dueña de una ferretería.
 *
 * Enlace firmado de **un solo uso** y **60 minutos** de vida: el token se
 * borra al consumirse —`Password::reset()` lo hace— y `config('auth.passwords.
 * users.expire')` fija el plazo. Los dos límites van juntos a propósito: un
 * enlace eterno en una bandeja de entrada es una llave escondida bajo el
 * felpudo.
 *
 * El aviso de envío es **siempre el mismo**, exista el correo o no. Es la
 * misma razón que el mensaje genérico del login (SP-1): decir «ese correo no
 * está registrado» convertiría este formulario en el oráculo que el login se
 * cuida de no ser.
 */
final class PasswordResetController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('backoffice/auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        Password::sendResetLink(['email' => Str::lower((string) $request->string('email'))]);

        return back()->with('status', 'Si ese correo tiene una cuenta de equipo, le enviamos un enlace para entrar.');
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('backoffice/auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->string('email'),
        ]);
    }

    /**
     * @throws ValidationException si el enlace caducó, ya se usó, o la
     *                             contraseña no cumple RB-6
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            // RB-6, la misma regla que el registro: doce caracteres,
            // mayúsculas y minúsculas, números y **no comprometida**. Una
            // contraseña larga que ya está en una filtración no es fuerte.
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->uncompromised()],
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower((string) $request->string('email')),
                'password' => (string) $request->string('password'),
                'password_confirmation' => (string) $request->string('password_confirmation'),
                'token' => (string) $request->string('token'),
            ],
            function ($user, string $password): void {
                // `must_change_password` se apaga aquí: quien acaba de elegir
                // una contraseña ya ha hecho lo que se le pedía, y volver a
                // exigírselo en la siguiente pantalla sería un bucle.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // SP-10 · caducado o ya usado. El mensaje lleva la salida —pedir
            // otro— porque quien lo lee no hizo nada mal: tardó.
            throw ValidationException::withMessages([
                'email' => 'Ese enlace ya no sirve: caducó o se usó. Pide uno nuevo.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Contraseña cambiada: ya puedes entrar.');
    }
}
