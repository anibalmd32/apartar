<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogSubscription>
 */
class CatalogSubscriptionFactory extends Factory
{
    /** @var class-string<CatalogSubscription> */
    protected $model = CatalogSubscription::class;

    /**
     * @return array<model-property<CatalogSubscription>, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'catalog_id' => Catalog::factory(),
            'subscribed_at' => CarbonImmutable::now(),
            'muted_at' => null,
        ];
    }

    public function muted(): static
    {
        return $this->state(fn (): array => [
            'muted_at' => CarbonImmutable::now(),
        ]);
    }
}
