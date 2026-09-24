<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Si un catálogo se abre al público, y qué se responde si no
 * (`REQ-CAT-021` SP-2, SP-3, SP-5; `REQ-CAT-003` SP-3).
 *
 *  - `Draft` → `404`: nunca existió para el público, aunque se conozca el slug.
 *    Una organización suspendida tiene sus catálogos despublicados, así que
 *    cae aquí sin rama propia (`REQ-ORG-008` RB-2).
 *  - `Expired` → `410`: existió y terminó. Ver `CatalogExpiredException`.
 *  - `Listed` y `Unlisted` → se abre. `is_listed` **no** controla el acceso
 *    (`REQ-CAT-022` RB-3), solo la vitrina.
 *
 * ## Por qué es una clase y no un `match` en cada controlador
 *
 * Porque desde `T-035` son dos páginas, y la ficha tiene que decidirlo
 * **antes de mirar el producto** (`REQ-CAT-003` SP-3): un catálogo caducado
 * da `410` aunque el producto de la URL no exista, porque lo que caducó es el
 * enlace entero. Esa comprobación la hacen el *binding* del producto
 * (`Catalog::resolveChildRouteBinding()`) y los dos controladores, y tres
 * copias del mismo `match` son tres sitios donde un quinto estado de
 * visibilidad podría responderse de tres maneras.
 *
 * El `match` no lleva `default`: un estado nuevo tiene que decidir aquí su
 * respuesta, no heredar una por descuido.
 */
final class PublicCatalogAccess
{
    /**
     * @throws ModelNotFoundException<Catalog> si el catálogo es un borrador
     * @throws CatalogExpiredException si su campaña terminó
     */
    public static function ensureOpen(Catalog $catalog): void
    {
        match ($catalog->visibility()) {
            CatalogVisibility::Draft => throw (new ModelNotFoundException)->setModel(Catalog::class, [$catalog->slug]),
            CatalogVisibility::Expired => throw CatalogExpiredException::of($catalog),
            CatalogVisibility::Listed, CatalogVisibility::Unlisted => null,
        };
    }
}
