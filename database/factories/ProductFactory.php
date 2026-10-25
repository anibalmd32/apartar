<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Product> */
    protected $model = Product::class;

    /**
     * @return array<model-property<Product>, mixed>
     */
    public function definition(): array
    {
        $name = 'Producto '.Str::lower(Str::random(8));

        return [
            'category_id' => null,
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'price_cents' => 25_000,
            'compare_at_price_cents' => null,
            'down_payment_cents' => 5_000,
            'installments_count' => 4,
            'frequency_days' => 15,
            'stock' => 10,
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Archived]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock' => 0]);
    }

    public function onSale(int $compareAtCents = 30_000): static
    {
        return $this->state(fn (): array => ['compare_at_price_cents' => $compareAtCents]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn (): array => ['category_id' => $category->getKey()]);
    }
}
