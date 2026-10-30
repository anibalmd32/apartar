<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;

final class DownPaymentLedger
{
    public function paidOn(Layaway $layaway): int
    {
        $id = (int) $layaway->getKey();

        return $this->paidOnEach([$id])[$id] ?? 0;
    }

    public function paidThrough(Payment $payment): int
    {
        $portions = $this->portions(Payment::query()
            ->where('layaway_id', $payment->layaway_id)
            ->where('id', '<=', $payment->getKey()));

        return array_sum(array_column($portions, 'cents'));
    }

    /**
     * @param  list<int>  $layawayIds
     * @return array<int, int>
     */
    public function paidOnEach(array $layawayIds): array
    {
        if ($layawayIds === []) {
            return [];
        }

        $paid = [];

        foreach ($this->portions(Payment::query()->whereIn('layaway_id', $layawayIds)) as $portion) {
            $paid[$portion['layawayId']] = ($paid[$portion['layawayId']] ?? 0) + $portion['cents'];
        }

        return $paid;
    }

    /**
     * @param  Builder<Payment>  $payments
     * @return list<array{layawayId: int, cents: int}>
     */
    private function portions(Builder $payments): array
    {
        $rows = $payments->get(['id', 'layaway_id', 'amount_cents_usd', 'unallocated_cents']);

        if ($rows->isEmpty()) {
            return [];
        }

        $allocated = PaymentAllocation::query()
            ->whereIn('payment_id', $rows->modelKeys())
            ->groupBy('payment_id')
            ->selectRaw('payment_id, SUM(amount_cents) AS cents')
            ->toBase()
            ->pluck('cents', 'payment_id')
            ->all();

        return $rows->map(fn (Payment $payment): array => [
            'layawayId' => (int) $payment->layaway_id,
            'cents' => $payment->amount_cents_usd->cents
                - $payment->unallocated_cents->cents
                - (int) ($allocated[(int) $payment->getKey()] ?? 0),
        ])->values()->all();
    }
}
