<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /** @var class-string<ExchangeRate> */
    protected $model = ExchangeRate::class;

    /**
     * @return array<model-property<ExchangeRate>, mixed>
     */
    public function definition(): array
    {
        $publishedAt = CarbonImmutable::now()->subMinutes(15);

        return [
            'source' => RateSource::Bcv,
            'rate' => '42.50000000',
            'published_at' => $publishedAt,
            'fetched_at' => $publishedAt->addMinutes(10),
        ];
    }

    public function parallel(): static
    {
        return $this->state(fn (): array => [
            'source' => RateSource::Parallel,
            'rate' => '47.80000000',
        ]);
    }

    public function stale(): static
    {
        return $this->state(fn (): array => [
            'published_at' => CarbonImmutable::now()->subHours(30),
            'fetched_at' => CarbonImmutable::now()->subMinutes(5),
        ]);
    }
}
