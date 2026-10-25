<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Models\Organization;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ResetDatabaseCommandsTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_the_demo_scenario_is_never_seeded_in_production(): void
    {
        $organization = Organization::factory()->create();
        $this->app->instance('env', 'production');

        $this->artisan('db:reset-demo', ['--force' => true])
            ->expectsOutputToContain('no se siembra en producción')
            ->assertFailed();

        $this->assertModelExists($organization);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function confirmations(): array
    {
        return [
            'demo' => ['db:reset-demo', 'Se borrarán TODOS los datos y archivos subidos y se sembrará el escenario de prueba. ¿Continuar?'],
            'vacía' => ['db:reset-empty', 'Se borrarán TODOS los datos y archivos subidos y la base quedará vacía. ¿Continuar?'],
        ];
    }

    #[DataProvider('confirmations')]
    public function test_nothing_is_wiped_without_confirmation(string $command, string $question): void
    {
        $organization = Organization::factory()->create();

        $this->artisan($command)
            ->expectsConfirmation($question, 'no')
            ->assertFailed();

        $this->assertModelExists($organization);
    }

    public function test_emptying_production_asks_with_an_explicit_warning(): void
    {
        $organization = Organization::factory()->create();
        $this->app->instance('env', 'production');

        $this->artisan('db:reset-empty')
            ->expectsConfirmation('Estás en PRODUCCIÓN. Se borrarán TODOS los negocios, clientes, pagos y archivos subidos. ¿Continuar?', 'no')
            ->assertFailed();

        $this->assertModelExists($organization);
    }
}
