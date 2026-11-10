<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Catalog\PhotoSubject;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPhotoReview>
 */
class ProductPhotoReviewFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<ProductPhotoReview> */
    protected $model = ProductPhotoReview::class;

    /**
     * @return array<model-property<ProductPhotoReview>, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'subject' => PhotoSubject::Animal,
            'observation' => 'Se ve un gato sobre un sofá.',
            'status' => PhotoReviewStatus::Pending,
        ];
    }

    public function rejected(string $reason = 'No vendemos animales.'): static
    {
        return $this->state(fn (): array => [
            'status' => PhotoReviewStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => PhotoReviewStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }
}
