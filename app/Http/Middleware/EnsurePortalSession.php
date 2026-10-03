<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige una sesión del portal (`REQ-IDN-004`, `REQ-LAY-020` RB-1).
 *
 * Sin sesión, a `/mi-cuenta/acceso` **conservando el destino** (SP-1): quien
 * abrió el enlace de su apartado vuelve a él después de entrar.
 *
 * Hoy la única sesión del portal es la acotada (`customer`). La de cuenta
 * (`account`, Google) llega con H6 y entra aquí como segunda vía: cualquiera
 * de las dos basta, y si están las dos prevalece la de cuenta
 * (`REQ-ACC-005` RB-4).
 *
 * No declara superficie: dice **quién** puede pasar, no de qué negocio es la
 * petición. Eso lo dice `ResolvePortalTenant` en las rutas que leen datos del
 * negocio, u `outside-tenancy` en las que no (salir).
 */
final class EnsurePortalSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('customer')->check()) {
            return redirect()->guest(route('portal.access'));
        }

        return $next($request);
    }
}
