<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Payment\ReceiptStatus;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use Carbon\CarbonImmutable;

final class SubscriptionReceiptInbox
{
    public const LATE_HOURS = 48;

    public const LIMIT = 100;

    public function __construct(private readonly PlatformMoney $money) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $now = CarbonImmutable::now();

        return SubscriptionReceipt::query()->withoutTenantScope()
            ->with([
                'invoice' => fn ($query) => $query->withoutTenantScope()->with('plan:id,code,name'),
                'organization:id,name,slug',
            ])
            ->where('status', ReceiptStatus::PendingReview)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SubscriptionReceipt $receipt): array => $this->row($receipt, $now))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoicesOf(int $organizationId, int $limit = 12): array
    {
        return SubscriptionInvoice::query()->withoutTenantScope()
            ->with('plan:id,code,name')
            ->where('organization_id', $organizationId)
            ->latest('issued_at')
            ->limit($limit)
            ->get()
            ->map(fn (SubscriptionInvoice $invoice): array => [
                'id' => $invoice->getKey(),
                'plan' => $invoice->plan->name,
                'kind' => $invoice->kind->value,
                'status' => $invoice->status->value,
                'amount' => $this->money->view($invoice->amount_cents),
                'paid' => $this->money->view($invoice->paid_cents),
                'dueDate' => $invoice->due_date->toDateString(),
                'periodEnd' => $invoice->period_end->toDateString(),
                'voidReason' => $invoice->void_reason,
            ])
            ->all();
    }

    public function find(int $receiptId): ?SubscriptionReceipt
    {
        return SubscriptionReceipt::query()->withoutTenantScope()->find($receiptId);
    }

    public function findInvoice(int $invoiceId): ?SubscriptionInvoice
    {
        return SubscriptionInvoice::query()->withoutTenantScope()->find($invoiceId);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SubscriptionReceipt $receipt, CarbonImmutable $now): array
    {
        $invoice = $receipt->invoice;

        return [
            'id' => $receipt->getKey(),
            'organization' => ['id' => $receipt->organization_id, 'name' => $receipt->organization->name ?? '—'],
            'invoiceId' => $invoice->getKey(),
            'plan' => $invoice->plan->name,
            'outstanding' => $this->money->view(max(0, $invoice->amount_cents - $invoice->paid_cents)),
            'declared' => $receipt->declared_amount.' '.$receipt->declared_currency->value,
            'method' => $receipt->payment_method->label(),
            'bank' => $receipt->bank,
            'reference' => $receipt->reference,
            'paidAt' => $receipt->paid_at->toDateString(),
            'submittedAt' => $receipt->created_at?->toIso8601String(),
            'isLate' => $receipt->created_at !== null && $receipt->created_at->lessThan($now->subHours(self::LATE_HOURS)),
            'fileUrl' => route('platform.receipts.file', $receipt->getKey()),
        ];
    }
}
