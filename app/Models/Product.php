<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que el negocio vende (`REQ-CAT-007`, `REQ-BOF-020`).
 *
 * Tabla del tenant y la pieza central de H2. El modelo es anémico a propósito
 * (`REQ-QAX-001` RB-3): las transiciones de estado son de
 * `ChangeProductStatusAction` (`T-030`), la búsqueda y los scopes del catálogo
 * de `T-034`, y las imágenes de `T-029`.
 *
 * ## El dinero sale como `Money`, no como `int`
 *
 * Las tres columnas `*_cents` son `BIGINT` de centavos de USD (`REQ-FX-001`
 * RB-1) y **este es el primer modelo del proyecto que las tiene**. Pasan por
 * `MoneyCast`, que es lo que impide que un importe salga como número suelto y
 * alguien lo sume con otro de otra moneda o lo divida con una barra.
 * `compare_at_price_cents` nulo devuelve **`null`** y no `Money(0)`: «sin
 * precio de comparación» y «precio de comparación de cero dólares» son cosas
 * distintas, y la segunda se pinta como un descuento del 100 %.
 *
 * Aquí no se hace ninguna cuenta con esas cifras. La cuota mostrada y el plan
 * de pago son de `ScheduleGenerator` (`T-041`) y la proyección a bolívares de
 * `ProjectsMoney`.
 *
 * ## El borrado es lógico y el estado no depende del stock
 *
 * `SoftDeletes` porque un producto con apartados **nunca** se borra
 * físicamente (`REQ-CAT-007` RB-2): los apartados vigentes operan sobre su
 * *snapshot*. Y `stock = 0` no cambia el estado (RB-4): sigue `published`,
 * visible con su sello de agotado.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $category_id nulo mientras el producto es borrador (`REQ-BOF-020` RB-5)
 * @property string $sku único por organización, no en la plataforma
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Money $price_cents precio en USD
 * @property Money|null $compare_at_price_cents precio tachado; nulo si no hay
 * @property Money $down_payment_cents cuota inicial
 * @property int $installments_count
 * @property int $frequency_days días entre cuotas
 * @property int $stock
 * @property ProductStatus $status
 * @property \Illuminate\Support\Carbon|null $published_at futuro = publicación programada (RB-5)
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * `organization_id` queda fuera: lo rellena el trait (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'slug',
        'description',
        'price_cents',
        'compare_at_price_cents',
        'down_payment_cents',
        'installments_count',
        'frequency_days',
        'stock',
        'status',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => MoneyCast::class,
            'compare_at_price_cents' => MoneyCast::class,
            'down_payment_cents' => MoneyCast::class,
            'installments_count' => 'integer',
            'frequency_days' => 'integer',
            'stock' => 'integer',
            'status' => ProductStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * La categoría a la que pertenece, si ya la tiene.
     *
     * Nullable porque un borrador puede estar incompleto: la categoría solo se
     * exige para publicar (`REQ-BOF-020` RB-5).
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Las etiquetas del producto (`REQ-CAT-006` RB-2).
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * La ficha técnica, fila a fila (`REQ-CAT-004` RB-1).
     *
     * Sin orden declarado aquí: agrupar por `group` y ordenar por `position` y
     * luego por `id` (RB-2, RB-3) es trabajo de quien la pinta,
     * `ProductDetailResource`, y meterlo en la relación lo escondería del sitio
     * donde la regla se lee.
     *
     * @return HasMany<ProductSpecification, $this>
     */
    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class);
    }

    /**
     * Los catálogos del negocio en los que está (`REQ-CAT-023` RB-1).
     *
     * `withPivot('position', 'created_at')` y **no `withTimestamps()`**: la
     * tabla no tiene `updated_at` a propósito, y pedírselo haría que cada
     * `attach()` intentara escribir una columna que no existe. El `created_at`
     * lo pone la base (`useCurrent`) y registra cuándo entró el producto a ese
     * catálogo, que es lo que ordena el feed y decide qué es novedad (RB-3).
     *
     * @return BelongsToMany<Catalog, $this>
     */
    public function catalogs(): BelongsToMany
    {
        return $this->belongsToMany(Catalog::class)->withPivot('position', 'created_at');
    }
}
