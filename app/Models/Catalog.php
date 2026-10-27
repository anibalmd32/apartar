<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\PublicCatalogAccess;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Models\Concerns\RecordsLastEditor;
use Database\Factories\CatalogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $cover_path
 * @property bool $is_listed
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $withdrawn_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $updated_by
 */
class Catalog extends Model
{
    /** @use HasFactory<CatalogFactory> */
    use BelongsToOrganization, HasFactory, RecordsLastEditor;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_path',
        'is_listed',
        'published_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_listed' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function visibility(): CatalogVisibility
    {
        if ($this->published_at === null) {
            return CatalogVisibility::Draft;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return CatalogVisibility::Expired;
        }

        return $this->is_listed
            ? CatalogVisibility::Listed
            : CatalogVisibility::Unlisted;
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position', 'created_at');
    }

    /**
     * @return HasMany<CatalogSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(CatalogSubscription::class);
    }

    /**
     * @param  string  $childType
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveChildRouteBinding($childType, $value, $field): ?Model
    {
        if ($childType !== 'product') {
            return parent::resolveChildRouteBinding($childType, $value, $field);
        }

        PublicCatalogAccess::ensureOpen($this);

        return $this->products()
            ->published()
            ->where((new Product)->qualifyColumn($field ?? 'slug'), $value)
            ->first();
    }
}
