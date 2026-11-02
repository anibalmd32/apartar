<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Layaway\DueCalendar;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\NextPayment;
use App\Domain\Layaway\NextPaymentKind;
use App\Domain\Money\Money;
use App\Domain\Payment\ReceiptsInReview;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

final readonly class BusinessBalance
{
    /** @var list<Layaway> */
    public array $active;

    /** @var list<Layaway> */
    public array $history;

    /**
     * @param  list<Layaway>  $layaways
     * @param  array<int, array{next: ?NextPayment, review: ReceiptsInReview}>  $suggestions
     */
    public function __construct(public Organization $organization, array $layaways, public DueCalendar $calendar, public array $suggestions = [])
    {
        $active = [];
        $history = [];

        foreach ($layaways as $layaway) {
            if ((int) $layaway->organization_id !== (int) $organization->getKey()) {
                throw new LogicException('Un bloque del portal es de un solo negocio, y llegó un apartado de otro.');
            }

            if ($layaway->status->isTerminal()) {
                $history[] = $layaway;
            } else {
                $active[] = $layaway;
            }
        }

        $this->active = $active;
        $this->history = $history;
    }

    public function committed(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => $layaway->total_cents);
    }

    public function paid(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => $layaway->paid_cents);
    }

    public function remaining(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => $layaway->remaining_cents);
    }

    public function overdueInstallments(): int
    {
        return (int) array_sum(array_map(fn (Layaway $layaway): int => self::overdueCountOf($layaway), $this->active));
    }

    public function overdueAmount(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => new Money((int) $layaway->getAttribute('overdue_pending_cents')));
    }

    public function firstOverdueLayaway(): ?Layaway
    {
        foreach ($this->active as $layaway) {
            if (self::overdueCountOf($layaway) > 0) {
                return $layaway;
            }
        }

        return null;
    }

    /**
     * @return array{date: CarbonImmutable, amount: Money, layaway: Layaway}|null
     */
    public function dueSoon(): ?array
    {
        $soon = null;

        foreach ($this->active as $layaway) {
            $date = $layaway->getAttribute('soon_due_date');

            $amount = $this->soonAmountOf($layaway);

            if (! is_string($date) || $amount === null) {
                continue;
            }

            $day = $this->calendar->day(CarbonImmutable::parse($date));

            if ($soon === null || $day->lessThan($soon['date'])) {
                $soon = ['date' => $day, 'amount' => $amount, 'layaway' => $layaway];
            } elseif ($day->equalTo($soon['date'])) {
                $soon['amount'] = $soon['amount']->add($amount);
            }
        }

        return $soon;
    }

    public function suggestionFor(Layaway $layaway): ?NextPayment
    {
        return $this->suggestions[(int) $layaway->getKey()]['next'] ?? null;
    }

    public function reviewFor(Layaway $layaway): ?ReceiptsInReview
    {
        return $this->suggestions[(int) $layaway->getKey()]['review'] ?? null;
    }

    public function nextPaymentDueDate(): ?CarbonInterface
    {
        $dates = array_values(array_filter(array_map(fn (Layaway $layaway): ?CarbonInterface => $this->paymentDueDateOf($layaway), $this->active)));
        usort($dates, fn (CarbonInterface $a, CarbonInterface $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $dates[0] ?? null;
    }

    public function paymentInReview(): bool
    {
        foreach ($this->active as $layaway) {
            if ($this->suggestionFor($layaway)?->covered() === true) {
                return true;
            }
        }

        return false;
    }

    public function catchUp(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => $this->catchUpOf($layaway));
    }

    public function catchUpInstallments(): int
    {
        return (int) array_sum(array_map(
            fn (Layaway $layaway): int => $this->catchUpOf($layaway)->cents > 0 ? self::overdueCountOf($layaway) : 0,
            $this->active,
        ));
    }

    public function firstCatchUpLayaway(): ?Layaway
    {
        foreach ($this->active as $layaway) {
            if ($this->catchUpOf($layaway)->cents > 0) {
                return $layaway;
            }
        }

        return null;
    }

    /**
     * @return list<Layaway>
     */
    public function awaitingDownPayment(): array
    {
        $waiting = array_values(array_filter($this->active, fn (Layaway $layaway): bool => $layaway->status === LayawayStatus::PendingDownPayment));
        usort($waiting, fn (Layaway $a, Layaway $b): int => ($a->expires_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->expires_at?->getTimestamp() ?? PHP_INT_MAX));

        return $waiting;
    }

    public function nextDueDate(): ?CarbonInterface
    {
        $dates = array_values(array_filter(array_map(fn (Layaway $layaway): ?CarbonInterface => $layaway->next_due_date, $this->active)));

        if ($dates === []) {
            return null;
        }

        usort($dates, fn (CarbonInterface $a, CarbonInterface $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $dates[0];
    }

    public function businessIsClosed(): bool
    {
        return $this->organization->status === OrganizationStatus::Closed;
    }

    public static function overdueCountOf(Layaway $layaway): int
    {
        return (int) $layaway->getAttribute('overdue_installments_count');
    }

    private function catchUpOf(Layaway $layaway): Money
    {
        if (self::overdueCountOf($layaway) === 0) {
            return new Money(0);
        }

        if (! array_key_exists((int) $layaway->getKey(), $this->suggestions)) {
            return new Money((int) $layaway->getAttribute('overdue_pending_cents'));
        }

        $next = $this->suggestionFor($layaway);

        return $next?->kind === NextPaymentKind::Overdue ? $next->amount : new Money(0);
    }

    private function paymentDueDateOf(Layaway $layaway): ?CarbonInterface
    {
        if (! array_key_exists((int) $layaway->getKey(), $this->suggestions)) {
            return $layaway->next_due_date;
        }

        $next = $this->suggestionFor($layaway);

        if ($next === null || $next->covered()) {
            return null;
        }

        if ($next->kind === NextPaymentKind::Installment) {
            return $layaway->installments->firstWhere('number', $next->installmentNumber)?->due_date;
        }

        return $next->kind === NextPaymentKind::Overdue ? $layaway->next_due_date : null;
    }

    private function soonAmountOf(Layaway $layaway): ?Money
    {
        if (self::overdueCountOf($layaway) > 0) {
            return null;
        }

        if (! array_key_exists((int) $layaway->getKey(), $this->suggestions)) {
            return new Money((int) $layaway->getAttribute('soon_pending_cents'));
        }

        $next = $this->suggestionFor($layaway);

        return $next?->kind === NextPaymentKind::Installment && ! $next->covered() ? $next->amount : null;
    }

    /**
     * @param  callable(Layaway): Money  $amount
     */
    private function sum(callable $amount): Money
    {
        $total = new Money(0);

        foreach ($this->active as $layaway) {
            $total = $total->add($amount($layaway));
        }

        return $total;
    }
}
