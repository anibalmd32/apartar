<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSpecification;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductSpecification>
 */
class ProductSpecificationFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<ProductSpecification> */
    protected $model = ProductSpecification::class;

    /**
     * @return array<model-property<ProductSpecification>, mixed>
     */
    public function definition(): array
    {
        return [
            'text' => 'Característica '.Str::lower(Str::random(12)),
            'position' => 0,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }
}
