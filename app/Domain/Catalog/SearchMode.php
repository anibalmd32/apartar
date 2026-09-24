<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Los dos modos de la búsqueda del catálogo (`REQ-CAT-002` RB-2).
 *
 * Los dos son parte del contrato y no una optimización: el índice `FULLTEXT`
 * ordena por relevancia y encuentra por la descripción, pero **no ve** las
 * palabras de menos de tres caracteres ni lo que va a mitad de una palabra; el
 * `LIKE` sí, sobre nombre y SKU. Cuál toca lo decide `CatalogProductsQuery`, y
 * lo que tiene que saber quien lea el resultado es que `Like` **no tiene
 * relevancia**: sin `sort` explícito, ordena el orden curado.
 */
enum SearchMode
{
    /** `MATCH (name, description, sku) AGAINST (… IN BOOLEAN MODE)`. */
    case FullText;

    /** `name LIKE %palabra%` o `sku LIKE %palabra%`, por cada palabra. */
    case Like;
}
