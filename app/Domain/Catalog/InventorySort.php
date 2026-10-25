<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\SortWhitelist;

enum InventorySort: string
{
    case Created = 'created';

    case Name = 'name';

    case Sku = 'sku';

    case Price = 'price';

    case Stock = 'stock';

    case Updated = 'updated';

    public function column(): string
    {
        return match ($this) {
            self::Created => 'products.id',
            self::Name => 'products.name',
            self::Sku => 'products.sku',
            self::Price => 'products.price_cents',
            self::Stock => 'products.stock',
            self::Updated => 'products.updated_at',
        };
    }

    /**
     * @return SortWhitelist<self>
     */
    public static function whitelist(): SortWhitelist
    {
        return new SortWhitelist(self::cases());
    }
}
