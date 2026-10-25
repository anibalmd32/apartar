<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Catalog\UpsertCatalogAction;
use App\Domain\Catalog\ImageSet;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Models\Catalog;
use App\Models\Plan;
use App\Models\Product;
use App\Queries\CatalogProductsQuery;
use App\Support\CatalogShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property-read Catalog $resource
 */
final class CatalogViewResource extends JsonResource
{
    public function __construct(
        Catalog $catalog,
        private readonly Plan $plan,
        private readonly ?ImageSet $fallbackCover,
    ) {
        parent::__construct($catalog);
    }

    public static function forVisitor(Catalog $catalog, Plan $plan, CatalogProductsQuery $products): self
    {
        return new self($catalog, $plan, self::fallbackCoverOf($catalog, $products));
    }

    private static function fallbackCoverOf(Catalog $catalog, CatalogProductsQuery $products): ?ImageSet
    {
        if ($catalog->cover_path !== null && $catalog->cover_path !== '') {
            return null;
        }

        $first = $products->visible($catalog)
            ->with('media')
            ->orderBy('catalog_position')
            ->orderBy('products.id')
            ->first();

        $media = $first?->getFirstMedia(Product::IMAGES);

        return $media instanceof Media ? ImageSet::of($media) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $catalog = $this->resource;

        return [
            'slug' => $catalog->slug,
            'name' => $catalog->name,
            'description' => $catalog->description,
            'cover' => $this->cover($catalog)?->toArray(),
            'business' => OrganizationBrand::of(
                $catalog->organization,
                $this->plan->hasFeature(PlanFeature::Branding),
            )->toArray(),
            'shareUrl' => CatalogShareLink::of($catalog->slug),
            'expiresAt' => $catalog->expires_at?->toIso8601String(),
            'showsPlatformBadge' => $this->plan->showsPlatformBadge(),
        ];
    }

    private function cover(Catalog $catalog): ?ImageSet
    {
        return self::ownCoverOf($catalog) ?? $this->fallbackCover;
    }

    public static function ownCoverOf(Catalog $catalog): ?ImageSet
    {
        $path = $catalog->cover_path;

        if ($path === null || $path === '') {
            return null;
        }

        $url = Storage::disk(UpsertCatalogAction::COVER_DISK)->url($path);

        return new ImageSet($url, $url, $url, $catalog->name);
    }
}
