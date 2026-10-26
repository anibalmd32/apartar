<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantSurface;
use App\Http\Middleware\DeclaresNoTenantSurface;
use App\Http\Middleware\DeclaresTenantSurface;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveCatalogTenant;
use App\Http\Middleware\ResolveStorefrontTenant;
use App\Http\Middleware\ResolveTenant;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CrossTenantAccessTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    /**
     * @var array<string, string>
     */
    private const PLAIN_IDENTIFIERS = [
        'media' => 'La tabla `media` está declarada fuera del filtro por organización —se ata a su modelo por '
            .'relación polimórfica ([05 §4])—, así que un binding implícito resolvería la fila del negocio de al '
            .'lado y la comprobación de pertenencia tendría que hacerla el controlador igualmente. Pedido como '
            .'identificador, esa comprobación contra la colección del producto es el único camino, y quien '
            .'sostiene el `404` cruzado de la ruta es su `{product}`.',
        'code' => 'El código de la página de gracias del apartado. La página es solo de la sesión que '
            .'apartó, así que sin ese código en la sesión todo es `404` y este mecanismo, que no la tiene, '
            .'afirmaría un `404` vacío. Lo que de verdad se puede torcer —una sesión que guarda el código de un '
            .'apartado de otro negocio— lo prueba `LayawayThanksIsolationTest`: bajo el catálogo de la URL, el '
            .'código ajeno no existe y la respuesta es `404`. Y el detalle del portal: ahí el código se '
            .'busca entre las fichas de la sesión (`PortalContext::findLayaway()`), y el `404` de un código de '
            .'otra persona o de otro negocio lo afirma `PortalLayawayIsolationTest`. El formulario del comprobante '
            .' busca igual, y su `404` lo afirma '
            .'`PortalReceiptIsolationTest`.',
        'export' => 'La descarga de una exportación. '
            .'Se pide `403` y no `404` para una URL firmada válida abierta desde otro negocio: el '
            .'enlace existe, lo que falta es permiso. Por eso no se resuelve con el binding del modelo —que daría el '
            .'`404` que este mecanismo espera— sino buscándola en la organización del tenant, y lo ajeno o inexistente '
            .'es `403`. Lo afirma `ExportControllerTest::test_another_business_gets_403_with_a_valid_signed_link`.',
        'organization' => 'El selector de negocio de la multi-organización (rediseño, `POST '
            .'/backoffice/negocios/{organization}/entrar`). Su parámetro es **otro** negocio a propósito: el destino '
            .'del cambio. Lo que lo aísla no es el global scope sino `TeamMemberships::in()`, que solo acepta un '
            .'negocio donde esta misma persona —su correo— tiene una fila activa; cualquier otro es `404`. Lo afirma '
            .'`MultiOrganizationTest::test_the_switcher_lists_and_switches_only_to_own_businesses`.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(HttpKernel::class);
    }

    public function test_every_route_of_ours_declares_its_tenant_surface(): void
    {
        $this->assertSame([], $this->unclassifiableRoutes());
    }

    public function test_an_undeclared_route_breaks_the_mechanism(): void
    {
        RouteFacade::middleware('web')->get('__gate/sin-declarar', fn (): string => 'ok');

        $unclassifiable = $this->unclassifiableRoutes();

        $this->assertCount(1, $unclassifiable);
        $this->assertStringContainsString('__gate/sin-declarar', $unclassifiable[0]);
    }

    public function test_a_route_declaring_two_surfaces_breaks_the_mechanism(): void
    {
        RouteFacade::middleware(['web', 'tenant', 'outside-tenancy'])
            ->get('__gate/ambigua', fn (): string => 'ok');

        $unclassifiable = $this->unclassifiableRoutes();

        $this->assertCount(1, $unclassifiable);
        $this->assertStringContainsString('__gate/ambigua', $unclassifiable[0]);
    }

    public function test_a_bound_tenant_route_hides_the_neighbours_record(): void
    {
        $route = RouteFacade::middleware(['web', 'auth', 'tenant'])
            ->get('__gate/usuarios/{user}', fn (User $user): array => ['id' => $user->getKey()]);

        $this->assertCrossTenantAccessIsNotFound($route, TenantSurface::Backoffice);
    }

    public function test_the_cross_tenant_answer_is_never_403(): void
    {
        RouteFacade::middleware(['web', 'auth', 'tenant'])
            ->get('__gate/usuarios/{user}', fn (User $user): array => ['id' => $user->getKey()]);

        $neighbour = $this->neighbourOrganization();
        $own = Organization::factory()->create();

        $foreign = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());
        $actor = $this->withinOrganization($own, fn (): User => User::factory()->create());

        $this->withoutOrganization();

        $response = $this->actingAs($actor)->get('/__gate/usuarios/'.$foreign->getKey());

        $response->assertNotFound();
        $this->assertNotSame(403, $response->status());
    }

    public function test_the_same_route_serves_the_organizations_own_record(): void
    {
        RouteFacade::middleware(['web', 'auth', 'tenant'])
            ->get('__gate/usuarios/{user}', fn (User $user): array => ['id' => $user->getKey()]);

        $own = Organization::factory()->create();
        $this->neighbourOrganization();

        $actor = $this->withinOrganization($own, fn (): User => User::factory()->create());

        $this->withoutOrganization();

        $this->actingAs($actor)
            ->get('/__gate/usuarios/'.$actor->getKey())
            ->assertOk()
            ->assertJson(['id' => $actor->getKey()]);
    }

    public function test_the_mechanism_places_the_routes_of_today(): void
    {
        $tenant = array_map(fn (array $pair): string => $this->describe($pair[0]), $this->tenantRoutes());

        $this->assertContains('GET /backoffice', $tenant);
        $this->assertContains('PATCH /backoffice/perfil', $tenant);
        $this->assertContains('GET /c/{catalog}', $tenant);
        $this->assertContains('GET /{organization}', $tenant);

        $outside = array_map(
            fn (Route $route): string => $this->describe($route),
            array_values(array_filter(
                $this->ourRoutes(),
                fn (Route $route): bool => $this->declaredSurfaces($route) === [null],
            )),
        );

        $this->assertContains('POST /backoffice/login', $outside);
        $this->assertContains('GET /', $outside);
    }

    public function test_every_bound_tenant_route_hides_the_neighbours_resource(): void
    {
        $covered = 0;

        foreach ($this->tenantRoutes() as [$route, $surface]) {
            if ($surface === TenantSurface::Storefront) {
                $this->assertStorefrontRouteIsIsolated($route);
                $covered++;

                continue;
            }

            if ($this->boundTenantModels($route) === []) {
                continue;
            }

            $this->assertCrossTenantAccessIsNotFound($route, $surface);
            $covered++;
        }

        $this->assertSame($covered, $this->coveredRouteCount());
    }

    public function test_no_tenant_route_is_left_uncovered_in_silence(): void
    {
        $uncoverable = [];

        foreach ($this->tenantRoutes() as [$route, $surface]) {
            $parameters = array_values(array_diff($route->parameterNames(), array_keys(self::PLAIN_IDENTIFIERS)));

            if ($surface === TenantSurface::Storefront && $this->isAnchoredStorefront($route)) {
                continue;
            }

            if ($parameters === []) {
                continue;
            }

            $models = $this->boundModels($route);
            $missing = array_values(array_diff($parameters, array_keys($models)));

            if ($missing !== []) {
                $uncoverable[] = sprintf(
                    '%s · el mecanismo no sabe qué recurso es {%s}',
                    $this->describe($route),
                    implode('}, {', $missing),
                );

                continue;
            }

            if ($this->boundTenantModels($route) === []) {
                $uncoverable[] = sprintf(
                    '%s · ninguno de sus modelos lleva BelongsToOrganization, '
                    .'así que no hay recurso ajeno que pedir',
                    $this->describe($route),
                );
            }
        }

        $this->assertSame([], $uncoverable);
    }

    public function test_every_plain_identifier_declares_its_reason(): void
    {
        foreach (self::PLAIN_IDENTIFIERS as $parameter => $reason) {
            $this->assertNotSame('', trim($reason), "El parámetro {$parameter} no declara su motivo.");
            $this->assertGreaterThan(80, mb_strlen($reason), "El motivo de {$parameter} no explica nada.");
        }
    }

    public function test_the_tenant_is_resolved_before_the_route_binding(): void
    {
        $route = RouteFacade::middleware(['web', 'auth', 'tenant'])
            ->get('__gate/orden/{user}', fn (User $user): array => ['id' => $user->getKey()]);

        $middleware = array_values(array_filter(
            $this->router()->gatherRouteMiddleware($route),
            'is_string',
        ));

        $tenant = array_search(ResolveTenant::class, $middleware, true);
        $bindings = array_search(SubstituteBindings::class, $middleware, true);

        $this->assertIsInt($tenant, 'ResolveTenant no está en el stack de la ruta.');
        $this->assertIsInt($bindings, 'SubstituteBindings no está en el stack de la ruta.');
        $this->assertLessThan($bindings, $tenant, 'El binding se resolvería sin tenant fijado.');
    }

    public function test_the_catalog_tenant_is_resolved_before_the_route_binding(): void
    {
        $route = $this->router()->getRoutes()->getByName('catalog.show');
        $this->assertInstanceOf(Route::class, $route);

        $middleware = array_values(array_filter($this->router()->gatherRouteMiddleware($route), 'is_string'));

        $tenant = array_search(ResolveCatalogTenant::class, $middleware, true);
        $bindings = array_search(SubstituteBindings::class, $middleware, true);
        $inertia = array_search(HandleInertiaRequests::class, $middleware, true);

        $this->assertIsInt($tenant, 'ResolveCatalogTenant no está en el stack de la ruta pública.');
        $this->assertIsInt($bindings);
        $this->assertIsInt($inertia);
        $this->assertLessThan($bindings, $tenant, 'El catálogo se resolvería sin tenant fijado.');
        $this->assertLessThan($inertia, $tenant, 'Las props compartidas se calcularían sin el negocio del catálogo.');
    }

    public function test_the_storefront_tenant_is_resolved_before_the_route_binding(): void
    {
        $route = $this->router()->getRoutes()->getByName('storefront');
        $this->assertInstanceOf(Route::class, $route);

        $middleware = array_values(array_filter($this->router()->gatherRouteMiddleware($route), 'is_string'));

        $tenant = array_search(ResolveStorefrontTenant::class, $middleware, true);
        $bindings = array_search(SubstituteBindings::class, $middleware, true);
        $inertia = array_search(HandleInertiaRequests::class, $middleware, true);

        $this->assertIsInt($tenant, 'ResolveStorefrontTenant no está en el stack de la vitrina.');
        $this->assertIsInt($bindings);
        $this->assertIsInt($inertia);
        $this->assertLessThan($bindings, $tenant, 'La organización se resolvería antes de comprobar su vitrina.');
        $this->assertLessThan($inertia, $tenant, 'Las props compartidas se calcularían sin el negocio de la vitrina.');
    }

    private function assertCrossTenantAccessIsNotFound(Route $route, TenantSurface $surface): void
    {
        if ($surface === TenantSurface::PublicCatalog) {
            $this->assertPublicCatalogRouteIsIsolated($route);

            return;
        }

        $neighbour = $this->neighbourOrganization();
        $own = Organization::factory()->create();

        $parameters = [];

        foreach ($this->boundModels($route) as $name => $model) {
            $organization = $this->usesTenantTrait($model) ? $neighbour : $own;

            $parameters[$name] = $this->withinOrganization(
                $organization,
                fn (): Model => Factory::factoryForModel($model)->createOne(),
            );
        }

        [$actor, $guard] = $this->actorFor($surface, $own);

        $this->withoutOrganization();

        $method = $this->methodOf($route);
        $url = $this->urlFor($route, $parameters);

        $default = $this->app->make(AuthManager::class)->getDefaultDriver();
        try {
            $response = $this->actingAs($actor, $guard)->call($method, $url);
        } finally {
            $this->app->make(AuthManager::class)->shouldUse($default);
        }

        $this->assertSame(404, $response->getStatusCode(), sprintf(
            '%s devolvió %d con el recurso de otra organización; se exige 404.',
            $this->describe($route),
            $response->getStatusCode(),
        ));
    }

    private function assertPublicCatalogRouteIsIsolated(Route $route): void
    {
        $models = $this->boundModels($route);
        $anchor = ResolveCatalogTenant::PARAMETER;

        if (($models[$anchor] ?? null) !== Catalog::class) {
            $this->fail(sprintf('%s es de la superficie pública y no enlaza {%s}: no hay de dónde sacar su tenant.', $this->describe($route), $anchor));
        }

        $neighbour = $this->neighbourOrganization();
        $own = Organization::factory()->create();

        $parameters = [$anchor => $this->withinOrganization($own, fn (): Catalog => Catalog::factory()->published()->create())];
        $foreign = [];

        foreach ($models as $name => $model) {
            if ($name === $anchor) {
                continue;
            }

            $organization = $this->usesTenantTrait($model) ? $neighbour : $own;
            $parameters[$name] = $this->withinOrganization($organization, fn (): Model => Factory::factoryForModel($model)->createOne());

            if ($organization === $neighbour) {
                $foreign[] = $name;
            }
        }

        $this->withoutOrganization();

        $response = $this->call($this->methodOf($route), $this->urlFor($route, $parameters));

        if ($foreign !== []) {
            $this->assertSame(404, $response->getStatusCode(), sprintf(
                '%s devolvió %d con {%s} de otra organización que la del catálogo; se exige 404.',
                $this->describe($route),
                $response->getStatusCode(),
                implode('}, {', $foreign),
            ));

            return;
        }

        $this->assertNotSame(500, $response->getStatusCode(), $this->describe($route).' no supo resolver su tenant.');
        $this->assertTrue($own->is(app(TenantContext::class)->organization()), sprintf(
            '%s fijó un tenant que no es el de su catálogo.',
            $this->describe($route),
        ));
    }

    private function assertStorefrontRouteIsIsolated(Route $route): void
    {
        if (! $this->isAnchoredStorefront($route)) {
            $this->fail(sprintf('%s es de la vitrina y no enlaza {%s} a Organization: no hay de dónde sacar su tenant.', $this->describe($route), ResolveStorefrontTenant::PARAMETER));
        }

        $this->neighbourOrganization()->update(['storefront_enabled' => true]);
        $own = Organization::factory()->create(['storefront_enabled' => true]);
        $off = Organization::factory()->create(['storefront_enabled' => false]);
        $this->withinOrganization($own, fn () => Subscription::factory()->forPlan(Plan::factory()->create())->create());

        $this->withoutOrganization();
        $response = $this->call($this->methodOf($route), $this->urlFor($route, [ResolveStorefrontTenant::PARAMETER => $own]));

        $this->assertSame(200, $response->getStatusCode(), $this->describe($route).' no abrió una vitrina encendida.');
        $this->assertTrue($own->is(app(TenantContext::class)->organization()), sprintf(
            '%s fijó un tenant que no es el de su slug.',
            $this->describe($route),
        ));

        $this->withoutOrganization();
        $response = $this->call($this->methodOf($route), $this->urlFor($route, [ResolveStorefrontTenant::PARAMETER => $off]));

        $this->assertSame(404, $response->getStatusCode(), $this->describe($route).' abrió una vitrina apagada.');
        $this->assertFalse(app(TenantContext::class)->hasOrganization(), $this->describe($route).' fijó el tenant de una vitrina apagada.');
    }

    private function isAnchoredStorefront(Route $route): bool
    {
        return $this->boundModels($route) === [ResolveStorefrontTenant::PARAMETER => Organization::class]
            && $route->parameterNames() === [ResolveStorefrontTenant::PARAMETER];
    }

    /**
     * @return array{0: User|Customer, 1: string}
     */
    private function actorFor(TenantSurface $surface, Organization $organization): array
    {
        if ($surface === TenantSurface::Backoffice) {
            return [$this->withinOrganization($organization, fn (): User => User::factory()->create()), 'web'];
        }

        if ($surface === TenantSurface::Portal) {
            $layaway = $this->layawayIn($organization);

            return [$this->withinOrganization($organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id)), 'customer'];
        }

        $this->fail(sprintf(
            'La suite no sabe autenticarse en la superficie %s; enséñale antes de enrutarla.',
            $surface->value,
        ));
    }

    /**
     * @param  array<string, Model>  $parameters
     */
    private function urlFor(Route $route, array $parameters): string
    {
        $uri = $route->uri();

        foreach ($parameters as $name => $model) {
            $field = $route->bindingFieldFor($name);

            $value = $field === null
                ? $model->getRouteKey()
                : $model->getAttribute($field);

            $uri = (string) preg_replace(
                '/\{'.preg_quote($name, '/').'(:[^}]+)?\??\}/',
                rawurlencode((string) $value),
                $uri,
            );
        }

        return '/'.ltrim($uri, '/');
    }

    private function methodOf(Route $route): string
    {
        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                return $method;
            }
        }

        return 'GET';
    }

    /**
     * @return list<string>
     */
    private function unclassifiableRoutes(): array
    {
        $offenders = [];

        foreach ($this->ourRoutes() as $route) {
            $surfaces = $this->declaredSurfaces($route);

            if ($surfaces === []) {
                $offenders[] = $this->describe($route).' · no declara superficie';

                continue;
            }

            if (count(array_unique($surfaces, SORT_REGULAR)) > 1) {
                $offenders[] = $this->describe($route).' · declara más de una superficie';
            }
        }

        return $offenders;
    }

    /**
     * @return list<array{0: Route, 1: TenantSurface}>
     */
    private function tenantRoutes(): array
    {
        $routes = [];

        foreach ($this->ourRoutes() as $route) {
            $surfaces = $this->declaredSurfaces($route);

            if (count($surfaces) !== 1) {
                continue;
            }

            $surface = $surfaces[0];

            if ($surface instanceof TenantSurface && $surface->requiresTenant()) {
                $routes[] = [$route, $surface];
            }
        }

        return $routes;
    }

    /**
     * @return list<TenantSurface|null>
     */
    private function declaredSurfaces(Route $route): array
    {
        $declared = [];

        foreach ($this->router()->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $class = explode(':', $middleware)[0];

            if (! class_exists($class) && ! interface_exists($class)) {
                continue;
            }

            if (is_a($class, DeclaresTenantSurface::class, true)) {
                $declared[] = $class::surface();
            }

            if (is_a($class, DeclaresNoTenantSurface::class, true)) {
                $declared[] = null;
            }
        }

        return $declared;
    }

    /**
     * @return list<Route>
     */
    private function ourRoutes(): array
    {
        $routes = [];

        foreach ($this->router()->getRoutes()->getRoutes() as $route) {
            $file = $this->declaringFileOf($route);

            if ($file === null || str_contains($file, '/vendor/')) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    private function declaringFileOf(Route $route): ?string
    {
        $action = $route->getAction('uses');

        if ($action instanceof Closure) {
            $file = (new ReflectionFunction($action))->getFileName();

            return is_string($file) ? str_replace('\\', '/', $file) : null;
        }

        if (! is_string($action)) {
            return null;
        }

        $class = explode('@', $action)[0];

        if (! class_exists($class)) {
            return null;
        }

        $file = (new ReflectionClass($class))->getFileName();

        return is_string($file) ? str_replace('\\', '/', $file) : null;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function boundModels(Route $route): array
    {
        $models = [];
        $parameters = $route->parameterNames();

        foreach ($route->signatureParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $class */
            $class = $type->getName();

            if (! in_array($parameter->getName(), $parameters, true) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $models[$parameter->getName()] = $class;
        }

        return $models;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function boundTenantModels(Route $route): array
    {
        return array_filter(
            $this->boundModels($route),
            fn (string $model): bool => $this->usesTenantTrait($model),
        );
    }

    /**
     * @param  class-string  $model
     */
    private function usesTenantTrait(string $model): bool
    {
        return in_array(BelongsToOrganization::class, class_uses_recursive($model), true);
    }

    private function coveredRouteCount(): int
    {
        $covered = 0;

        foreach ($this->tenantRoutes() as [$route, $surface]) {
            if ($surface === TenantSurface::Storefront || $this->boundTenantModels($route) !== []) {
                $covered++;
            }
        }

        return $covered;
    }

    private function describe(Route $route): string
    {
        return $this->methodOf($route).' /'.ltrim($route->uri(), '/');
    }

    private function router(): Router
    {
        return $this->app->make(Router::class);
    }
}
