<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Database\Seeders\ExchangeRateSeeder;
use Tests\Concerns\ReusesMigratedDatabase;

uses(ReusesMigratedDatabase::class);

function rateAt(RateSource $source, string $rate, string $publishedAt): ExchangeRate
{
    return ExchangeRate::query()->create([
        'source' => $source,
        'rate' => $rate,
        'published_at' => CarbonImmutable::parse($publishedAt, 'America/Caracas')->setTimezone(date_default_timezone_get()),
    ]);
}

it('saca la tasa inicial de relleno de los días recientes para que el histórico traiga la real', function (): void {
    $placeholder = rateAt(RateSource::Bcv, ExchangeRateSeeder::INITIAL_RATE, '2026-10-30 14:23:00');
    $realOld = rateAt(RateSource::Bcv, ExchangeRateSeeder::INITIAL_RATE, '2024-03-01 00:00:00');
    $realRecent = rateAt(RateSource::Bcv, '889.25530000', '2026-10-29 00:00:00');
    $parallel = rateAt(RateSource::Parallel, ExchangeRateSeeder::INITIAL_RATE, '2026-10-30 14:23:00');

    (require database_path('migrations/2026_11_02_000001_move_initial_rate_out_of_recent_history.php'))->up();

    expect($placeholder->fresh()->published_at->equalTo(ExchangeRateSeeder::publishedAt()))->toBeTrue()
        ->and($realOld->fresh()->published_at->equalTo($realOld->published_at))->toBeTrue()
        ->and($realRecent->fresh()->published_at->equalTo($realRecent->published_at))->toBeTrue()
        ->and($parallel->fresh()->published_at->equalTo($parallel->published_at))->toBeTrue();
});
