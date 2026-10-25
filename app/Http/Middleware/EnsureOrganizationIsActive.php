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
     * @param  Closure(Request): Response  $next
     */
    private function whileSuspended(Request $request, Closure $next): Response
    {
        if ($this->routeSurvivesSuspension($request)) {
            return $next($request);
        }

        abort(
            Response::HTTP_FORBIDDEN,
            'Tu suscripción está suspendida por falta de pago: puedes cobrar y aprobar comprobantes, '
            .'pero no crear cosas nuevas. Regulariza el pago desde Suscripción para recuperar todo.',
        );
    }

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

    private function endSession(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login')
            ->withErrors(['email' => OrganizationNotActiveException::CLOSED_MESSAGE]);
    }
}
