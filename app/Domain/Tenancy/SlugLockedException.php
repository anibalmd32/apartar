<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class SlugLockedException extends RuntimeException
{
    public const STATUS = 422;

    public static function hasPublishedCatalogs(string $slug): self
    {
        return new self(sprintf(
            'El enlace de tu negocio (%s) ya no se puede cambiar: tienes catálogos publicados y '
            .'cambiarlo rompería los enlaces que ya compartiste con tus clientes.',
            $slug,
        ));
    }

    public static function catalogIsPublished(string $slug): self
    {
        return new self(sprintf(
            'El enlace de este catálogo (/c/%s) ya no se puede cambiar: se publicó y esa URL ya circula. '
            .'Despublicarlo tampoco lo libera, porque quien la tenga guardada seguiría llegando aquí. '
            .'Si necesitas otro enlace, crea un catálogo nuevo.',
            $slug,
        ));
    }
}
