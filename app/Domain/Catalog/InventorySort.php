<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\SortWhitelist;

/**
 * Por qué se puede ordenar el inventario del backoffice (`REQ-BOF-020` RB-9,
 * `REQ-BOF-045` RB-4).
 *
 * La lista blanca de la tabla de productos, y la única fuente de qué columna
 * ordena cada caso. El `sort` de la URL nunca llega al `ORDER BY`: llega uno de
 * estos casos, elegido por `SortWhitelist`, y es él quien dice la columna. Un
 * `sort=password` no es un orden raro, es uno que no existe, y la tabla cae a
 * su defecto sin error (SP-1).
 *
 * La dirección no está en el caso sino en el prefijo `-` de la URL, que es la
 * forma del contrato (`"name" | "-name"`): así cada columna se ordena en los
 * dos sentidos sin duplicar la lista.
 *
 * Es distinto de `CatalogSort`, que ordena la parrilla **pública** y tiene
 * criterios que aquí no significan nada —la posición curada, la relevancia de
 * una búsqueda—. Un enum por lista, porque una lista de casos es propiedad del
 * tipo y no de quien la consume.
 */
enum InventorySort: string
{
    /** El orden de alta: lo último creado es lo que se está preparando (defecto, descendente). */
    case Created = 'created';

    case Name = 'name';

    case Sku = 'sku';

    case Price = 'price';

    case Stock = 'stock';

    /** La marca de última modificación, con su autor (`REQ-BOF-020` SP-7). */
    case Updated = 'updated';

    /**
     * La columna que ordena este caso.
     *
     * Cualificada, porque la consulta del listado lleva subconsultas de conteo
     * y un nombre suelto podría acabar siendo ambiguo el día que se añada un
     * `join`.
     */
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
     * Los órdenes que acepta la URL: todos.
     *
     * @return SortWhitelist<self>
     */
    public static function whitelist(): SortWhitelist
    {
        return new SortWhitelist(self::cases());
    }
}
