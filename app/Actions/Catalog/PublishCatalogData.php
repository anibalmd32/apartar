<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use Carbon\CarbonImmutable;

/**
 * Con qué se publica un catálogo, ya validado (`REQ-CAT-022` RB-3, RB-6).
 *
 * Dos campos, y ninguno es `published_at`: la fecha de publicación **no la
 * elige quien publica**. `REQ-CAT-022` deja la publicación programada fuera de
 * alcance —«solo se programa la expiración»— y quien fija la fecha es la
 * acción, al publicar. Un campo aquí sería la puerta por la que esa función
 * entraría sin que nadie la hubiera decidido, y además produciría el estado
 * que `Catalog::visibility()` describe como imposible: un `published_at`
 * futuro que nadie sabe leer como «programado».
 */
final readonly class PublishCatalogData
{
    /**
     * @param  bool  $isListed  aparece en la vitrina del negocio; **no** controla el acceso (RB-3)
     * @param  CarbonImmutable|null  $expiresAt  cuándo termina la campaña; `null` es sin caducidad (RB-6)
     */
    public function __construct(
        public bool $isListed,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
