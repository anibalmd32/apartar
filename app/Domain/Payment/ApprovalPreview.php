<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Exchange\RateDeviation;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\StaleRate;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use InvalidArgumentException;

final readonly class ApprovalPreview
{
    /**
     * @param  list<array{number: int, cents: int, covers: bool}>  $allocations
     */
    public function __construct(
        public Money $amount,
        public string $rate,
        public RateSource $rateSource,
        public int $downPaymentCents,
        public array $allocations,
        public int $unallocatedCents,
        public LayawayStatus $statusBefore,
        public LayawayStatus $statusAfter,
        public ?StaleRate $staleRate = null,
        public ?RateDeviation $rateDeviation = null,
        public int $downPaymentPaidBeforeCents = 0,
        public int $downPaymentMissingCents = 0,
    ) {
        $allocated = array_sum(array_column($allocations, 'cents'));

        if ($downPaymentCents + $allocated + $unallocatedCents !== $amount->cents) {
            throw new InvalidArgumentException(sprintf(
                'La vista previa de un pago de %d centavos suma %d de inicial, %d asignados y %d sin asignar.',
                $amount->cents,
                $downPaymentCents,
                $allocated,
                $unallocatedCents,
            ));
        }
    }

    public function activates(): bool
    {
        return $this->statusBefore === LayawayStatus::PendingDownPayment && $this->statusAfter !== LayawayStatus::PendingDownPayment;
    }

    public function downPayment(): DownPaymentProgress
    {
        return new DownPaymentProgress($this->downPaymentCents, $this->downPaymentPaidBeforeCents, $this->downPaymentMissingCents);
    }

    public function completes(): bool
    {
        return $this->statusAfter === LayawayStatus::Completed && $this->statusBefore !== LayawayStatus::Completed;
    }

    public function hasExcess(): bool
    {
        return $this->unallocatedCents > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => MoneyFormatter::format($this->amount),
            'rate' => $this->rate,
            'rateSource' => $this->rateSource->value,
            'rateSourceLabel' => $this->rateSource->label(),
            'downPayment' => $this->downPaymentCents > 0 ? MoneyFormatter::format(new Money($this->downPaymentCents)) : null,
            'downPaymentMissing' => $this->downPaymentMissingCents > 0 ? MoneyFormatter::format(new Money($this->downPaymentMissingCents)) : null,
            'allocations' => array_map(fn (array $allocation): array => [
                'number' => $allocation['number'],
                'amount' => MoneyFormatter::format(new Money($allocation['cents'])),
                'covers' => $allocation['covers'],
            ], $this->allocations),
            'unallocated' => $this->hasExcess() ? MoneyFormatter::format(new Money($this->unallocatedCents)) : null,
            'unallocatedCents' => $this->unallocatedCents,
            'staleRate' => $this->staleRate?->toArray(),
            'rateDeviation' => $this->rateDeviation?->toArray(),
            'statusAfter' => $this->statusAfter->value,
            'statusAfterLabel' => $this->statusAfter->label(),
            'summary' => $this->summary(),
        ];
    }

    public function summary(): string
    {
        $parts = [];

        $downPayment = $this->downPayment();

        if ($downPayment->partial()) {
            $parts[] = sprintf(
                'abonará %s a la inicial y faltarán %s para activar el apartado',
                MoneyFormatter::format(new Money($this->downPaymentCents)),
                MoneyFormatter::format(new Money($this->downPaymentMissingCents)),
            );
        } elseif ($this->activates() && $downPayment->completesEarlierPayments()) {
            $parts[] = sprintf('completará la inicial con %s y activará el apartado', MoneyFormatter::format(new Money($this->downPaymentCents)));
        } elseif ($this->activates()) {
            $parts[] = sprintf('activará el apartado con la inicial de %s', MoneyFormatter::format(new Money($this->downPaymentCents)));
        }

        $numbers = array_column($this->allocations, 'number');

        if ($numbers !== []) {
            $parts[] = count($numbers) === 1
                ? sprintf('se aplicará a la cuota %d', $numbers[0])
                : sprintf('se aplicará a las cuotas %s', self::listOf($numbers));
        }

        if ($this->hasExcess()) {
            $parts[] = sprintf('sobrarán %s sin asignar', MoneyFormatter::format(new Money($this->unallocatedCents)));
        }

        if ($this->completes()) {
            $parts[] = 'el apartado quedará pagado';
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    /**
     * @param  list<int>  $numbers
     */
    private static function listOf(array $numbers): string
    {
        $last = array_pop($numbers);

        return implode(', ', $numbers).' y '.$last;
    }
}
