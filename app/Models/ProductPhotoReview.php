<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Catalog\PhotoSubject;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\ProductPhotoReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int|null $requested_by
 * @property PhotoSubject $subject
 * @property string|null $observation
 * @property PhotoReviewStatus $status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Product $product
 * @property-read Organization $organization
 */
class ProductPhotoReview extends Model
{
    /** @use HasFactory<ProductPhotoReviewFactory> */
    use BelongsToOrganization, HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => PhotoSubject::class,
            'status' => PhotoReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
