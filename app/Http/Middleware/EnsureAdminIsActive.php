<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\AdminDisabledException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta la sesión de un miembro desactivado (`REQ-BOF-001` RB-4, SP-3).
 *
 * **En la siguiente petición, no en el siguiente inicio de sesión.** Es la
 * misma idea que hace que el rol se lea de la base de datos en cada petición
 * (`REQ-ORG-003` RB-6): una revocación que espera a que a la persona le
 * apetezca volver a entrar no es una revocación. Quien acaba de dejar el
 * equipo tiene la pestaña abierta, y es exactamente entonces cuando importa.
 *
 * Se comprueba la columna del usuario que trae el guard, que la sesión vuelve
 * a leer de `users` en cada petición; no hay ninguna copia del estado en la
 * sesión que pudiera quedarse rancia.
 */
final class EnsureAdminIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->disabled_at !== null) {
            return $this->endSession($request);
        }

        return $next($request);
    }

    /**
     * Cierra la sesión y devuelve al acceso con el motivo.
     *
     * Se invalida la sesión entera y se rota el token, igual que hace
     * `ResolveTenant` cuando no puede resolver el tenant: conservarla solo
     * garantizaría que la siguiente petición vuelva a caer aquí.
     */
    private function endSession(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login')
            ->withErrors(['email' => AdminDisabledException::MESSAGE]);
    }
}
