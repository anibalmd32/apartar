<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\SearchMode;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Models\Concerns\RecordsLastEditor;
use App\Rules\RealMimeType;
use App\Support\FullTextTerm;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\JoinClause;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Lo que el negocio vende (`REQ-CAT-007`, `REQ-BOF-020`).
 *
 * Tabla del tenant y la pieza central de H2. El modelo es anémico a propósito
 * (`REQ-QAX-001` RB-3): las transiciones de estado son de
 * `ChangeProductStatusAction`, y decidir cómo se busca, de
 * `CatalogProductsQuery`. Lo que sí vive aquí es **leer** —`scopePublished()`,
 * `scopeInCatalog()`, `scopeSearch()`, `isScheduled()`, `hasImages()`—, porque
 * son preguntas sobre la fila y no decisiones sobre ella.
 *
 * ## El dinero sale como `Money`, no como `int`
 *
 * Las tres columnas `*_cents` son `BIGINT` de centavos de USD (`REQ-FX-001`
 * RB-1) y pasan por `MoneyCast`, que impide que un importe salga como número
 * suelto y alguien lo sume con otro de otra moneda. `compare_at_price_cents`
 * nulo devuelve **`null`** y no `Money(0)`: «sin precio de comparación» y
 * «precio de cero dólares» son cosas distintas, y la segunda se pinta como un
 * descuento del 100 %. Aquí no se hace ninguna cuenta: el plan de pago es de
 * `FinancingPlan` y la proyección a bolívares de `ProjectsMoney`.
 *
 * `SoftDeletes` porque un producto con apartados **nunca** se borra
 * físicamente (`REQ-CAT-007` RB-2): los apartados operan sobre su *snapshot*.
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
 * @property int $stock cero **no** cambia el estado: sigue publicado y agotado (`REQ-CAT-007` RB-4)
 * @property ProductStatus $status
 * @property \Illuminate\Support\Carbon|null $published_at futuro = publicación programada (RB-5)
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $updated_by quién lo modificó por última vez (`REQ-BOF-020` SP-7); ver `RecordsLastEditor`
 */
class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToOrganization, HasFactory, InteractsWithMedia, RecordsLastEditor, SoftDeletes;

    /** La única colección de medios del producto: sus fotos. */
    public const IMAGES = 'images';

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
     * La categoría a la que pertenece, si ya la tiene: nullable porque solo se
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
     * Sin orden declarado aquí: agrupar y ordenar es de `SpecificationGroups`,
     * y meterlo en la relación lo escondería del sitio donde la regla se lee.
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
     * `attach()` escribiera una columna que no existe. El `created_at` lo pone
     * la base y decide qué es novedad en el feed (RB-3).
     *
     * @return BelongsToMany<Catalog, $this>
     */
    public function catalogs(): BelongsToMany
    {
        return $this->belongsToMany(Catalog::class)->withPivot('position', 'created_at');
    }

    /**
     * Las fotos del producto (`REQ-BOF-022`, `REQ-CAT-005`).
     *
     * Una sola colección. La ruta física la decide `TenantPathGenerator`, con
     * el `organization_id` dentro (RB-9).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGES)
            ->acceptsMimeTypes(array_keys(RealMimeType::ALLOWED));
    }

    /**
     * Los tres tamaños de `REQ-CAT-005`, en WebP.
     *
     * Las anchuras y la calidad salen de `ImageSet`, donde está escrito por
     * qué son esas: el mismo número se lee aquí y en quien sirve la URL.
     * **`queued()`** es RB-1 —nunca en el request de subida— y quien las hace
     * es `TranscodeProductImage`; va **primero** porque las manipulaciones se
     * reenvían al driver de imagen y encadenarlo al final no tipa. `width` a
     * secas escala sin recortar: el recorte decidiría por el negocio qué parte
     * de su producto se ve.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (ImageSet::CONVERSIONS as $name => $width) {
            $this->addMediaConversion($name)
                ->queued()
                ->format('webp')
                ->quality(ImageSet::QUALITY)
                ->width($width);
        }
    }

    /**
     * Si el producto tiene al menos una foto.
     *
     * Existe para `T-030`: la completitud de `REQ-BOF-020` RB-5 se comprueba
     * entera en la transición a `published`. Aquí solo sabe contestar.
     */
    public function hasImages(): bool
    {
        return $this->hasMedia(self::IMAGES);
    }

    /**
     * Los productos que de verdad se pueden mostrar hoy
     * (`REQ-CAT-007` RB-1, RB-5).
     *
     * El estado **y el reloj**, los dos en la consulta: `published_at` futuro
     * es publicación programada y RB-5 dice explícitamente que se evalúa aquí
     * y **sin job**, que de otro modo sería un segundo mecanismo capaz de no
     * correr. El porqué entero está en `ChangeProductStatusAction`, que es
     * quien escribe la columna. `whereNotNull` es redundante en SQL —`NULL <=
     * now()` no es cierto— y se escribe igual: auditar si un `published` sin
     * fecha se cuela no debería exigir saberse la lógica de tres valores de
     * MySQL.
     *
     * **No sabe en qué catálogo está el producto.** La visibilidad real es la
     * intersección con la accesibilidad del catálogo (RB-6) y la cruza la
     * consulta pública de `REQ-CAT-021` (`T-033`); lo que esto garantiza es
     * que un `draft` o un `archived` **no tengan por dónde entrar** en ella,
     * que es el mecanismo detrás del `404` de SP-1. Lo borrado y lo ajeno ya
     * quedan fuera por sus dos *global scopes*.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        // Cualificadas: esta consulta va unida a `catalog_product` en la
        // parrilla y dentro de los conteos de las facetas (`T-034`).
        return $query
            ->where($this->qualifyColumn('status'), ProductStatus::Published)
            ->whereNotNull($this->qualifyColumn('published_at'))
            ->where($this->qualifyColumn('published_at'), '<=', now());
    }

    /**
     * Los productos que están en ese catálogo (`REQ-CAT-002` RB-1,
     * `REQ-CAT-021` RB-5).
     *
     * Un `JOIN` con `catalog_product` y **sin tocar el `SELECT`**, a
     * propósito: es la misma acotación en la parrilla, que necesita las
     * columnas del pivote para ordenar, y dentro del `withCount()` de
     * `CatalogFacetsQuery`, donde un `select()` sustituiría el `count(*)` por
     * otra cosa. Que las dos pasen por aquí es lo que impide que el panel
     * cuente un conjunto y la parrilla filtre otro.
     *
     * No acota la organización, ni lo necesita: el catálogo ya es del tenant, y
     * un producto ajeno enlazado a él a mano en el pivote lo sigue dejando
     * fuera el *global scope* de `products`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInCatalog(Builder $query, Catalog $catalog): Builder
    {
        return $query->join('catalog_product', function (JoinClause $join) use ($catalog): void {
            $join->on('catalog_product.product_id', '=', $this->qualifyColumn('id'))
                ->where('catalog_product.catalog_id', '=', $catalog->getKey());
        });
    }

    /**
     * Los productos que responden al término, en el modo que se pida
     * (`REQ-CAT-002` RB-2, RB-7).
     *
     * El término llega ya saneado —`FullTextTerm` es quien garantiza que no
     * lleva operadores—, y el modo lo elige quien sabe si el índice encontró
     * algo, que es `CatalogProductsQuery`. `FullText` busca en las tres
     * columnas del índice; `Like` exige cada palabra en el nombre **o** en el
     * SKU, y es el que encuentra «tv» o «ladro».
     *
     * Los acentos coinciden en los dos modos por la *collation*
     * `utf8mb4_unicode_ci` de las columnas (SP-4), no por nada que se haga
     * aquí.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, FullTextTerm $term, SearchMode $mode): Builder
    {
        $boolean = $term->booleanQuery();

        if ($mode === SearchMode::FullText && $boolean !== null) {
            return $query->whereFullText(FullTextTerm::COLUMNS, $boolean, ['mode' => 'boolean']);
        }

        foreach ($term->likePatterns() as $pattern) {
            $query->where(fn (Builder $word): Builder => $word
                ->where($this->qualifyColumn('name'), 'like', $pattern)
                ->orWhere($this->qualifyColumn('sku'), 'like', $pattern));
        }

        return $query;
    }

    /**
     * Publicado con fecha futura: el sello «Programado» del backoffice (SP-5).
     *
     * Es la mitad por fila de lo que `scopePublished()` decide por consulta
     * —aquella elige **qué se lista** y esta **qué se le dice al negocio**
     * sobre una fila que ya tiene delante—, y las dos miran la misma columna
     * contra el mismo reloj sin guardar nada, que es lo que impide que digan
     * cosas distintas.
     */
    public function isScheduled(): bool
    {
        return $this->status === ProductStatus::Published
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }
}
