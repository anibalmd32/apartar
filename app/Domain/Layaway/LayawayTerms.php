<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Money\Money;
use InvalidArgumentException;

final readonly class LayawayTerms
{
    /**
     * @throws InvalidScheduleException
     * @throws InvalidArgumentException
     */
    public function __construct(
        public Money $unitPrice,
        public Money $downPayment,
        public int $quantity,
        public int $installmentsCount,
        public int $frequencyDays,
    ) {
        if ($installmentsCount < ScheduleGenerator::MIN_INSTALLMENTS || $installmentsCount > ScheduleGenerator::MAX_INSTALLMENTS) {
            throw InvalidScheduleException::installmentsOutOfRange($installmentsCount);
        }

        if ($frequencyDays < ScheduleGenerator::MIN_FREQUENCY_DAYS || $frequencyDays > ScheduleGenerator::MAX_FREQUENCY_DAYS) {
            throw InvalidScheduleException::frequencyOutOfRange($frequencyDays);
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException(sprintf(
                'Un apartado es de al menos una unidad, y se pidieron %d. Lo valida el formulario antes '
                .': si esto lanza, alguien congeló las condiciones sin pasar por él.',
                $quantity,
            ));
        }

        if ($downPayment->isNegative() || $unitPrice->subtract($downPayment)->isNegative()) {
            throw new InvalidArgumentException(
                'La cuota inicial va de cero al precio de la unidad. Por encima, el financiado sería negativo '
                .'y las cuotas también; CoherentFinancingTerms lo impide al guardar el producto.'
            );
        }

        $financed = $this->total()->cents - $this->totalDownPayment()->cents;

        if ($financed > 0 && $financed < $installmentsCount) {
            throw InvalidScheduleException::installmentBelowOneCent($financed, $installmentsCount);
        }
    }

    public function total(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function totalDownPayment(): Money
    {
        return $this->downPayment->multiply($this->quantity);
    }

    public function requiresDownPayment(): bool
    {
        return $this->totalDownPayment()->cents > 0;
    }

    public function financedAmount(): Money
    {
        return $this->financingPlan()?->financedAmount() ?? new Money(0, $this->unitPrice->currency);
    }

    public function financingPlan(): ?FinancingPlan
    {
        return FinancingPlan::tryFrom(
            $this->total(),
            $this->totalDownPayment(),
            $this->installmentsCount,
            $this->frequencyDays,
        );
    }
}
