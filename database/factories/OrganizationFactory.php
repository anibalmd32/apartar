<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Organizaciones para los tests y para `DevSeeder`.
 *
 * El `slug` se genera con un sufijo aleatorio porque es `UNIQUE` global: dos
 * ferreterías llamadas igual en el mismo escenario no pueden chocar, y
 * `REQ-QAX-003` RB-10 exige que **todo test de feature cree al menos dos
 * organizaciones**, así que la colisión sería la norma y no la excepción.
 *
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

    /**
     * Organización suspendida por impago.
     *
     * Su equipo pierde el backoffice; sus clientes no pierden nada
     * (`REQ-ORG-008` RB-1).
     */
    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    /**
     * Organización dada de baja por su `owner`, con los datos conservados.
     */
    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Closed,
        ]);
    }
}
