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

    public function test_a_reserved_route_registered_after_the_storefront_still_wins(): void
    {
        RouteFacade::get('precios', fn (): string => 'precios')->name('__probe.precios');
        $this->router()->getRoutes()->refreshNameLookups();

        $matched = $this->router()->getRoutes()->match(Request::create('/precios', 'GET'));

        $this->assertSame('__probe.precios', $matched->getName());
    }

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
