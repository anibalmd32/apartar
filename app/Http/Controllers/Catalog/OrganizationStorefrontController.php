<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\ImageSet;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogViewResource;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Support\CatalogShareLink;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class OrganizationStorefrontController extends Controller
{
    public const THUMBS = 3;

    public function __construct(private readonly LimitEnforcer $limits) {}

    public function __invoke(Organization $organization): Response
    {
        $plan = $this->limits->plan();

        return Inertia::render('catalog/OrganizationStorefront', [
            'business' => OrganizationBrand::of($organization, $plan->hasFeature(PlanFeature::Branding))->toArray(),
            'showsPlatformBadge' => $plan->showsPlatformBadge(),
            'catalogs' => $this->listedCatalogs(),
            'profile' => [
                'description' => $organization->description,
                'address' => $organization->address,
                'phone' => $organization->phone,
            ],
        ]);
    }

    /**
     * @return list<array{slug: string, name: string, description: string|null, cover: array{thumb: string, card: string, zoom: string, alt: string}|null, url: string, expiresAt: string|null, productsCount: int, thumbs: list<string>}>
     */
    private function listedCatalogs(): array
    {
        return Catalog::query()
            ->withCount(['products as visible_products_count' => fn ($products) => $products->published()])
            ->with(['products' => fn ($products) => $products->published()->with('media')->orderBy('catalog_product.position')->limit(self::THUMBS)])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Catalog $catalog): bool => $catalog->visibility()->appearsInStorefront())
            ->map(fn (Catalog $catalog): array => [
                'slug' => $catalog->slug,
                'name' => $catalog->name,
                'description' => $catalog->description,
                'cover' => CatalogViewResource::ownCoverOf($catalog)?->toArray(),
                'url' => CatalogShareLink::of($catalog->slug),
                'expiresAt' => $catalog->expires_at?->toIso8601String(),
                'productsCount' => (int) $catalog->getAttribute('visible_products_count'),
                'thumbs' => $catalog->products
                    ->map(fn (Product $product): ?Media => $product->getFirstMedia(Product::IMAGES))
                    ->filter(fn (?Media $media): bool => $media instanceof Media)
                    ->map(fn (Media $media): string => ImageSet::of($media)->thumb)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
