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
 * Fija el tenant de la superficie pública **desde el catálogo de la URL**
 * (`REQ-ORG-002` RB-4, `REQ-CAT-021` RB-3).
 *
 * Es la otra mitad de `ResolveTenant`, y la que sostiene el modelo en la cara
 * pública: en el backoffice la organización sale de la sesión de alguien que
 * entró; aquí sale de un slug que escribe cualquiera. De ese catálogo se
 * deriva todo lo demás —la taxonomía, la tasa, las cuentas de cobro, el
 * branding—, y a partir de este middleware `Product::query()` devuelve solo lo
 * de ese negocio sin que ningún controlador escriba un `where`.
 *
 * ## Corre antes que el binding, igual que su hermano
 *
 * `{catalog:slug}` es un modelo del tenant, así que `SubstituteBindings` no
 * puede resolverlo sin una organización fijada: el global scope lanzaría y la
 * respuesta sería un `500`. Por eso este middleware lleva la misma prioridad
 * explícita que `ResolveTenant` en `bootstrap/app.php`, y por eso aquí el
 * parámetro llega **todavía como texto**. Si llegase ya como modelo es que
 * alguien reordenó los middlewares, y eso se dice en voz alta en vez de
 * resolverse a medias.
 *
 * Resuelve el tenant **y nada más**. Qué se responde —`404` para el borrador,
 * `410` para el expirado, la página para el resto— lo decide el controlador
 * con `Catalog::visibility()`, que es la única función que sabe en qué orden
 * se evalúan los tres criterios. Duplicar aquí esa decisión sería el segundo
 * censo de los mismos cuatro estados.
 */
final readonly class ResolveCatalogTenant implements DeclaresTenantSurface
{
    /**
     * Parámetro de ruta del que sale el slug.
     */
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
     * @throws LogicException si la ruta no lleva `{catalog}` o el binding ya corrió
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

        // Un slug que no existe lanza `ModelNotFoundException`, que es el
        // `404` de SP-1 sin rama propia: el mismo que daría el binding.
        $organization = $this->resolver->resolve(self::surface(), $slug)
            ?? throw new LogicException('La superficie pública exige tenant y TenantResolver no devolvió ninguno.');

        $this->context->setOrganization($organization);

        return $next($request);
    }
}
