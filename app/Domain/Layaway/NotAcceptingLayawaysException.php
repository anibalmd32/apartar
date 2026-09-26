<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use RuntimeException;
use Throwable;

/**
 * El negocio no puede recibir apartados ahora mismo, dicho **al cliente**
 * (`REQ-LAY-001` SP-2, `REQ-SUB-004` RB-4).
 *
 * `active_layaways` es el único límite del plan que llega al cliente final, y
 * por eso el mensaje es neutro: no dice que el negocio está en un plan
 * gratuito, ni que no ha pagado, ni cuántos apartados le caben. Ponerlo en la
 * pantalla de su cliente sería humillarlo por algo que no tiene que ver con la
 * compra.
 *
 * El motivo real **no se pierde**: viaja como la excepción anterior
 * (`getPrevious()`) —un `PlanLimitReachedException` con la métrica, el techo y
 * el plan que lo desbloquea, o un `CreationSuspendedException`—, que es lo que
 * el aviso del dashboard del negocio necesita (SP-2, `T-065`).
 */
final class NotAcceptingLayawaysException extends RuntimeException
{
    /** Código HTTP con el que se responde (SP-2). */
    public const STATUS = 422;

    public const MESSAGE = 'Este negocio no puede recibir nuevos apartados en este momento.';

    private function __construct(Throwable $reason)
    {
        parent::__construct(self::MESSAGE, 0, $reason);
    }

    public static function because(Throwable $reason): self
    {
        return new self($reason);
    }
}
