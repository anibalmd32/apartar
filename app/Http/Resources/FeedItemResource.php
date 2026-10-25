<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Support\CatalogShareLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class FeedItemResource
{
    /**
     * @param  array{product: Product, catalog: Catalog, organization: Organization, enteredAt: CarbonImmutable, isNew: bool}  $item
     * @return array<string, mixed>
     */
    public static function toArray(array $item, Request $request): array
    {
        return app(TenantContext::class)->runFor($item['organization'], fn (): array => [
            'product' => (new ProductCardResource($item['product'], $item['catalog']))->resolve($request),
            'businessName' => $item['organization']->name,
            'catalogName' => $item['catalog']->name,
            'catalogUrl' => CatalogShareLink::of($item['catalog']->slug),
            'isNew' => $item['isNew'],
            'enteredAt' => $item['enteredAt']->toIso8601String(),
            'url' => route(CatalogShareLink::PRODUCT_ROUTE, ['catalog' => $item['catalog']->slug, 'product' => $item['product']->slug]),
        ]);
    }
}
