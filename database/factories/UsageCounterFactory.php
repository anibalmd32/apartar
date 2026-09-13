<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscription\UsageMetric;
use App\Models\UsageCounter;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Contadores de consumo para los tests.
 *
 * @extends Factory<UsageCounter>
 */
class UsageCounterFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<UsageCounter> */
    protected $model = UsageCounter::class;

    /**
     * @return array<model-property<UsageCounter>, mixed>
     */
    public function definition(): array
    {
        return [
            'metric' => UsageMetric::Products,
            'value' => 0,
            'computed_at' => now(),
        ];
    }

    public function forMetric(UsageMetric $metric, int $value = 0): static
    {
        return $this->state(fn (): array => [
            'metric' => $metric,
            'value' => $value,
        ]);
    }
}
