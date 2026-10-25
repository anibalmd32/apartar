<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use InvalidArgumentException;

final readonly class BalanceDiscrepancy
{
    /**
     * @param  array<'paid_cents'|'remaining_cents'|'next_due_date'|'status', array{stored: int|string|null, derived: int|string|null}>  $fields
     */
    public function __construct(
        public int $layawayId,
        public array $fields,
        public int $paidFromPayments,
        public int $paidFromSchedule,
        public int $overpaidCents,
    ) {
        if ($overpaidCents < 0) {
            throw new InvalidArgumentException(sprintf('El exceso pagado no puede ser negativo, y es %d.', $overpaidCents));
        }

        foreach ($fields as $column => $pair) {
            if ($pair['stored'] === $pair['derived']) {
                throw new InvalidArgumentException(sprintf('«%s» no está descuadrada: lo guardado y lo derivado son iguales.', $column));
            }
        }

        if ($fields === [] && ! $this->ledgerMismatch() && $overpaidCents === 0) {
            throw new InvalidArgumentException('Una discrepancia sin nada descuadrado no existe: diff() devuelve null.');
        }
    }

    public function ledgerMismatch(): bool
    {
        return $this->paidFromPayments !== $this->paidFromSchedule;
    }

    public function isFixedByRecalculating(): bool
    {
        return ! $this->ledgerMismatch() && $this->overpaidCents === 0;
    }
}
