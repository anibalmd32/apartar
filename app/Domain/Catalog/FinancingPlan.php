<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Money\Money;
use InvalidArgumentException;

final readonly class FinancingPlan
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public Money $price,
        public Money $downPayment,
        public int $installmentsCount,
        public int $frequencyDays,
    ) {
        if ($installmentsCount < 1) {
            throw new InvalidArgumentException(sprintf(
                'Un plan de pago necesita al menos una cuota, y se pidieron %d. Las reglas del '
                .'formulario lo comprueban antes: si esto lanza, alguien construyó el plan sin pasar '
                .'por CoherentFinancingTerms.',
                $installmentsCount,
            ));
        }

        if ($downPayment->cents >= $price->cents) {
            throw new InvalidArgumentException(
                'La cuota inicial tiene que ser estrictamente menor que el precio: con la inicial igual al '
                .'precio no hay nada que financiar, y por encima el plan devolvería cuotas negativas. Lo '
                .'comprueba CoherentFinancingTerms antes de llegar aquí.'
            );
        }
    }

    public static function tryFrom(
        Money $price,
        Money $downPayment,
        int $installmentsCount,
        int $frequencyDays,
    ): ?self {
        try {
            return new self($price, $downPayment, $installmentsCount, $frequencyDays);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function financedAmount(): Money
    {
        return $this->price->subtract($this->downPayment);
    }

    /**
     * @return list<Money>
     */
    public function installments(): array
    {
        return $this->financedAmount()->allocateEvenly($this->installmentsCount);
    }

    public function installmentAmount(): Money
    {
        return $this->installments()[0];
    }

    public function lastInstallmentAmount(): Money
    {
        $installments = $this->installments();

        return $installments[count($installments) - 1];
    }

    public function hasUnevenLastInstallment(): bool
    {
        return $this->installmentsCount > 1
            && $this->lastInstallmentAmount()->cents !== $this->installmentAmount()->cents;
    }
}
