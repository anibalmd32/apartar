<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\SortWhitelist;

/**
 * Los órdenes de la parrilla pública (`REQ-CAT-006` RB-5, `REQ-CAT-002`
 * RB-4).
 *
 * Cinco se eligen —`curated` es el defecto— y uno se deduce: **`relevance`**
 * solo existe cuando hay un término que el índice `FULLTEXT` pudo contestar,
 * y no se acepta de la URL (`whitelist()`). Es un orden por defecto que
 * depende del estado, no un sexto botón: quien ordenó por precio y después
 * busca sigue viendo precios ordenados, porque su `sort` explícito manda.
 *
 * ## Cada orden trae su cursor
 *
 * `keyset()` es a la vez el `ORDER BY` y la forma exacta que acepta
 * `CatalogCursor`: las columnas en su orden, con su dirección, y el `id`
 * siempre al final para que el orden sea **total** —dos productos con el mismo
 * precio no pueden depender del azar del plan de ejecución, o el cursor
 * repite uno y se salta otro entre lotes—.
 *
 * Los nombres son **alias** del `SELECT` (`SELECTS`) y no columnas, por dos
 * motivos que el paginador de Laravel obliga a resolver: `catalog_product` y
 * `products` tienen las dos `created_at`, y el cursor lee su valor del modelo
 * por el nombre corto; y `price_cents` sale del modelo como `Money`, que no se
 * puede escribir en un cursor. El alias devuelve el dato en crudo, y el
 * paginador traduce el alias a su columna para el `WHERE`.
 *
 * `relevance` no tiene `keyset()`: la puntuación de `MATCH` es un número de
 * coma flotante calculado, y un cursor que compare flotantes por igualdad
 * pierde filas. Se pagina por desplazamiento, y no cuesta: MySQL calcula el
 * conjunto entero de coincidencias `FULLTEXT` en cada consulta, así que saltar
 * los primeros veinticuatro no añade lectura. Ver `CatalogCursor`.
 */
enum CatalogSort: string
{
    /** Por `position` del catálogo: el orden que eligió el negocio (defecto). */
    case Curated = 'curated';

    /** Lo último que entró **a este catálogo** (`catalog_product.created_at`). */
    case Recent = 'recent';

    case PriceAsc = 'price_asc';

    case PriceDesc = 'price_desc';

    case Name = 'name';

    /** Solo con término y solo si el índice contestó; nunca desde la URL. */
    case Relevance = 'relevance';

    /**
     * Las columnas de orden que no son de `products`, con el alias por el que
     * las leen el `ORDER BY` y el cursor.
     *
     * @var list<string>
     */
    public const SELECTS = [
        'catalog_product.position as catalog_position',
        'catalog_product.created_at as catalog_added_at',
        'products.price_cents as sort_price',
    ];

    /**
     * Los órdenes que se aceptan del parámetro `sort`.
     *
     * @return SortWhitelist<self>
     */
    public static function whitelist(): SortWhitelist
    {
        return new SortWhitelist([self::Curated, self::Recent, self::PriceAsc, self::PriceDesc, self::Name]);
    }

    /**
     * El orden y la forma del cursor: alias → dirección, `null` si el orden se
     * pagina por desplazamiento.
     *
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
