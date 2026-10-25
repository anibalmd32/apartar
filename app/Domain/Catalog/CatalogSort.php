<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\SortWhitelist;

enum CatalogSort: string
{
    case Curated = 'curated';

    case Recent = 'recent';

    case PriceAsc = 'price_asc';

    case PriceDesc = 'price_desc';

    case Name = 'name';

    case Relevance = 'relevance';

    /**
     * @var list<string>
     */
    public const SELECTS = [
        'catalog_product.position as catalog_position',
        'catalog_product.created_at as catalog_added_at',
        'products.price_cents as sort_price',
    ];

    /**
     * @return SortWhitelist<self>
     */
    public static function whitelist(): SortWhitelist
    {
        return new SortWhitelist([self::Curated, self::Recent, self::PriceAsc, self::PriceDesc, self::Name]);
    }

    /**
     * @return array<string, 'asc'|'desc'>|null
     */
    public function keyset(): ?array
    {
        return match ($this) {
            self::Curated => ['catalog_position' => 'asc', 'products.id' => 'asc'],
            self::Recent => ['catalog_added_at' => 'desc', 'products.id' => 'desc'],
            self::PriceAsc => ['sort_price' => 'asc', 'products.id' => 'asc'],
            self::PriceDesc => ['sort_price' => 'desc', 'products.id' => 'desc'],
            self::Name => ['products.name' => 'asc', 'products.id' => 'asc'],
            self::Relevance => null,
        };
    }
}
