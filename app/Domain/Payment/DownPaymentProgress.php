<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use InvalidArgumentException;

final readonly class DownPaymentProgress
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public int $appliedCents,
        public int $paidBeforeCents = 0,
        public int $missingCents = 0,
    ) {
        if ($appliedCents < 0 || $paidBeforeCents < 0 || $missingCents < 0) {
            throw new InvalidArgumentException(sprintf(
                'Lo abonado a la inicial no puede ser negativo: %d ahora, %d antes y %d por pagar.',
                $appliedCents,
                $paidBeforeCents,
                $missingCents,
            ));
        }
    }

    public function partial(): bool
    {
        return $this->appliedCents > 0 && $this->missingCents > 0;
    }

    public function completesEarlierPayments(): bool
    {
        return $this->appliedCents > 0 && $this->missingCents === 0 && $this->paidBeforeCents > 0;
    }

    public function label(): ?string
    {
        if ($this->appliedCents === 0) {
            return null;
        }

        $applied = MoneyFormatter::format(new Money($this->appliedCents));

        return match (true) {
            $this->partial() => sprintf('abono a la inicial de %s, faltan %s', $applied, MoneyFormatter::format(new Money($this->missingCents))),
            $this->completesEarlierPayments() => sprintf('abono de %s que completa la inicial', $applied),
            default => sprintf('inicial de %s', $applied),
        };
    }
}
