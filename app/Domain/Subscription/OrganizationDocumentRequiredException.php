<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

/**
 * Falta el RIF del negocio y hay que emitir su primera factura
 * (`REQ-SUB-006` RB-5, SP-4, `REQ-ORG-001` RB-7, `T-069`).
 *
 * Se lanza **antes** de subir de plan, no al emitir a medias: subir y emitir
 * van en la misma transacción, así que el negocio se queda en su plan y se le
 * dice qué falta y dónde ponerlo.
 */
final class OrganizationDocumentRequiredException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'Para emitir tu primera factura necesitamos el RIF del negocio. Añádelo en Ajustes y vuelve a intentarlo.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
