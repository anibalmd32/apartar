<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class AmortizationSchedule
{
    /**
     * @param  list<ScheduledInstallment>  $installments
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public array $installments,
        public Currency $currency = Currency::USD,
    ) {
        foreach ($installments as $position => $installment) {
            if ($installment->number !== $position + 1) {
                throw new InvalidArgumentException(sprintf(
                    'Las cuotas de un cronograma van numeradas 1…n y en orden, y en la posición %d está la %d. '
                    .'Es la numeración que protege UNIQUE(organization_id, layaway_id, number).',
                    $position + 1,
                    $installment->number,
                ));
            }

            if ($installment->amount->currency !== $currency) {
                throw new InvalidArgumentException(sprintf(
                    'La cuota %d está en %s y el cronograma en %s.',
                    $installment->number,
                    $installment->amount->currency->value,
                    $currency->value,
                ));
            }
        }
    }

    public function count(): int
    {
        return count($this->installments);
    }

    public function isEmpty(): bool
    {
        return $this->installments === [];
    }

    public function totalAmount(): Money
    {
        $total = new Money(0, $this->currency);

        foreach ($this->installments as $installment) {
            $total = $total->add($installment->amount);
        }

        return $total;
    }

    public function firstUnpaid(): ?ScheduledInstallment
    {
        foreach ($this->installments as $installment) {
            if (! $installment->isPaid()) {
                return $installment;
            }
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validMoveWindow(int $number, CarbonImmutable $today): RescheduleWindow
    {
        if ($number < 1 || $number > $this->count()) {
            throw new InvalidArgumentException(sprintf('El cronograma tiene %d cuotas, y se preguntó por la %d.', $this->count(), $number));
        }

        $tomorrow = $today->startOfDay()->addDay();
        $previous = $this->installments[$number - 2] ?? null;
        $next = $this->installments[$number] ?? null;

        $earliest = $previous !== null && $previous->dueDate->greaterThan($tomorrow) ? $previous->dueDate : $tomorrow;

        return new RescheduleWindow($earliest, $next?->dueDate);
    }

    public function last(): ?ScheduledInstallment
    {
        return $this->installments === [] ? null : $this->installments[count($this->installments) - 1];
    }
}
