<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ImageSet;
use App\Http\Resources\Concerns\DescribesProductOffer;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property-read Product $resource
 */
final class ProductCardResource extends JsonResource
{
    use DescribesProductOffer, ProjectsMoney;

    public function __construct(Product $product, private readonly Catalog $catalog)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        $plan = new FinancingPlan(
            $product->price_cents,
            $product->down_payment_cents,
            $product->installments_count,
            $product->frequency_days,
        );

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'price' => $this->money($product->price_cents),
            'compareAtPrice' => $product->compare_at_price_cents === null
                ? null
                : $this->money($product->compare_at_price_cents),
            'downPayment' => $this->money($plan->downPayment),
            'installmentAmount' => $this->money($plan->installmentAmount()),
            'lastInstallmentAmount' => $plan->hasUnevenLastInstallment()
                ? $this->money($plan->lastInstallmentAmount())
                : null,
            'installmentsCount' => $plan->installmentsCount,
            'frequencyDays' => $plan->frequencyDays,
            'image' => $this->image($product),
            'categoryName' => (string) $product->category?->name,
            'tags' => $product->tags->map(fn (Tag $tag): string => $tag->name)->values()->all(),
            'inStock' => $product->stock > 0,
            'catalogSlug' => $this->catalog->slug,
            'isNew' => $this->isNew($product),
            'savingsPercent' => $this->savingsPercent($product),
        ];
    }

    /**
     * @return array{thumb: string, card: string, zoom: string, alt: string}|null
     */
    private function image(Product $product): ?array
    {
        $media = $product->getFirstMedia(Product::IMAGES);

        return $media instanceof Media ? ImageSet::of($media)->toArray() : null;
    }
}
