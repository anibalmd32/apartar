<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La tasa inicial de una instalación nueva ([05 §6.4], `REQ-FX-005` SP-1).
 *
 * Sin ella el hueco es real aunque dure poco: entre `migrate --seed` y la
 * primera vuelta del job pasan hasta treinta minutos, y en ese rato el catálogo
 * funcionaría en dólares puros —sin monto en bolívares y con el toggle
 * desactivado—, que es el estado degradado que `REQ-FX-005` describe para
 * cuando algo va mal, no para el primer día.
 *
 * Clase de PHPUnit y no sintaxis de Pest porque usa `seed()` del caso base.
 */
final class ExchangeRateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_initial_bcv_rate(): void
    {
        $this->seed(ExchangeRateSeeder::class);

        $row = ExchangeRate::query()->where('source', RateSource::Bcv->value)->firstOrFail();

        $this->assertSame(ExchangeRateSeeder::INITIAL_RATE, $row->rate);
        $this->assertNotNull(app(ExchangeRateService::class)->forSource(RateSource::Bcv));
    }

    /**
     * Nace **marcada como obsoleta**, y es deliberado: es un marcador de
     * posición, no la tasa de hoy. El catálogo la muestra con su aviso de
     * desactualización en lugar de aparentar una cifra fresca que nadie ha
     * consultado.
     */
    public function test_it_is_dated_in_the_past_so_it_is_born_stale(): void
    {
        $this->seed(ExchangeRateSeeder::class);

        $snapshot = app(ExchangeRateService::class)->forSource(RateSource::Bcv);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isStale());
        $this->assertTrue($snapshot->publishedAt->isBefore(CarbonImmutable::now()->subDay()));
    }

    /**
     * `exchange_rates` es un histórico de solo `INSERT` (RB-2), así que sembrar
     * dos veces añadiría ruido a un historial que existe para poder justificar
     * pagos.
     */
    public function test_it_is_idempotent(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $this->seed(ExchangeRateSeeder::class);
        $this->seed(ExchangeRateSeeder::class);

        $this->assertSame(1, ExchangeRate::query()->count());
    }

    /**
     * Y no pisa lo que ya ingestó el job: si hay histórico, esto no tiene nada
     * que hacer.
     */
    public function test_it_does_not_seed_over_an_ingested_rate(): void
    {
        ExchangeRate::query()->create([
            'source' => RateSource::Bcv,
            'rate' => '41.00000000',
            'published_at' => CarbonImmutable::now(),
        ]);

        $this->seed(ExchangeRateSeeder::class);

        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertSame('41.00000000', ExchangeRate::query()->value('rate'));
    }

    /**
     * La siembra va en `DatabaseSeeder`, que es la que corre en producción: el
     * escenario de desarrollo es otra cosa y no debe mezclarse.
     */
    public function test_it_runs_in_every_environment(): void
    {
        $database = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertStringContainsString('ExchangeRateSeeder::class', $database);
    }
}
