<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSpecification;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Líneas de ficha técnica para los tests.
 *
 * ## Se siembra **desde su producto**, y no al revés
 *
 * La forma prevista es `Product::factory()->has(ProductSpecification::factory()
 * ->count(3), 'specifications')`, o `->for($product)` dentro de un contexto ya
 * fijado. La que no existe es `ProductSpecification::factory()->create()` a
 * secas, y no es un descuido: una factory anidada se resuelve **antes** de que
 * `CreatesWithinTenant` entre a fijar el tenant, así que un `product_id` por
 * defecto crearía el producto en una organización y la ficha en otra —una fila
 * cruzada que ninguna consulta filtrada volvería a ver entera, y que ningún
 * test se pondría rojo por producir—. Sembrada desde el padre, en cambio, los
 * hijos de `has()` se guardan dentro del `runFor()` del producto y heredan su
 * organización.
 *
 * `group` es nulo por defecto: una ficha corta no necesita secciones, y esas
 * líneas se pintan bajo el rótulo «General» (`REQ-CAT-004` RB-2).
 *
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
            'group' => null,
            'label' => 'Característica '.Str::lower(Str::random(6)),
            'value' => Str::lower(Str::random(12)),
            'position' => 0,
        ];
    }

    /**
     * Una línea dentro de una sección con nombre.
     */
    public function inGroup(string $group): static
    {
        return $this->state(fn (): array => ['group' => $group]);
    }

    /**
     * Para el test que ya tiene el producto y está situado en su organización.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }
}
