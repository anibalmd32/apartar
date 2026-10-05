<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use InvalidArgumentException;

/**
 * Lo que `LayawayBalanceRecalculator::diff()` encontró descuadrado en un
 * apartado, sin haber escrito nada (`REQ-PAY-008` RB-3, RB-4).
 *
 * Son tres hallazgos de naturaleza distinta, y por eso van separados:
 *
 *  - **`$fields`**: columnas materializadas que no coinciden con lo que el
 *    recalculador derivaría ahora —`paid_cents`, `remaining_cents`,
 *    `next_due_date`— y `status`, cuando el recalculador pediría una
 *    transición. Esto lo arregla `recalculate()`.
 *  - **`$paidFromPayments` frente a `$paidFromSchedule`**: las dos primeras
 *    invariantes, que miden lo pagado por dos caminos —los pagos menos su
 *    sobrante; las cuotas más la inicial aplicada—. Si no coinciden, el
 *    descuadre está entre pagos, asignaciones y cuotas, y recalcular no lo
 *    arregla: el balance se deriva de las cuotas. Es lo que tiene que mirar
 *    una persona (o `layaways:reconcile --fix`, `T-064`).
 *  - **`$overpaidCents`**: lo que lo pagado excede del total (SP-2). El saldo
 *    se acota a cero, pero el exceso no desaparece: se reporta.
 *
 * No existe una discrepancia vacía: «nada que reportar» es `null`, no un
 * objeto que lo diga.
 */
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

    /**
     * Las dos primeras invariantes de RB-3 no coinciden entre sí.
     */
    public function ledgerMismatch(): bool
    {
        return $this->paidFromPayments !== $this->paidFromSchedule;
    }

    /**
     * Si `recalculate()` deja el apartado sin nada que reportar: solo había
     * columnas desactualizadas.
     */
    public function isFixedByRecalculating(): bool
    {
        return ! $this->ledgerMismatch() && $this->overpaidCents === 0;
    }
}
