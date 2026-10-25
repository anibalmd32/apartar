<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use InvalidArgumentException;

final class InvalidScheduleException extends InvalidArgumentException
{
    public static function installmentsOutOfRange(int $installmentsCount): self
    {
        return new self(sprintf(
            'Un apartado se paga en entre %d y %d cuotas, y se pidieron %d.',
            ScheduleGenerator::MIN_INSTALLMENTS,
            ScheduleGenerator::MAX_INSTALLMENTS,
            $installmentsCount,
        ));
    }

    public static function installmentBelowOneCent(int $financedCents, int $installmentsCount): self
    {
        return new self(sprintf(
            'Con %d céntimos por financiar no caben %d cuotas: alguna saldría de $0,00. '
            .'CoherentFinancingTerms lo impide al guardar el producto.',
            $financedCents,
            $installmentsCount,
        ));
    }

    public static function frequencyOutOfRange(int $frequencyDays): self
    {
        return new self(sprintf(
            'Las cuotas van cada %d a %d días, y se pidieron %d.',
            ScheduleGenerator::MIN_FREQUENCY_DAYS,
            ScheduleGenerator::MAX_FREQUENCY_DAYS,
            $frequencyDays,
        ));
    }
}
