<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    public const INITIAL_RATE = '36.50000000';

    public const PUBLISHED_AT = '2000-01-01 00:00:00';

    public function run(): void
    {
        if (ExchangeRate::query()->where('source', RateSource::Bcv->value)->exists()) {
            return;
        }

        ExchangeRate::query()->create([
            'source' => RateSource::Bcv,
            'rate' => self::INITIAL_RATE,
            'published_at' => self::publishedAt(),
        ]);
    }

    public static function publishedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::PUBLISHED_AT, 'America/Caracas')->setTimezone(date_default_timezone_get());
    }
}
