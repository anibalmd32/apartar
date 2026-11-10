<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Catalog\ProductDescription;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductPhotoReviewInbox
{
    public const LATE_HOURS = 48;

    public const LIMIT = 100;

    /**
     * @return list<array{id: int, organization: array{id: int, name: string}, product: array{id: int, name: string, description: string}, subject: string, observation: string|null, requestedAt: string, isLate: bool, photos: list<array{thumb: string, card: string, zoom: string, alt: string}>}>
     */
    public function pending(): array
    {
        $now = CarbonImmutable::now();

        return ProductPhotoReview::query()->withoutTenantScope()
            ->with([
                'organization:id,name',
                'product' => fn ($query) => $query->withoutTenantScope()->with('media'),
            ])
            ->where('status', PhotoReviewStatus::Pending)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ProductPhotoReview $review): array => $this->row($review, $now))
            ->values()
            ->all();
    }

    public function pendingCount(): int
    {
        return DB::table('product_photo_reviews')->where('status', PhotoReviewStatus::Pending->value)->count();
    }

    public function lateCount(CarbonImmutable $now): int
    {
        return DB::table('product_photo_reviews')
            ->where('status', PhotoReviewStatus::Pending->value)
            ->where('created_at', '<', $now->subHours(self::LATE_HOURS))
            ->count();
    }

    public function find(int $reviewId): ?ProductPhotoReview
    {
        return ProductPhotoReview::query()->withoutTenantScope()->find($reviewId);
    }

    /**
     * @return array{id: int, organization: array{id: int, name: string}, product: array{id: int, name: string, description: string}, subject: string, observation: string|null, requestedAt: string, isLate: bool, photos: list<array{thumb: string, card: string, zoom: string, alt: string}>}
     */
    private function row(ProductPhotoReview $review, CarbonImmutable $now): array
    {
        $product = $review->product;

        return [
            'id' => (int) $review->getKey(),
            'organization' => ['id' => (int) $review->organization->getKey(), 'name' => $review->organization->name],
            'product' => [
                'id' => (int) $product->getKey(),
                'name' => $product->name,
                'description' => ProductDescription::plainText((string) $product->description),
            ],
            'subject' => $review->subject->label(),
            'observation' => $review->observation,
            'requestedAt' => $review->created_at->toIso8601String(),
            'isLate' => $review->created_at->lessThan($now->subHours(self::LATE_HOURS)),
            'photos' => $product->getMedia(Product::IMAGES)
                ->map(fn (Media $media): array => ImageSet::of($media)->toArray())
                ->values()
                ->all(),
        ];
    }
}
