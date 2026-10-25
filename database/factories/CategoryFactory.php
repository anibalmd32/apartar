<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
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

    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
