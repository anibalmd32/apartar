<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * Qué se exporta (`REQ-BOF-043` RB-4): la cartera, los clientes o el
 * inventario, cada uno con la tabla que el negocio está mirando.
 *
 * **No hay exportación de suscriptores ni de cuentas de consumidor**
 * (`REQ-ACC-007` RB-5, RB-7): esta lista es cerrada, y añadir un caso exige
 * pasar por esa regla.
 */
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

    /**
     * El nombre del archivo que se descarga, sin fecha (la pone quien lo
     * genera).
     */
    public function filename(): string
    {
        return match ($this) {
            self::Collections => 'cobranza',
            self::Customers => 'clientes',
            self::Inventory => 'inventario',
        };
    }
}
