<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Account\AccountProvider;
use App\Models\Account;
use App\Models\AccountIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountIdentity>
 */
class AccountIdentityFactory extends Factory
{
    /** @var class-string<AccountIdentity> */
    protected $model = AccountIdentity::class;

    /**
     * @return array<model-property<AccountIdentity>, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'provider' => AccountProvider::Google,
            'provider_id' => fake()->unique()->uuid(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
