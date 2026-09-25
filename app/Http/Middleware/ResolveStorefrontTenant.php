<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\TenantSurface;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el tenant de la vitrina **desde el slug de la organización de la URL**
 * (`REQ-ORG-006` RB-6, `REQ-ORG-002` RB-4).
 *
 * El hermano de `ResolveCatalogTenant` para `apartar.store/{slug}`: la misma
 * forma, la misma prioridad en `bootstrap/app.php` —antes que
 * `SubstituteBindings` y que `HandleInertiaRequests`— y el parámetro llegando
 * **todavía como texto**. Si llegase como modelo es que alguien reordenó los
 * middlewares, y eso se dice en voz alta.
 *
 * ## La vitrina apagada no pasa de aquí
 *
 * `TenantResolver` solo encuentra la organización si tiene la vitrina
 * activada, así que una apagada lanza el mismo `ModelNotFoundException` que
 * un slug que no existe, **antes** de fijar ningún tenant. Las dos respuestas
 * no pueden distinguirse porque son la misma línea (`REQ-ORG-006` RB-6,
 * `T-036`). Qué se enseña de una vitrina encendida lo decide el controlador.
 */
final readonly class ResolveStorefrontTenant implements DeclaresTenantSurface
{
    /**
     * Parámetro de ruta del que sale el slug.
     */
    public const PARAMETER = 'organization';

    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
    ) {}

    public static function surface(): TenantSurface
    {
        return TenantSurface::Storefront;
    }

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws LogicException si la ruta no lleva `{organization}` o el binding ya corrió
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route(self::PARAMETER);

        if (! is_string($slug)) {
            throw new LogicException(sprintf(
                'ResolveStorefrontTenant necesita el slug de {%s} sin resolver, y %s llegó con %s. O la ruta no '
                .'lleva ese parámetro, o SubstituteBindings corrió antes que este middleware: revisa la '
                .'prioridad declarada en bootstrap/app.php.',
                self::PARAMETER,
                $request->path(),
                get_debug_type($slug),
            ));
        }

        $organization = $this->resolver->resolve(self::surface(), $slug)
            ?? throw new LogicException('La vitrina exige tenant y TenantResolver no devolvió ninguno.');

        $this->context->setOrganization($organization);

        return $next($request);
    }
}
