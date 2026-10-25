<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantSurface;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

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
     * @throws LogicException
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
