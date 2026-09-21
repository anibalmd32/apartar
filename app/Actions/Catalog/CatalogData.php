<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use Illuminate\Http\UploadedFile;

/**
 * Datos de alta o edición de un catálogo, ya validados (`REQ-BOF-024` RB-1).
 *
 * Los cinco campos del CRUD que **no** son de publicación. Los otros tres
 * —`is_listed`, `published_at` y `expires_at`— viajan en `PublishCatalogData`
 * y los escribe `PublishCatalogAction`, que es el único sitio donde se tocan:
 * el motivo está escrito allí y es el mismo que dejó `catalog_product` con un
 * solo escritor.
 */
final readonly class CatalogData
{
    /**
     * @param  string  $name  como lo llama el negocio
     * @param  string|null  $slug  el enlace pedido, o `null` para derivarlo del nombre (RB-1)
     * @param  string|null  $description  texto de la portada del catálogo
     * @param  UploadedFile|null  $cover  portada nueva, o `null` para dejar la que haya (SP-9)
     */
    public function __construct(
        public string $name,
        public ?string $slug,
        public ?string $description,
        public ?UploadedFile $cover,
    ) {}
}
