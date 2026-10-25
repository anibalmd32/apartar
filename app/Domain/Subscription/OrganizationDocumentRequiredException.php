<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

final class OrganizationDocumentRequiredException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'Para emitir tu primera factura necesitamos el RIF del negocio. Añádelo en Ajustes y vuelve a intentarlo.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
