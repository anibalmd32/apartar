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

final readonly class ResolveCatalogTenant implements DeclaresTenantSurface
{
    public const PARAMETER = 'catalog';

    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
    ) {}

    public static function surface(): TenantSurface
    {
        return TenantSurface::PublicCatalog;
    }

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws LogicException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route(self::PARAMETER);

        if (! is_string($slug)) {
            throw new LogicException(sprintf(
                'ResolveCatalogTenant necesita el slug de {%s} sin resolver, y %s llegó con %s. O la ruta no '
                .'lleva ese parámetro, o SubstituteBindings corrió antes que este middleware: revisa la '
                .'prioridad declarada en bootstrap/app.php.',
                self::PARAMETER,
                $request->path(),
                get_debug_type($slug),
            ));
        }

        $catalog = $this->resolver->publicCatalog($slug);

        $this->context->setOrganization($catalog->organization);

        $request->route()?->setParameter(self::PARAMETER, $catalog);

        return $next($request);
    }
}
