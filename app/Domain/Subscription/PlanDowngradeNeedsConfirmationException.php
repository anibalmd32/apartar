<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

/**
 * Una bajada que deja algo por encima del techo y que nadie ha confirmado
 * (`REQ-SUB-005` SP-2, `T-068`).
 *
 * Bajar con el consumo muy por encima está **permitido** —nada se borra
 * (`REQ-SUB-008` RB-1)—, pero no a ciegas: la pantalla enseña lo que excede
 * (RB-5) y la acción exige que se haya visto. Lleva la previsualización para
 * que quien la reciba pueda decir exactamente qué faltó confirmar.
 */
final class PlanDowngradeNeedsConfirmationException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct(public readonly PlanChangePreview $preview)
    {
        parent::__construct(sprintf(
            'Con el plan %s quedarías por encima de algún límite. Revisa lo que cambia y confírmalo para programar la bajada.',
            $preview->target->name,
        ));
    }
}
