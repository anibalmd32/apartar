<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\TenantSurface;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el tenant del backoffice desde la sesión (`REQ-ORG-002` RB-4).
 *
 * Es el primer eslabón de `/backoffice/*`: a partir de aquí, `Product::query()`
 * devuelve solo los productos de esa organización sin que ningún controlador
 * escriba un `where`.
 *
 * **Si no se puede resolver, se cierra la sesión** (SP-1). Una sesión corrupta,
 * o un usuario cuya organización se eliminó, no puede seguir adelante: la
 * alternativa —continuar sin tenant— no sería una consulta sin filtro, porque
 * el global scope lanzaría, pero dejaría al usuario frente a un error de
 * quinientos en lugar de frente a su pantalla de acceso.
 */
final readonly class ResolveTenant implements DeclaresTenantSurface
{
    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
    ) {}

    public static function surface(): TenantSurface
    {
        return TenantSurface::Backoffice;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $organization = $this->resolver->resolve(self::surface(), $request->user());
        } catch (ModelNotFoundException) {
            return $this->abandonSession($request);
        }

        if ($organization === null) {
            return $this->abandonSession($request);
        }

        $this->context->setOrganization($organization);

        return $next($request);
    }

    /**
     * Cierra la sesión y devuelve al acceso.
     *
     * Se invalida la sesión entera y se rota el token: si el motivo era una
     * sesión corrupta, conservarla solo garantizaría que la siguiente petición
     * vuelva a caer aquí.
     */
    private function abandonSession(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->guest(route('login'));
    }
}
