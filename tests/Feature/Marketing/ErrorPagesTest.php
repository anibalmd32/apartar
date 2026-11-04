<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ErrorPagesTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_a_missing_page_shows_the_apartar_404_with_a_way_home(): void
    {
        $this->get('/esta-pagina-no-existe-'.uniqid())
            ->assertNotFound()
            ->assertSee('No encontramos esta página')
            ->assertSee('Ir al inicio')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('/fonts/archivo-latin.woff2', false)
            ->assertSee('aria-label="Cambiar entre modo claro y oscuro"', false)
            ->assertSee("[data-theme='apartar-dark']", false)
            ->assertSee('apartar');
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function statuses(): iterable
    {
        yield '403' => [403, 'No tienes acceso a esta página'];
        yield '419' => [419, 'La página estuvo abierta mucho tiempo'];
        yield '500' => [500, 'Algo salió mal de nuestro lado'];
        yield '503' => [503, 'Estamos haciendo mantenimiento'];
    }

    #[DataProvider('statuses')]
    public function test_every_common_error_has_its_own_spanish_page(int $status, string $heading): void
    {
        config(['app.debug' => false]);
        Route::get('/_prueba-error', fn () => abort($status));

        $this->get('/_prueba-error')
            ->assertStatus($status)
            ->assertSee($heading)
            ->assertSee('Error '.$status)
            ->assertSee('Ir al inicio');
    }
}
