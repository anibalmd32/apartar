<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /** @var class-string<Organization> */
    protected $model = Organization::class;

    /**
     * @return array<model-property<Organization>, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'document' => 'J-'.fake()->numerify('########').'-'.fake()->numberBetween(0, 9),
            'timezone' => 'America/Caracas',
            'logo_path' => null,
            'brand_color' => fake()->hexColor(),
            'storefront_enabled' => false,
            'status' => OrganizationStatus::Active,
            'suspended_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Closed,
        ]);
    }
}
