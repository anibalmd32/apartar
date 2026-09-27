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
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Fuga entre organizaciones por HTTP (`REQ-QAX-008` RB-2, RB-3 y SP-1).
 *
 * La suite **se construye desde el router**, no desde una lista escrita a
 * mano, y esa es toda la idea. Una lista se escribe una vez y deja de crecer el
 * mismo día; recorrer las rutas registradas hace que un endpoint nuevo entre
 * solo y falle si no está cubierto. Con las rutas de hoy parece desmedido; con
 * las ochenta de [05 §7] será lo único que impida que el gate se degrade.
 *
 * De ahí se sigue la regla que hace que esto no sea decorativo: **una ruta que
 * el mecanismo no sabe clasificar hace fallar la suite.** No se salta el caso,
 * no se marca como omitido. Un `markTestSkipped` para lo desconocido convierte
 * el gate en un adorno justo el día en que alguien añade una ruta que no encaja
 * en los patrones previstos, que es exactamente el día en que hace falta.
 *
 * Clasificar significa preguntar a los middlewares de la ruta qué superficie
 * gobiernan (`DeclaresTenantSurface`, de `T-006`) o si la ruta está fuera del
 * modelo de tenancy (`DeclaresNoTenantSurface`). Las dos son afirmaciones
 * explícitas; **el silencio no es ninguna de las dos** y por eso es un fallo.
 */
final class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Parámetros de ruta que son **un identificador y no un modelo**, con su
     * motivo escrito.
     *
     * La presunción del mecanismo es la contraria y así debe seguir: todo
     * parámetro se presume un recurso enlazado, y el que no lo sea tiene que
     * justificarse aquí — igual que `GlobalUniqueExceptions` obliga a
     * justificar cada tabla que se queda fuera del tenant.
     *
     * **Quedarse fuera no exime del caso cruzado.** La ruta sigue teniendo que
     * enlazar al menos un modelo del tenant, y es ese el que se pide desde la
     * organización vecina; lo que esta lista dice es que *ese otro* parámetro
     * no describe un recurso que el mecanismo pueda construir.
     *
     * @var array<string, string>
     */
    private const PLAIN_IDENTIFIERS = [
        'media' => 'La tabla `media` está declarada fuera del filtro por organización —se ata a su modelo por '
            .'relación polimórfica ([05 §4])—, así que un binding implícito resolvería la fila del negocio de al '
            .'lado y la comprobación de pertenencia tendría que hacerla el controlador igualmente. Pedido como '
            .'identificador, esa comprobación contra la colección del producto es el único camino, y quien '
            .'sostiene el `404` cruzado de la ruta es su `{product}` (`REQ-BOF-022` SP-9).',
        'code' => 'El código de la página de gracias del apartado (`T-045b`). La página es solo de la sesión que '
            .'apartó, así que sin ese código en la sesión todo es `404` y este mecanismo, que no la tiene, '
            .'afirmaría un `404` vacío. Lo que de verdad se puede torcer —una sesión que guarda el código de un '
            .'apartado de otro negocio— lo prueba `LayawayThanksIsolationTest`: bajo el catálogo de la URL, el '
            .'código ajeno no existe y la respuesta es `404`.',
    ];

    /**
     * El kernel HTTP es quien copia los alias y las prioridades de
     * `bootstrap/app.php` al router. Sin una petición de por medio no se
     * resuelve, y entonces `gatherRouteMiddleware()` devolvería los alias sin
     * traducir —`tenant`, `outside-tenancy`— y **toda ruta parecería no
     * declarar nada**: el gate en verde por no saber leer.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(HttpKernel::class);
    }

    /**
     * SP-1 · toda ruta nuestra declara de qué superficie es.
     *
     * Es la comprobación que hace que las demás signifiquen algo: sin ella, un
     * endpoint nuevo sin declarar no entraría en la parametrización y su
     * ausencia se leería igual que un caso en verde.
     */
    public function test_every_route_of_ours_declares_its_tenant_surface(): void
    {
        $this->assertSame([], $this->unclassifiableRoutes());
    }

    /**
     * Y la demostración de lo anterior, porque una aserción sobre un conjunto
     * que hoy está vacío pasa también cuando el mecanismo está roto: se inyecta
     * una ruta sin declarar y se exige que el mecanismo la señale.
     */
    public function test_an_undeclared_route_breaks_the_mechanism(): void
    {
        RouteFacade::middleware('web')->get('__gate/sin-declarar', fn (): string => 'ok');

        $unclassifiable = $this->unclassifiableRoutes();

        $this->assertCount(1, $unclassifiable);
        $this->assertStringContainsString('__gate/sin-declarar', $unclassifiable[0]);
    }

    /**
     * La ambigüedad también es un fallo: una ruta bajo dos superficies no
     * declara ninguna, declara que alguien la metió en dos grupos.
     */
    public function test_a_route_declaring_two_surfaces_breaks_the_mechanism(): void
    {
        RouteFacade::middleware(['web', 'tenant', 'outside-tenancy'])
            ->get('__gate/ambigua', fn (): string => 'ok');

        $unclassifiable = $this->unclassifiableRoutes();

        $this->assertCount(1, $unclassifiable);
        $this->assertStringContainsString('__gate/ambigua', $unclassifiable[0]);
    }

    /**
     * RB-2 y `REQ-ORG-002` RB-6 · el caso generado, sobre una ruta inyectada.
     *
     * Hoy ninguna ruta del tenant recibe un modelo por *binding* —las de
     * [05 §7] llegan con sus hitos—, así que el mecanismo se ejercita sobre una
     * ruta de prueba montada igual que las que vendrán: grupo `web`, guard de
     * sesión y `ResolveTenant`. Lo que se prueba no es la ruta, es el mecanismo
     * que mañana recorrerá las ochenta.
     */
    public function test_a_bound_tenant_route_hides_the_neighbours_record(): void
    {
        $route = RouteFacade::middleware(['web', 'auth', 'tenant'])
            ->get('__gate/usuarios/{user}', fn (User $user): array => ['id' => $user->getKey()]);

        $this->assertCrossTenantAccessIsNotFound($route, TenantSurface::Backoffice);
    }

    /**
     * RB-6 con todas las letras: **`404`, nunca `403`**. La diferencia no es de
     * estilo. Un `403` confirma que el recurso existe, y con identificadores
     * correlativos eso basta para contar los clientes del vecino sin ver ni uno.
     */
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

    /**
     * El caso complementario, que es el que evita que el anterior pase por el
     * motivo equivocado: el recurso **propio** sí se ve. Sin esto, un `404`
     * universal —una ruta mal montada, un binding roto— se leería como
     * aislamiento perfecto.
     */
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

    /**
     * El ancla del mecanismo: las rutas que hoy existen caen donde tienen que
     * caer.
     *
     * Sin esto, un fallo en la clasificación que devolviera la lista vacía
     * dejaría el recorrido en cero y todo seguiría en verde: un gate ciego se
     * ve exactamente igual que un gate satisfecho.
     */
    public function test_the_mechanism_places_the_routes_of_today(): void
    {
        $tenant = array_map(fn (array $pair): string => $this->describe($pair[0]), $this->tenantRoutes());

        $this->assertContains('GET /backoffice', $tenant);
        $this->assertContains('PATCH /settings/profile', $tenant);
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

    /**
     * RB-2 · el recorrido de verdad: cada ruta del tenant con un modelo por
     * *binding* genera su caso.
     *
     * Hoy no hay ninguna y el recorrido queda vacío, que es el estado correcto
     * de un mecanismo que deriva su trabajo de las rutas que existen. El caso
     * inyectado de más arriba es el que prueba que el recorrido funciona.
     */
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

    /**
     * Y la otra mitad de SP-1: ninguna ruta del tenant se queda sin caso **en
     * silencio**.
     *
     * Una ruta del tenant con parámetros que el mecanismo no sabe convertir en
     * un recurso de la organización vecina —un `{code}` que no es un modelo, un
     * binding hacia algo que no lleva `BelongsToOrganization`— hace fallar la
     * suite. Quien la añada tendrá que enseñarle al mecanismo a construir ese
     * recurso, que es exactamente la conversación que debe ocurrir.
     *
     * Las rutas del tenant **sin** parámetros no generan caso y no lo
     * necesitan: no hay identificador ajeno que pedir, y lo que devuelven sale
     * de consultas que el global scope filtra o hace lanzar
     * (`REQ-ORG-002` RB-1). Ahí el aislamiento no depende de un test.
     */
    public function test_no_tenant_route_is_left_uncovered_in_silence(): void
    {
        $uncoverable = [];

        foreach ($this->tenantRoutes() as [$route, $surface]) {
            $parameters = array_values(array_diff($route->parameterNames(), array_keys(self::PLAIN_IDENTIFIERS)));

            // La vitrina tiene su caso propio (`assertStorefrontRouteIsIsolated`):
            // su `{organization}` no es un recurso del tenant, **es** el tenant.
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

    /**
     * Y la lista de excepciones no se vacía de contenido: cada parámetro
     * declarado como identificador lleva su motivo escrito.
     *
     * Sin esto, `PLAIN_IDENTIFIERS` podría crecer con nombres sueltos y el
     * mecanismo dejaría de mirar rutas sin que nadie hubiera tenido que
     * explicar por qué.
     */
    public function test_every_plain_identifier_declares_its_reason(): void
    {
        foreach (self::PLAIN_IDENTIFIERS as $parameter => $reason) {
            $this->assertNotSame('', trim($reason), "El parámetro {$parameter} no declara su motivo.");
            $this->assertGreaterThan(80, mb_strlen($reason), "El motivo de {$parameter} no explica nada.");
        }
    }

    /**
     * El orden que hace posible el `404` de RB-6.
     *
     * `SubstituteBindings` resuelve el modelo de la URL; si corriera antes de
     * `ResolveTenant`, lo resolvería sin organización fijada y el global scope
     * lanzaría: un `500` en lugar de un `404`, y con él la confirmación de que
     * el recurso ajeno existe. La prioridad está declarada en `bootstrap/app.php`
     * y se comprueba aquí porque es una de esas cosas que se rompen al añadir un
     * middleware y no dan la cara hasta que alguien mira los logs.
     */
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

    /**
     * El mismo orden en la superficie pública, sobre la ruta real: allí el
     * modelo del binding **es** la fuente del tenant, así que al revés no
     * habría forma de resolverlo. Y por delante de `HandleInertiaRequests`,
     * que es lo que hace que la tasa y la zona compartidas sean las del
     * negocio del catálogo.
     */
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

    /**
     * Y en la vitrina (`T-036`): el tenant sale del slug de la organización
     * antes del binding y antes de las props compartidas, que son las de ese
     * negocio.
     */
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

    // -------------------------------------------------------------------
    // El caso generado
    // -------------------------------------------------------------------

    /**
     * Siembra la organización vecina con el recurso equivalente, se autentica
     * en la propia y exige `404` (`REQ-QAX-003` RB-10 y `REQ-QAX-008` SP-6: la
     * vecina tiene datos comparables y no vacíos, o el test pasaría solo).
     */
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

        $actor = $this->actorFor($surface, $own);

        $this->withoutOrganization();

        $method = $this->methodOf($route);
        $url = $this->urlFor($route, $parameters);

        $response = $this->actingAs($actor)->call($method, $url);

        $this->assertSame(404, $response->getStatusCode(), sprintf(
            '%s devolvió %d con el recurso de otra organización; REQ-ORG-002 RB-6 exige 404.',
            $this->describe($route),
            $response->getStatusCode(),
        ));
    }

    /**
     * El caso generado de la superficie pública (`REQ-CAT-021` RB-3).
     *
     * Aquí no hay «organización propia» desde la que pedir lo ajeno: quien
     * mira es anónimo y **el catálogo de la URL es la fuente del tenant**.
     * Así que el caso se construye al revés que en el backoffice: el
     * `{catalog}` es el ancla —publicado, de una organización con datos— y
     * todo **otro** modelo del tenant de la ruta se siembra en la vecina y se
     * exige `404` (una ficha `/c/{catalog}/p/{product}` con el producto de otro
     * negocio). Una ruta que solo enlaza el catálogo no tiene nada ajeno que
     * pedir; su caso es que el tenant fijado sea **el del catálogo** y no otro.
     *
     * Y una ruta pública sin `{catalog}` falla en voz alta: no sabría de dónde
     * sale su tenant.
     */
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
                '%s devolvió %d con {%s} de otra organización que la del catálogo; REQ-ORG-002 RB-6 exige 404.',
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

    /**
     * El caso de la vitrina (`REQ-ORG-006` RB-6, `T-036`).
     *
     * Aquí el parámetro de la URL no es un recurso del tenant sino **el
     * tenant**, así que no hay «recurso de la vecina» que pedir. Lo que se
     * afirma es lo que puede torcerse: que el tenant fijado es el del slug de
     * la URL —y no otro— aunque la vecina también tenga la vitrina encendida,
     * y que una vitrina apagada es `404` sin haber fijado ninguno.
     */
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

    /**
     * Si la ruta es la vitrina con su ancla: `{organization}` enlazado a
     * `Organization` y ningún otro parámetro.
     */
    private function isAnchoredStorefront(Route $route): bool
    {
        return $this->boundModels($route) === [ResolveStorefrontTenant::PARAMETER => Organization::class]
            && $route->parameterNames() === [ResolveStorefrontTenant::PARAMETER];
    }

    /**
     * Quién pide el recurso ajeno, según la superficie.
     *
     * Solo el backoffice tiene hoy con qué autenticarse; la pública no lo
     * necesita y tiene su propio caso más arriba. El portal llega con H3
     * —necesita `Customer`— y hasta entonces **falla en voz alta** en lugar de
     * omitir el caso: enseñarle al mecanismo a situarse en una superficie nueva
     * es parte de añadirla.
     */
    private function actorFor(TenantSurface $surface, Organization $organization): User
    {
        if ($surface !== TenantSurface::Backoffice) {
            $this->fail(sprintf(
                'La suite no sabe autenticarse en la superficie %s; enséñale antes de enrutarla.',
                $surface->value,
            ));
        }

        return $this->withinOrganization($organization, fn (): User => User::factory()->create());
    }

    /**
     * URL de la ruta con los recursos ajenos puestos en su sitio, respetando el
     * campo del binding (`{catalog:slug}` viaja por `slug`, no por `id`).
     *
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

    // -------------------------------------------------------------------
    // Clasificación
    // -------------------------------------------------------------------

    /**
     * Rutas que no declaran superficie, o que declaran dos.
     *
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
     * Rutas que operan **con** una organización fijada, con su superficie.
     *
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
     * Lo que declaran los middlewares de la ruta: una superficie, `null` si la
     * declaran fuera del modelo de tenancy, o nada.
     *
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
     * Rutas de las que somos responsables.
     *
     * El criterio es de dónde sale la acción: `/up` y `storage/{path}` los
     * registra el framework y sus *closures* viven en `vendor/`, así que exigir
     * que declaren superficie sería exigírselo a Laravel. Todo lo demás —lo que
     * escribimos en `routes/`— entra, incluidas las rutas que un test registra
     * al vuelo.
     *
     * El precio de este criterio es que una ruta apuntada a un controlador de
     * un paquete tampoco se auditaría. No hay ninguna, y cuando la haya tendrá
     * que envolverse en un controlador nuestro para poder declararla.
     *
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

    /**
     * Archivo donde está escrita la acción de la ruta.
     */
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

    // -------------------------------------------------------------------
    // Bindings
    // -------------------------------------------------------------------

    /**
     * Parámetros de la ruta que llegan como modelo, por su tipo declarado.
     *
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
     * Los que además están sujetos al tenant, que son los que pueden pertenecer
     * a la organización vecina.
     *
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

    /**
     * Cuántas rutas del tenant con recurso ajeno cubre hoy la suite.
     *
     * Se calcula igual que el recorrido, y existe para que el recorrido no
     * pueda quedarse en cero por un `continue` mal puesto sin que nadie se
     * entere.
     */
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
