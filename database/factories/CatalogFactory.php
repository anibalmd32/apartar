<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Catalog;
use Database\Factories\Concerns\CreatesWithinTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Catalog>
 */
class CatalogFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Catalog> */
    protected $model = Catalog::class;

    /**
     * @return array<model-property<Catalog>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Catálogo '.fake()->word(),
            'slug' => 'catalogo-'.Str::lower(Str::random(10)),
            'description' => null,
            'cover_path' => null,
            'is_listed' => true,
            'published_at' => null,
            'expires_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['published_at' => now()->subDay()]);
    }

    public function unlisted(): static
    {
        return $this->state(fn (): array => ['is_listed' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'published_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiresAt(DateTimeInterface $expiresAt): static
    {
        return $this->state(fn (): array => ['expires_at' => $expiresAt]);
    }
}
