<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Settings\SettingKey;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Support\ModelState;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

final class LayawayBalanceRecalculator
{
    public function __construct(
        private readonly LayawayStateMachine $machine,
        private readonly Settings $settings,
        private readonly DownPaymentLedger $ledger,
    ) {}

    public function recalculate(Layaway $layaway): void
    {
        DB::transaction(function () use ($layaway): void {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            /** @var list<Installment> $installments */
            $installments = $locked->installments()->lockForUpdate()->get()->all();

            $derived = $this->derive($locked, $installments);

            if ($derived['overpaid'] > 0) {
                Log::warning('layaway_balance.overpaid', [
                    'layaway_id' => $locked->getKey(),
                    'organization_id' => $locked->organization_id,
                    'paid_cents' => $derived['paid_cents'],
                    'total_cents' => $locked->total_cents->cents,
                    'overpaid_cents' => $derived['overpaid'],
                ]);
            }

            $changes = $this->changedColumns($this->stored($locked), $derived);

            if ($changes !== []) {
                Layaway::query()->whereKey($locked->getKey())->update(array_intersect_key([
                    'paid_cents' => $derived['paid_cents'],
                    'remaining_cents' => $derived['remaining_cents'],
                    'next_due_date' => $derived['next_due_date'],
                ], $changes));
            }

            $target = $this->targetStatus($locked->status, $derived['remaining_cents'], $installments);

            if ($target !== null) {
                $this->machine->transition($locked, $target);
            }

            $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su recálculo.');
            ModelState::adopt($layaway, $fresh->getAttributes());
        });
    }

    public function diff(Layaway $layaway): ?BalanceDiscrepancy
    {
        /** @var Layaway $current */
        $current = Layaway::query()->whereKey($layaway->getKey())->firstOrFail();

        /** @var list<Installment> $installments */
        $installments = $current->installments()->get()->all();

        return $this->discrepancyOf($current, $installments, $this->paidFromPayments($current));
    }

    /**
     * @param  list<Layaway>  $layaways
     * @return array<int, BalanceDiscrepancy>
     */
    public function diffMany(array $layaways): array
    {
        if ($layaways === []) {
            return [];
        }

        $ids = array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways);

        /** @var array<int, list<Installment>> $installments */
        $installments = [];

        foreach (Installment::query()->whereIn('layaway_id', $ids)->orderBy('number')->get() as $installment) {
            $installments[(int) $installment->layaway_id][] = $installment;
        }

        $pendingIds = array_values(array_map(
            fn (Layaway $layaway): int => (int) $layaway->getKey(),
            array_filter($layaways, fn (Layaway $layaway): bool => $layaway->activated_at === null),
        ));
        $downPayments = $this->ledger->paidOnEach($pendingIds);

        $fromPayments = Payment::query()
            ->whereIn('layaway_id', $ids)
            ->groupBy('layaway_id')
            ->selectRaw('layaway_id, SUM(amount_cents_usd - unallocated_cents) AS paid')
            ->pluck('paid', 'layaway_id')
            ->all();

        $discrepancies = [];

        foreach ($layaways as $layaway) {
            $id = (int) $layaway->getKey();
            $discrepancy = $this->discrepancyOf($layaway, $installments[$id] ?? [], (int) ($fromPayments[$id] ?? 0), $downPayments[$id] ?? 0);

            if ($discrepancy instanceof BalanceDiscrepancy) {
                $discrepancies[$id] = $discrepancy;
            }
        }

        return $discrepancies;
    }

    /**
     * @param  list<Installment>  $installments
     */
    private function discrepancyOf(Layaway $layaway, array $installments, int $fromPayments, ?int $downPaymentPaid = null): ?BalanceDiscrepancy
    {
        $derived = $this->derive($layaway, $installments, $downPaymentPaid);
        $fields = $this->changedColumns($this->stored($layaway), $derived);

        $target = $this->targetStatus($layaway->status, $derived['remaining_cents'], $installments);

        if ($target !== null) {
            $fields['status'] = ['stored' => $layaway->status->value, 'derived' => $target->value];
        }

        if ($fields === [] && $fromPayments === $derived['paid_cents'] && $derived['overpaid'] === 0) {
            return null;
        }

        return new BalanceDiscrepancy(
            layawayId: (int) $layaway->getKey(),
            fields: $fields,
            paidFromPayments: $fromPayments,
            paidFromSchedule: $derived['paid_cents'],
            overpaidCents: $derived['overpaid'],
        );
    }

    /**
     * @param  list<Installment>  $installments
     * @return array{paid_cents: int, remaining_cents: int, next_due_date: ?string, overpaid: int}
     */
    private function derive(Layaway $layaway, array $installments, ?int $downPaymentPaid = null): array
    {
        $paid = $this->downPaymentApplied($layaway, $downPaymentPaid);
        $nextDue = null;

        foreach ($installments as $installment) {
            $paid += $installment->paid_cents->cents;

            if ($installment->paid_cents->cents < $installment->amount_cents->cents) {
                $day = $installment->due_date->toDateString();
                $nextDue = $nextDue === null || $day < $nextDue ? $day : $nextDue;
            }
        }

        $total = $layaway->total_cents->cents;

        return [
            'paid_cents' => $paid,
            'remaining_cents' => max(0, $total - $paid),
            'next_due_date' => $nextDue,
            'overpaid' => max(0, $paid - $total),
        ];
    }

    private function downPaymentApplied(Layaway $layaway, ?int $paid): int
    {
        $total = $layaway->terms()->totalDownPayment()->cents;

        if ($layaway->activated_at !== null) {
            return $total - ($layaway->down_payment_shortfall_cents->cents ?? 0);
        }

        return max(0, min($total, $paid ?? $this->ledger->paidOn($layaway)));
    }

    private function paidFromPayments(Layaway $layaway): int
    {
        return (int) Payment::query()
            ->where('layaway_id', $layaway->getKey())
            ->sum(DB::raw('amount_cents_usd - unallocated_cents'));
    }

    /**
     * @return array{paid_cents: int, remaining_cents: int, next_due_date: ?string}
     */
    private function stored(Layaway $layaway): array
    {
        return [
            'paid_cents' => $layaway->paid_cents->cents,
            'remaining_cents' => $layaway->remaining_cents->cents,
            'next_due_date' => $layaway->next_due_date?->toDateString(),
        ];
    }

    /**
     * @param  array{paid_cents: int, remaining_cents: int, next_due_date: ?string}  $stored
     * @param  array{paid_cents: int, remaining_cents: int, next_due_date: ?string, overpaid: int}  $derived
     * @return array<'paid_cents'|'remaining_cents'|'next_due_date', array{stored: int|string|null, derived: int|string|null}>
     */
    private function changedColumns(array $stored, array $derived): array
    {
        $changed = [];

        foreach (['paid_cents', 'remaining_cents', 'next_due_date'] as $column) {
            if ($stored[$column] !== $derived[$column]) {
                $changed[$column] = ['stored' => $stored[$column], 'derived' => $derived[$column]];
            }
        }

        return $changed;
    }

    /**
     * @param  list<Installment>  $installments
     */
    private function targetStatus(LayawayStatus $status, int $remaining, array $installments): ?LayawayStatus
    {
        if (! in_array($status, [LayawayStatus::Active, LayawayStatus::Overdue], true)) {
            return null;
        }

        if ($remaining <= $this->toleranceCents()) {
            return LayawayStatus::Completed;
        }

        $hasOverdue = false;

        foreach ($installments as $installment) {
            $hasOverdue = $hasOverdue || ($installment->status === InstallmentStatus::Overdue
                && $installment->paid_cents->cents < $installment->amount_cents->cents);
        }

        return match (true) {
            $hasOverdue && $status === LayawayStatus::Active => LayawayStatus::Overdue,
            ! $hasOverdue && $status === LayawayStatus::Overdue => LayawayStatus::Active,
            default => null,
        };
    }

    public function toleranceCents(): int
    {
        $cents = $this->settings->get(SettingKey::SettlementToleranceCents);

        return is_numeric($cents) ? (int) $cents : (int) SettingKey::SettlementToleranceCents->defaultValue();
    }
}
