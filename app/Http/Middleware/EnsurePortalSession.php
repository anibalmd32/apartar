<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
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
        $guard = Auth::guard('customer');

        if (! $guard->check()) {
            return redirect()->guest(route('portal.access'));
        }

        // `REQ-IDN-004` SP-2 · una sesión de una ficha absorbida por una fusión
        // llega con la superviviente (`TenantAwareUserProvider`): la sesión
        // pasa a guardar su id, y la fusión no se vuelve a buscar.
        if ($guard instanceof SessionGuard && (string) $request->session()->get($guard->getName()) !== (string) $guard->id()) {
            $request->session()->put($guard->getName(), $guard->id());
            $request->session()->migrate(true);
        }

        return $next($request);
    }
}
