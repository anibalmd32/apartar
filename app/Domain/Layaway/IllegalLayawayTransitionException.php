<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use DomainException;

/**
 * Una transición que la matriz de `REQ-LAY-005` RB-1 no admite (RB-2).
 *
 * El mensaje dice **desde dónde, hacia dónde y adónde sí se podía ir**: quien
 * lo lea en un log de un job de mora o en el `422` de una cancelación tiene
 * que entender el porqué sin abrir la spec. Los terminales lo dicen con esa
 * palabra, porque «activar un `expired`» (SP-1) y «cancelar un `completed`»
 * (SP-2) no son errores de orden sino de concepto: no existe reapertura.
 */
final class IllegalLayawayTransitionException extends DomainException
{
    public function __construct(
        public readonly LayawayStatus $from,
        public readonly LayawayStatus $to,
        public readonly int|string|null $layawayId = null,
    ) {
        $allowed = $from->allowedTransitions();

        parent::__construct(sprintf(
            'El apartado%s no puede pasar de «%s» a «%s». %s',
            $layawayId === null ? '' : ' '.$layawayId,
            $from->value,
            $to->value,
            $allowed === []
                ? sprintf('«%s» es un estado terminal: no se reabre ni pasa a ningún otro (REQ-LAY-005 RB-8).', $from->value)
                : sprintf('Desde «%s» solo se puede ir a %s (REQ-LAY-005 RB-1).', $from->value, implode(', ', array_map(
                    fn (LayawayStatus $status): string => '«'.$status->value.'»',
                    $allowed,
                ))),
        ));
    }
}
