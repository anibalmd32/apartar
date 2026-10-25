<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /** @var class-string<Account> */
    protected $model = Account::class;

    /**
     * @return array<model-property<Account>, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => null,
            'locale' => 'es',
            'last_login_at' => null,
        ];
    }
}
