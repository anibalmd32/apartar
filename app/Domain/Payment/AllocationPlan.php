<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use InvalidArgumentException;

final readonly class AllocationPlan
{
    /**
     * @param  list<array{installmentId: int, cents: int}>  $allocations
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public array $allocations,
        public int $unallocatedCents,
    ) {
        $seen = [];

        foreach ($allocations as $allocation) {
            if ($allocation['cents'] <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'Una asignación es de más de cero centavos, y a la cuota %d se le asignaron %d.',
                    $allocation['installmentId'],
                    $allocation['cents'],
                ));
            }

            if (isset($seen[$allocation['installmentId']])) {
                throw new InvalidArgumentException(sprintf(
                    'La cuota %d aparece dos veces en el reparto: es una asignación por cuota.',
                    $allocation['installmentId'],
                ));
            }

            $seen[$allocation['installmentId']] = true;
        }

        if ($unallocatedCents < 0) {
            throw new InvalidArgumentException(sprintf('Lo no asignado no puede ser negativo, y es %d.', $unallocatedCents));
        }
    }

    public function allocatedCents(): int
    {
        return array_sum(array_column($this->allocations, 'cents'));
    }

    public function isFullyAllocated(): bool
    {
        return $this->unallocatedCents === 0;
    }

    public function totalCents(): int
    {
        return $this->allocatedCents() + $this->unallocatedCents;
    }

    public function centsFor(int $installmentId): int
    {
        foreach ($this->allocations as $allocation) {
            if ($allocation['installmentId'] === $installmentId) {
                return $allocation['cents'];
            }
        }

        return 0;
    }
}
