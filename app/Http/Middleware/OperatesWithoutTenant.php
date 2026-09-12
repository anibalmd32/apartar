<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantSurface;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declara que una superficie opera **sin** organización activa
 * (`REQ-ORG-002` RB-4, fila de plataforma).
 *
 * El backoffice de plataforma ve a todas las organizaciones y por eso consulta
 * con `withoutTenantScope()`. Que no tenga tenant no se deja como la ausencia
 * de un middleware: una ausencia es indistinguible de un olvido, y el día que
 * alguien añada una ruta de plataforma bajo el grupo equivocado, nada lo
 * diría. Aquí es una afirmación, y como tal se comprueba.
 *
 * Y la afirmación se sostiene de verdad: si llega con un tenant fijado, se
 * lanza. Significa que la ruta está en dos grupos a la vez o que un middleware
 * anterior la resolvió, y ambas cosas son errores de enrutado que conviene ver
 * en el primer despliegue y no en el informe de una fuga.
 */
final readonly class OperatesWithoutTenant implements DeclaresTenantSurface
{
    public function __construct(private TenantContext $context) {}

    public static function surface(): TenantSurface
    {
        return TenantSurface::Platform;
    }

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws LogicException si la petición llega con una organización fijada
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->hasOrganization()) {
            throw new LogicException(sprintf(
                'La ruta %s declara operar sin tenant y llegó con la organización %d fijada. '
                .'Revisa en qué grupo de rutas está: una superficie de plataforma no puede estar '
                .'también bajo ResolveTenant.',
                $request->path(),
                $this->context->organizationId(),
            ));
        }

        return $next($request);
    }
}
