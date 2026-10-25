<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentAccount;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAccount>
 */
class PaymentAccountFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<PaymentAccount> */
    protected $model = PaymentAccount::class;

    /**
     * @return array<model-property<PaymentAccount>, mixed>
     */
    public function definition(): array
    {
        return [
            'method' => 'pago_movil',
            'bank' => fake()->randomElement(['Banesco', 'Mercantil', 'Provincial', 'Venezuela']),
            'holder_name' => fake()->name(),
            'holder_document' => 'V-'.fake()->numerify('########'),
            'identifier' => '0414'.fake()->numerify('#######'),
            'instructions' => null,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function transferencia(): static
    {
        return $this->state(fn (): array => [
            'method' => 'transferencia',
            'identifier' => fake()->numerify('####################'),
        ]);
    }

    public function zelle(): static
    {
        return $this->state(fn (): array => [
            'method' => 'zelle',
            'bank' => null,
            'identifier' => fake()->unique()->safeEmail(),
        ]);
    }

    public function efectivo(): static
    {
        return $this->state(fn (): array => [
            'method' => 'efectivo_usd',
            'bank' => null,
            'identifier' => fake()->streetAddress(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
