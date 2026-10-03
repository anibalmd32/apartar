<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\TenantSurface;
use App\Models\Customer;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el tenant del portal desde la ficha de la sesión (`REQ-IDN-004` RB-2,
 * `REQ-ORG-002` RB-4).
 *
 * La sesión acotada es de **una** ficha, y la ficha es de **una**
 * organización: esa es la que se fija, y a partir de aquí toda consulta del
 * portal va filtrada por ella. Nada de la petición —ni la URL ni un parámetro—
 * puede elegir otra.
 *
 * Va detrás de `EnsurePortalSession`, que ya garantizó que hay sesión. Si aun
 * así la ficha no resuelve organización —una sesión corrupta, una ficha que
 * ya no existe—, se cierra la sesión y se vuelve al acceso: conservarla solo
 * garantizaría que la petición siguiente cayera aquí otra vez.
 *
 * Con la sesión de cuenta (H6) **no** se fija tenant (RB-3): cada lectura se
 * acota por la organización de cada ficha. Esa rama llega con `PortalContext`.
 */
final readonly class ResolvePortalTenant implements DeclaresTenantSurface
{
    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
    ) {}

    public static function surface(): TenantSurface
    {
        return TenantSurface::Portal;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        try {
            $organization = $customer instanceof Customer ? $this->resolver->resolve(self::surface(), $customer) : null;
        } catch (ModelNotFoundException) {
            $organization = null;
        }

        if ($organization === null) {
            Auth::guard('customer')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->guest(route('portal.access'));
        }

        $this->context->setOrganization($organization);

        return $next($request);
    }
}
