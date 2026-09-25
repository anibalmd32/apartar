<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Http\Controllers\Catalog\OrganizationStorefrontController;
use App\Support\ReservedSlugs;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * La vitrina `/{organization:slug}` no se come ninguna ruta de la aplicación
 * (`REQ-ORG-006` SP-8, [05 §7.2], `T-036`).
 *
 * Es un comodín de un segmento en la raíz del dominio, y compite con toda
 * ruta literal de un segmento —las de hoy y las que llegarán: `T-037` trae
 * `/precios` y las páginas legales justo después—. Si una cayera en la
 * vitrina no habría error en ningún log: `/precios` daría `404`, o la vitrina
 * de nadie, y lo descubriría quien quería ver los planes.
 *
 * Por eso esto **no confía en el orden de los ficheros**: pregunta al router,
 * con la tabla de rutas registrada de verdad, adónde va cada segmento. Y la
 * ruta de la vitrina no depende de ese orden: su parámetro excluye los slugs
 * de `ReservedSlugs` (`routePattern()`), así que un segmento reservado no la
 * alcanza aunque su ruta se registre después o no exista todavía. Si alguien
 * quitara esa restricción, los segmentos que hoy no tienen ruta propia
 * —`precios`, `c`, `api`— caerían en la vitrina y el primer caso se pondría
 * rojo.
 */
final class StorefrontRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedSlugs(): iterable
    {
        foreach (ReservedSlugs::all() as $slug) {
            yield $slug => [$slug];
        }
    }

    #[DataProvider('reservedSlugs')]
    public function test_no_reserved_segment_resolves_to_the_storefront(string $slug): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $this->assertFalse(
                $this->resolvesToStorefront('/'.$slug, $method),
                sprintf('%s /%s resuelve a la vitrina: el segmento está reservado y la ruta de la vitrina no puede admitirlo.', $method, $slug),
            );
        }
    }

    /**
     * Toda ruta literal de un segmento que exista hoy se resuelve a sí misma,
     * esté reservada su palabra o no.
     */
    public function test_every_literal_single_segment_route_wins_over_the_storefront(): void
    {
        $literal = collect($this->router()->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true))
            ->filter(fn (Route $route): bool => preg_match('#^[^/{}]+$#', $route->uri()) === 1)
            ->values();

        $this->assertNotEmpty($literal, 'No hay rutas literales de un segmento: el recorrido no probaría nada.');

        foreach ($literal as $route) {
            $matched = $this->router()->getRoutes()->match(Request::create('/'.$route->uri(), 'GET'));

            $this->assertSame($route, $matched, sprintf('GET /%s no llega a su propia ruta.', $route->uri()));
        }
    }

    /**
     * El caso de `T-037`, ensayado: una ruta reservada registrada **después**
     * de la vitrina sigue siendo suya.
     */
    public function test_a_reserved_route_registered_after_the_storefront_still_wins(): void
    {
        RouteFacade::get('precios', fn (): string => 'precios')->name('__probe.precios');
        $this->router()->getRoutes()->refreshNameLookups();

        $matched = $this->router()->getRoutes()->match(Request::create('/precios', 'GET'));

        $this->assertSame('__probe.precios', $matched->getName());
    }

    /**
     * Y la otra mitad, para que el recorrido no pase en verde con una vitrina
     * que no resuelve nada: un slug de negocio sí llega a ella, con guiones y
     * cifras, y lo que no tiene forma de slug no.
     */
    public function test_a_business_slug_reaches_the_storefront(): void
    {
        $this->assertTrue($this->resolvesToStorefront('/ferreteria-el-tornillo-2'));
        $this->assertTrue($this->resolvesToStorefront('/precios-bajos'));
        $this->assertFalse($this->resolvesToStorefront('/Ferreteria'));
        $this->assertFalse($this->resolvesToStorefront('/ferreteria--doble'));
    }

    private function resolvesToStorefront(string $path, string $method = 'GET'): bool
    {
        try {
            $route = $this->router()->getRoutes()->match(Request::create($path, $method));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }

        return $route->getControllerClass() === OrganizationStorefrontController::class;
    }

    private function router(): Router
    {
        return app('router');
    }
}
