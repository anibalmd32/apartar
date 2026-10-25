<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Layaway;
use App\Models\Payment;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LayawayReceipts
{
    public function holdExpiration(Layaway $layaway): bool
    {
        return Receipt::query()
            ->where('layaway_id', $layaway->getKey())
            ->whereIn('status', ReceiptStatus::holdingExpiration())
            ->exists();
    }

    /**
     * @param  list<Layaway>  $layaways
     * @return array<int, int>
     */
    public function pendingReviewCounts(array $layaways): array
    {
        if ($layaways === []) {
            return [];
        }

        $counts = [];

        Receipt::query()
            ->whereIn('layaway_id', self::idsOf($layaways))
            ->where('status', ReceiptStatus::PendingReview)
            ->groupBy('layaway_id')
            ->selectRaw('layaway_id, COUNT(*) AS pending')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$counts): void {
                /** @var object{layaway_id: int|string, pending: int|string} $row */
                $counts[(int) $row->layaway_id] = (int) $row->pending;
            });

        return $counts;
    }

    /**
     * @param  list<Layaway>  $layaways
     * @return list<int>
     */
    public function rejectedAwaitingRetry(array $layaways): array
    {
        if ($layaways === []) {
            return [];
        }

        $latest = Receipt::query()
            ->whereIn('layaway_id', self::idsOf($layaways))
            ->groupBy('layaway_id')
            ->selectRaw('MAX(id)');

        return Receipt::query()
            ->whereIn('id', $latest)
            ->where('status', ReceiptStatus::Rejected)
            ->whereNotExists(fn ($payments) => $payments
                ->selectRaw('1')
                ->from('payments')
                ->whereColumn('payments.layaway_id', 'receipts.layaway_id')
                ->whereColumn('payments.created_at', '>=', DB::raw('COALESCE(receipts.reviewed_at, receipts.updated_at)')))
            ->pluck('layaway_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function rejectedToReplace(Layaway $layaway): ?Receipt
    {
        $latest = Receipt::query()->where('layaway_id', $layaway->getKey())->latest('id')->first();

        if (! $latest instanceof Receipt || $latest->status !== ReceiptStatus::Rejected) {
            return null;
        }

        $resolvedAt = $latest->reviewed_at ?? $latest->updated_at;
        $settledSince = Payment::query()
            ->where('layaway_id', $layaway->getKey())
            ->when($resolvedAt !== null, fn ($query) => $query->where('created_at', '>=', $resolvedAt))
            ->exists();

        return $settledSince ? null : $latest;
    }

    public function rejectPendingOnCancellation(Layaway $layaway): int
    {
        $pending = Receipt::query()
            ->where('layaway_id', $layaway->getKey())
            ->where('status', ReceiptStatus::PendingReview)
            ->lockForUpdate()
            ->get();

        $now = CarbonImmutable::now();

        foreach ($pending as $receipt) {
            $receipt->forceFill([
                'status' => ReceiptStatus::Rejected,
                'rejection_reason_code' => RejectionReasonCode::LayawayCancelled,
                'reviewed_by' => null,
                'reviewed_at' => $now,
            ])->save();
        }

        return $pending->count();
    }

    /**
     * @param  list<Layaway>  $layaways
     * @return list<int>
     */
    private static function idsOf(array $layaways): array
    {
        return array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways);
    }
}
