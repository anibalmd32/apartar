<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformUser>
 */
class PlatformUserFactory extends Factory
{
    /** @var class-string<PlatformUser> */
    protected $model = PlatformUser::class;

    public const PASSWORD = 'Operadora-Segura-2026';

    /**
     * @return array<model-property<PlatformUser>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::PASSWORD,
            'disabled_at' => null,
            'must_change_password' => false,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['disabled_at' => now()]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (): array => ['must_change_password' => true]);
    }
}
