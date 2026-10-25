<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $document
 * @property string|null $phone
 * @property string|null $tagline
 * @property string|null $description
 * @property string|null $address
 * @property string $timezone
 * @property string|null $logo_path
 * @property string|null $brand_color
 * @property bool $storefront_enabled
 * @property OrganizationStatus $status
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'document',
        'phone',
        'timezone',
        'logo_path',
        'brand_color',
        'storefront_enabled',
        'status',
        'suspended_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storefront_enabled' => 'boolean',
            'status' => OrganizationStatus::class,
            'suspended_at' => 'datetime',
        ];
    }
}
