<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use DomainException;

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
            match (true) {
                $from->canTransitionTo($to) => 'Un apartado pagado solo se reabre al anular uno de sus pagos, con LayawayStateMachine::reopen().',
                $from === LayawayStatus::Completed => '«completed» es un estado terminal: solo se reabre, hacia «active» u «overdue», al anular uno de sus pagos.',
                $allowed === [] => sprintf('«%s» es un estado terminal: no se reabre ni pasa a ningún otro.', $from->value),
                default => sprintf('Desde «%s» solo se puede ir a %s.', $from->value, implode(', ', array_map(
                    fn (LayawayStatus $status): string => '«'.$status->value.'»',
                    $allowed,
                ))),
            },
        ));
    }
}
