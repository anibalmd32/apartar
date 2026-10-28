<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\ProductDescription;
use App\Domain\Catalog\UnavailableReason;
use App\Domain\Payment\PaymentAccountView;
use App\Http\Resources\Concerns\DescribesProductOffer;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property-read Product $resource
 */
final class ProductDetailResource extends JsonResource
{
    use DescribesProductOffer, ProjectsMoney;

    public const MAX_QUANTITY = 10;

    /**
     * @param  list<PaymentAccountView>  $paymentAccounts
     */
    public function __construct(
        Product $product,
        private readonly Catalog $catalog,
        private readonly array $paymentAccounts,
        private readonly bool $businessAccepts = true,
    ) {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $plan = FinancingPlan::tryFrom(
            $product->price_cents,
            $product->down_payment_cents,
            $product->installments_count,
            $product->frequency_days,
        );
        $reason = UnavailableReason::of($product, $plan, $this->businessAccepts);

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'price' => $this->money($product->price_cents),
            'compareAtPrice' => $product->compare_at_price_cents === null ? null : $this->money($product->compare_at_price_cents),
            'downPayment' => $this->money($product->down_payment_cents),
            'installmentAmount' => $plan === null ? null : $this->money($plan->installmentAmount()),
            'lastInstallmentAmount' => $plan !== null && $plan->hasUnevenLastInstallment() ? $this->money($plan->lastInstallmentAmount()) : null,
            'installmentsCount' => $product->installments_count,
            'frequencyDays' => $product->frequency_days,
            'categoryName' => (string) $product->category?->name,
            'tags' => $product->tags->map(fn (Tag $tag): string => $tag->name)->values()->all(),
            'inStock' => $product->stock > 0,
            'catalogSlug' => $this->catalog->slug,
            'isNew' => $this->isNew($product),
            'savingsPercent' => $this->savingsPercent($product),
            'stockLeft' => $this->stockLeft($product),
            'description' => ProductDescription::toHtml((string) $product->description),
            'sku' => $product->sku,
            'images' => $product->getMedia(Product::IMAGES)
                ->map(fn (Media $media): array => ImageSet::of($media)->toArray())
                ->values()
                ->all(),
            'specifications' => $product->specifications
                ->sortBy([['position', 'asc'], ['id', 'asc']])
                ->map(fn (ProductSpecification $specification): string => $specification->text)
                ->values()
                ->all(),
            'maxQuantity' => max(0, min($product->stock, self::MAX_QUANTITY)),
            'paymentAccounts' => array_map(fn (PaymentAccountView $account): array => $account->toArray(), $this->paymentAccounts),
            'canBeReserved' => $reason === null,
            'unavailableReason' => $reason?->message(),
        ];
    }
}
