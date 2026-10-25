<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\SearchMode;
use App\Domain\Layaway\LayawayStatus;
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
 * @property int $id
 * @property int $organization_id
 * @property int|null $category_id
 * @property string $sku
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Money $price_cents
 * @property Money|null $compare_at_price_cents
 * @property Money $down_payment_cents
 * @property int $installments_count
 * @property int $frequency_days
 * @property int $stock
 * @property int $stock_shortfall
 * @property ProductStatus $status
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $updated_by
 */
class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToOrganization, HasFactory, InteractsWithMedia, RecordsLastEditor, SoftDeletes;

    public const IMAGES = 'images';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock_shortfall' => 0,
    ];

    /**
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
            'stock_shortfall' => 'integer',
            'status' => ProductStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return HasMany<ProductSpecification, $this>
     */
    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class);
    }

    /**
     * @return BelongsToMany<Catalog, $this>
     */
    public function catalogs(): BelongsToMany
    {
        return $this->belongsToMany(Catalog::class)->withPivot('position', 'created_at');
    }

    /**
     * @return HasMany<Layaway, $this>
     */
    public function layaways(): HasMany
    {
        return $this->hasMany(Layaway::class);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeWithReservedUnits(Builder $query): Builder
    {
        return $query->withSum(
            ['layaways as reserved_units' => fn (Builder $layaways): Builder => $layaways->whereIn('status', LayawayStatus::holdingStock())],
            'quantity',
        );
    }

    public function loadReservedUnits(): static
    {
        return $this->loadSum(
            ['layaways as reserved_units' => fn (Builder $layaways): Builder => $layaways->whereIn('status', LayawayStatus::holdingStock())],
            'quantity',
        );
    }

    public function reservedUnits(): int
    {
        if (array_key_exists('reserved_units', $this->getAttributes())) {
            return (int) $this->getAttribute('reserved_units');
        }

        return (int) $this->layaways()->whereIn('status', LayawayStatus::holdingStock())->sum('quantity');
    }

    public function onHandUnits(): int
    {
        $reserved = $this->reservedUnits();

        return $this->stock + $reserved - $this->boundedShortfall($reserved);
    }

    public function shortfallUnits(): int
    {
        return $this->boundedShortfall($this->reservedUnits());
    }

    private function boundedShortfall(int $reserved): int
    {
        return min((int) $this->getAttribute('stock_shortfall'), $reserved);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGES)
            ->acceptsMimeTypes(array_keys(RealMimeType::ALLOWED));
    }

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

    public function hasImages(): bool
    {
        return $this->hasMedia(self::IMAGES);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('status'), ProductStatus::Published)
            ->whereNotNull($this->qualifyColumn('published_at'))
            ->where($this->qualifyColumn('published_at'), '<=', now());
    }

    /**
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

    public function isScheduled(): bool
    {
        return $this->status === ProductStatus::Published
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }
}
