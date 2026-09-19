<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Categorías para los tests.
 *
 * No declara `organization_id`: lo resuelve `CreatesWithinTenant` al
 * persistir, igual que las demás factories del tenant.
 *
 * `name` y `slug` salen con sufijo aleatorio y **derivan el uno del otro**.
 * Los dos son únicos por organización en la base (`REQ-BOF-023` RB-3), así
 * que un nombre de diccionario repetido haría chocar dos categorías del mismo
 * negocio; y si el slug no saliera del nombre, el test que comprueba que «el
 * mismo nombre en dos organizaciones está permitido» pasaría por el motivo
 * equivocado.
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Category> */
    protected $model = Category::class;

    /**
     * @return array<model-property<Category>, mixed>
     */
    public function definition(): array
    {
        $name = 'Categoría '.Str::lower(Str::random(8));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'position' => 0,
        ];
    }

    /**
     * Una categoría con nombre y slug fijos, para el test que necesita
     * nombrarla.
     */
    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
