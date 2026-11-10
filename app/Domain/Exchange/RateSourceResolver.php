<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;

final readonly class RateSourceResolver
{
    public function __construct(
        private ExchangeRateService $rates,
        private TenantContext $context,
    ) {}

    public static function override(?Product $product, ?Catalog $catalog): ?RateSource
    {
        return $product->rate_source ?? $catalog->rate_source ?? null;
    }

    public function forProduct(Organization $organization, ?Product $product, ?Catalog $catalog): RateSource
    {
        return self::override($product, $catalog) ?? $this->rates->sourceFor($organization);
    }

    public function forLayaway(Layaway $layaway): RateSource
    {
        $organization = Organization::query()->findOrFail($layaway->organization_id);

        return $this->forProduct($organization, ...$this->overridesOf($layaway, $organization));
    }

    public function overrideForLayaway(Layaway $layaway): ?RateSource
    {
        return self::override(...$this->overridesOf($layaway, Organization::query()->findOrFail($layaway->organization_id)));
    }

    /**
     * @return array{0: Product|null, 1: Catalog|null}
     */
    private function overridesOf(Layaway $layaway, Organization $organization): array
    {
        return $this->context->runFor($organization, fn (): array => [
            Product::query()->withTrashed()->whereKey($layaway->product_id)->first(['id', 'organization_id', 'rate_source']),
            $layaway->catalog_id === null ? null : Catalog::query()->whereKey($layaway->catalog_id)->first(['id', 'organization_id', 'rate_source']),
        ]);
    }
}
