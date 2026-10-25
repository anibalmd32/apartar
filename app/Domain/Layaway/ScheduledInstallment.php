<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ScheduledInstallment
{
    public Money $paid;

    public function __construct(
        public int $number,
        public CarbonImmutable $dueDate,
        public Money $amount,
        ?Money $paid = null,
        public ?int $installmentId = null,
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException(sprintf('Las cuotas se numeran desde 1, y llegó la %d.', $number));
        }

        $this->paid = $paid ?? new Money(0, $amount->currency);
    }

    public function dueDateString(): string
    {
        return $this->dueDate->toDateString();
    }

    public function isPaid(): bool
    {
        return $this->paid->cents >= $this->amount->cents;
    }
}
