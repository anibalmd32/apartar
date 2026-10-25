<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice\Auth;

use App\Auth\TeamMemberships;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
     * @throws ValidationException
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
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
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();

                if ($user instanceof User) {
                    app(TeamMemberships::class)->syncPassword($user);
                }

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Ese enlace ya no sirve: caducó o se usó. Pide uno nuevo.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Contraseña cambiada: ya puedes entrar.');
    }
}
