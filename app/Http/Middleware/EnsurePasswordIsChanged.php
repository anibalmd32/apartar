<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Desvía toda la navegación al cambio de contraseña obligatorio
 * (`REQ-BOF-001` RB-5).
 *
 * `must_change_password` se pone en dos sitios: al reactivar a alguien
 * (`REQ-ORG-005` RB-6, por precaución) y donde haga falta forzar una rotación.
 * Mientras esté puesto, el backoffice entero lleva a la misma pantalla: si se
 * pudiera navegar «solo un poco», la rotación sería opcional y la contraseña
 * que se quería retirar seguiría en uso.
 *
 * Se excluyen las rutas que harían imposible cumplirlo —la propia pantalla de
 * cambio y su envío— y el cierre de sesión, porque encerrar a alguien sin
 * poder salir convierte una precaución en una trampa.
 */
final class EnsurePasswordIsChanged
{
    /**
     * Rutas que siguen abiertas mientras la contraseña esté pendiente.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'password.change',
        'password.change.store',
        'logout',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return redirect()->route('password.change');
    }
}
