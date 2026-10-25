<?php

declare(strict_types=1);

namespace App\Domain\Export;

enum ExportType: string
{
    case Collections = 'collections';
    case Customers = 'customers';
    case Inventory = 'inventory';

    public function label(): string
    {
        return match ($this) {
            self::Collections => 'Cobranza',
            self::Customers => 'Clientes',
            self::Inventory => 'Inventario',
        };
    }

    public function filename(): string
    {
        return match ($this) {
            self::Collections => 'cobranza',
            self::Customers => 'clientes',
            self::Inventory => 'inventario',
        };
    }
}
