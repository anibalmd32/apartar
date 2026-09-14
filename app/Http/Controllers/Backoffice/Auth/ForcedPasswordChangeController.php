<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cambio de contraseña obligatorio (`REQ-BOF-001` RB-5 y SP-7).
 *
 * Es la única pantalla a la que lleva `EnsurePasswordIsChanged` mientras
 * `must_change_password` esté puesto. Se separa de los ajustes de perfil
 * porque las dos pantallas no se parecen en lo que importa: aquí **no se pide
 * la contraseña actual**. Quien llega suele tener una que le acaban de
 * asignar, o una que se ha decidido retirar, y exigirle recordar la vieja para
 * poder cambiarla dejaría fuera justo a quien hay que dejar entrar.
 */
final class ForcedPasswordChangeController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('backoffice/auth/ChangePassword');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            // RB-6: la misma regla en todas las puertas del producto.
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->uncompromised()],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'password' => (string) $request->string('password'),
            'must_change_password' => false,
        ])->save();

        // La sesión se regenera igual que al entrar: si la contraseña se
        // cambia porque la anterior pudo quedar expuesta, la sesión abierta
        // con ella tampoco merece confianza.
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Contraseña actualizada.');
    }
}
