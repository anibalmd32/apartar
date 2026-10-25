<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Database\Seeders\ExchangeRateSeeder;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ExchangeRateSeederTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_it_seeds_an_initial_bcv_rate(): void
    {
        $this->seed(ExchangeRateSeeder::class);

        $row = ExchangeRate::query()->where('source', RateSource::Bcv->value)->firstOrFail();

        $this->assertSame(ExchangeRateSeeder::INITIAL_RATE, $row->rate);
        $this->assertNotNull(app(ExchangeRateService::class)->forSource(RateSource::Bcv));
    }

    public function test_it_is_dated_in_the_past_so_it_is_born_stale(): void
    {
        $this->seed(ExchangeRateSeeder::class);

        $snapshot = app(ExchangeRateService::class)->forSource(RateSource::Bcv);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isStale());
        $this->assertTrue($snapshot->publishedAt->isBefore(CarbonImmutable::now()->subDay()));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $this->seed(ExchangeRateSeeder::class);
        $this->seed(ExchangeRateSeeder::class);

        $this->assertSame(1, ExchangeRate::query()->count());
    }

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

    public function test_it_runs_in_every_environment(): void
    {
        $database = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertStringContainsString('ExchangeRateSeeder::class', $database);
    }
}
