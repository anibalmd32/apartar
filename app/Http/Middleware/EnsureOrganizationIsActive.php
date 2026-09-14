<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\OrganizationNotActiveException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decide qué puede hacer el equipo según el estado de su organización
 * (`REQ-BOF-001` RB-9 y RB-10, `REQ-ORG-008` RB-2 y RB-3).
 *
 * Los tres estados y lo que significan aquí:
 *
 *  - **`active`**: nada que decir.
 *  - **`suspended`**: el equipo **entra**, y esta es la regla que más va
 *    contra el instinto. Un negocio que no ha pagado su suscripción sigue
 *    teniendo clientes que le deben dinero, y cortarle el acceso a cobrarles
 *    le impide precisamente conseguir con qué pagar. Se le quita **crear**, no
 *    cobrar: sobrevive lo que cada ruta declare con `during-suspension`
 *    —leer la cobranza, aprobar comprobantes— y lo que hace falta para salir
 *    del apuro. Todo lo demás responde `403` con el motivo.
 *  - **`closed`**: se acabó. Lo pidió el propio `owner`, los datos se
 *    conservan y no hay operación que hacer dentro, así que la sesión se
 *    cierra y se vuelve al acceso con el motivo (RB-10).
 *
 * La lista de lo permitido **no está aquí**: está en cada ruta. Una lista
 * central se quedaría vieja en silencio; una ruta nueva sin declarar queda
 * bloqueada, que es el lado correcto en el que equivocarse.
 */
final class EnsureOrganizationIsActive
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasOrganization()) {
            return $next($request);
        }

        return match ($this->context->organization()->status) {
            OrganizationStatus::Active => $next($request),
            OrganizationStatus::Suspended => $this->whileSuspended($request, $next),
            OrganizationStatus::Closed => $this->endSession($request),
        };
    }

    /**
     * Modo restringido: pasa lo que la ruta declare, y nada más.
     *
     * @param  Closure(Request): Response  $next
     */
    private function whileSuspended(Request $request, Closure $next): Response
    {
        if ($this->routeSurvivesSuspension($request)) {
            return $next($request);
        }

        // SP-3: `403` con el motivo y adónde ir a arreglarlo. No un `404` ni
        // un error genérico: quien lo recibe puede resolverlo hoy mismo, y
        // solo lo hará si entiende qué pasó.
        abort(
            Response::HTTP_FORBIDDEN,
            'Tu suscripción está suspendida por falta de pago: puedes cobrar y aprobar comprobantes, '
            .'pero no crear cosas nuevas. Regulariza el pago desde Suscripción para recuperar todo.',
        );
    }

    /**
     * Indica si la ruta declaró seguir viva con la organización suspendida.
     */
    private function routeSurvivesSuspension(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach (RouteFacade::gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && explode(':', $middleware)[0] === AllowedDuringSuspension::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * RB-10 · el negocio cerró: se cierra también la sesión.
     */
    private function endSession(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // El mismo texto que recibe quien lo intenta desde el formulario de
        // acceso: los dos caminos llevan a la misma situación, y dos
        // redacciones distintas de lo mismo se acaban contradiciendo. El
        // nombre del negocio no se añade aquí —quien lo lee sabe a cuál
        // intentó entrar— y sí en la excepción, que es lo que se registra.
        return redirect()
            ->route('login')
            ->withErrors(['email' => OrganizationNotActiveException::CLOSED_MESSAGE]);
    }
}
