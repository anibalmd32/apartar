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
 * Productos para los tests.
 *
 * No declara `organization_id`: lo resuelve `CreatesWithinTenant` al
 * persistir, igual que las demás factories del tenant.
 *
 * **Nace en borrador y sin categoría**, que es como nace un producto de verdad
 * (`REQ-CAT-007` happy path, `REQ-BOF-020` RB-5): la categoría solo se exige
 * para publicar, y `category_id` es nullable justamente por eso. Sembrarla por
 * defecto tendría además un efecto que no se ve: la factory anidada se
 * resolvería **antes** de que `CreatesWithinTenant` fijara el contexto, así
 * que un `Product::factory()->create()` sin tenant activo dejaría la categoría
 * en una organización y el producto en otra. Quien necesite categoría la pasa
 * con `inCategory()`, dentro de su contexto.
 *
 * El dinero va en céntimos enteros y no en `Money`: `MoneyCast` admite las dos
 * formas al escribir, y el entero es lo que hace legible de un vistazo que
 * `down_payment_cents` es menor que `price_cents` (`REQ-BOF-020` RB-1).
 *
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
            // `sku` y `slug` son únicos **por organización** ([05 §4]), así que
            // el sufijo aleatorio basta: dos negocios pueden repetir el mismo
            // código de fábrica y eso es intencional (`REQ-BOF-020` RB-3).
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

    /**
     * Publicado y ya en fecha: el único estado que se renderiza y sobre el que
     * se puede apartar (`REQ-CAT-007` RB-1).
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * Publicación programada (`REQ-CAT-007` RB-5): el estado es `published` y
     * la fecha todavía no ha llegado, así que la consulta no lo lista.
     *
     * El estado y el reloj son cosas distintas y esta es la combinación que lo
     * demuestra.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    /**
     * Archivado: invisible, sin apartados nuevos y **conservando su relación
     * con los catálogos** (`REQ-CAT-007` RB-7).
     */
    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Archived]);
    }

    /**
     * Agotado sin dejar de estar publicado (`REQ-CAT-007` RB-4): sigue en el
     * catálogo con su sello, y lo que se deshabilita es el apartado.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock' => 0]);
    }

    /**
     * Con precio tachado, que es el caso en que `compare_at_price_cents` deja
     * de ser nulo.
     */
    public function onSale(int $compareAtCents = 30_000): static
    {
        return $this->state(fn (): array => ['compare_at_price_cents' => $compareAtCents]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn (): array => ['category_id' => $category->getKey()]);
    }
}
