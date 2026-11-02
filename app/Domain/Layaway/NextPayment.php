<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class NextPayment
{
    public Money $inReview;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public NextPaymentKind $kind,
        public ?int $installmentNumber,
        public Money $amount,
        public bool $started = false,
        public int $overdueInstallments = 0,
        ?Money $inReview = null,
        public ?self $alternative = null,
    ) {
        $this->inReview = $inReview ?? new Money(0);

        if ($amount->cents < 0 || ($amount->cents === 0 && $this->inReview->cents <= 0)) {
            throw new InvalidArgumentException(sprintf('Un pago sugerido es mayor que cero, y es de %d centavos.', $amount->cents));
        }

        if (($kind === NextPaymentKind::Installment) !== ($installmentNumber !== null)) {
            throw new InvalidArgumentException('Una cuota sugerida dice qué número es, y la inicial o lo vencido no lo dicen.');
        }

        if (($kind === NextPaymentKind::Overdue) !== ($overdueInstallments > 0)) {
            throw new InvalidArgumentException('Lo vencido dice cuántas cuotas junta, y solo lo vencido lo dice.');
        }

        if ($alternative !== null && ($alternative->kind !== NextPaymentKind::Installment || $alternative->amount->cents >= $amount->cents)) {
            throw new InvalidArgumentException('La alternativa es una cuota más pequeña que lo sugerido.');
        }
    }

    public function covered(): bool
    {
        return $this->amount->cents === 0;
    }

    /**
     * @param  list<Installment>  $installments
     */
    public static function for(Layaway $layaway, array $installments, ?CarbonInterface $today = null, ?Money $inReview = null): ?self
    {
        if (! $layaway->status->acceptsPayments()) {
            return null;
        }

        $review = max(0, $inReview->cents ?? 0);

        if ($layaway->status === LayawayStatus::PendingDownPayment) {
            $downPayment = $layaway->terms()->totalDownPayment()->cents;
            $paid = min($downPayment, $layaway->paid_cents->cents);

            return $downPayment > $paid
                ? new self(NextPaymentKind::DownPayment, null, self::net($downPayment - $paid, $review), $paid > 0 || $review > 0, 0, new Money($review))
                : null;
        }

        $open = array_values(array_filter($installments, fn (Installment $installment): bool => self::pendingOf($installment) > 0));

        if ($open === []) {
            return null;
        }

        $day = ($today ?? CarbonImmutable::now())->toDateString();
        $overdue = array_values(array_filter($open, fn (Installment $installment): bool => $installment->due_date->toDateString() < $day));
        $first = $open[0];

        if ($overdue === []) {
            return self::installment($first, $review);
        }

        $total = array_sum(array_map(self::pendingOf(...), $overdue));
        $amount = self::net($total, $review);
        $alternative = count($overdue) > 1 && self::pendingOf($first) > $review
            ? new self(NextPaymentKind::Installment, $first->number, self::net(self::pendingOf($first), $review), $first->paid_cents->cents > 0 || $review > 0)
            : null;

        return new self(
            NextPaymentKind::Overdue,
            null,
            $amount,
            $first->paid_cents->cents > 0 || $review > 0,
            count($overdue),
            new Money($review),
            $alternative,
        );
    }

    private static function installment(Installment $installment, int $review): self
    {
        return new self(
            NextPaymentKind::Installment,
            $installment->number,
            self::net(self::pendingOf($installment), $review),
            $installment->paid_cents->cents > 0 || $review > 0,
            0,
            new Money($review),
        );
    }

    private static function pendingOf(Installment $installment): int
    {
        return $installment->amount_cents->cents - $installment->paid_cents->cents;
    }

    private static function net(int $due, int $review): Money
    {
        return new Money(max(0, $due - $review));
    }
}
