<?php

declare(strict_types=1);

use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const RECENT_SINCE = '2025-01-01 00:00:00';

    public function up(): void
    {
        DB::table('exchange_rates')
            ->where('source', 'bcv')
            ->where('rate', ExchangeRateSeeder::INITIAL_RATE)
            ->where('published_at', '>=', self::RECENT_SINCE)
            ->update(['published_at' => ExchangeRateSeeder::publishedAt()]);
    }

    public function down(): void {}
};
