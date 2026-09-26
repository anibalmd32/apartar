<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use InvalidArgumentException;

/**
 * Unas condiciones que no producen un cronograma (`REQ-LAY-004` RB-7).
 *
 * **Salta al construir `LayawayTerms`, no al generar.** Las condiciones se
 * congelan al crear el apartado (`REQ-LAY-002`) y el cronograma se genera días
 * después, al aprobar la inicial (RB-1). Si el rango se comprobara al generar,
 * un apartado imposible se crearía, el cliente pagaría su inicial, y el fallo
 * aparecería en la activación con el dinero ya dentro. Comprobarlo en el
 * constructor lo adelanta a la creación (`T-045`), donde todavía es un `422`.
 *
 * Los mensajes dicen el rango, como los de `T-040`.
 */
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

    /**
     * Un financiado menor que el número de cuotas deja cuotas de $0,00
     * (`T-045`, razonado bajo `T-041`): `CoherentFinancingTerms` lo rechaza al
     * guardar el producto, y esto es la misma regla como guarda.
     */
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
