<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Http\Controllers\Backoffice\ReceiptInboxController;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Support\SignedFileUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class OldestPendingReceiptsQuery
{
    public const LIMIT = 4;

    /**
     * @return list<array{id: int, reviewUrl: string, customerName: string|null, layawayCode: string|null, methodLabel: string, declared: string, submittedAt: string|null, overdue: bool, file: array{url: string, kind: string}}>
     */
    public function get(CarbonImmutable $now, int $limit = self::LIMIT): array
    {
        $overdueBefore = $now->subHours(ReceiptInboxController::OVERDUE_AFTER_HOURS);

        $receipts = Receipt::query()
            ->pendingReview()
            ->whereHas('layaway', fn (Builder $layaway) => $layaway->where('status', '!=', LayawayStatus::Cancelled->value))
            ->with(['layaway:id,organization_id,code', 'customer:id,organization_id,full_name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $receipts->map(function (Receipt $receipt) use ($overdueBefore): array {
            $layaway = $receipt->layaway;
            $customer = $receipt->customer;

            return [
                'id' => (int) $receipt->getKey(),
                'reviewUrl' => route('receipts.show', $receipt),
                'customerName' => $customer instanceof Customer ? $customer->full_name : null,
                'layawayCode' => $layaway instanceof Layaway ? $layaway->code : null,
                'methodLabel' => $receipt->payment_method->label(),
                'declared' => MoneyFormatter::format(Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency)),
                'submittedAt' => $receipt->created_at?->toIso8601String(),
                'overdue' => $receipt->created_at !== null && $receipt->created_at->lessThan($overdueBefore),
                'file' => [
                    'url' => SignedFileUrl::forBackoffice($receipt),
                    'kind' => $receipt->file_mime === 'application/pdf' ? 'pdf' : 'image',
                ],
            ];
        })->values()->all();
    }
}
