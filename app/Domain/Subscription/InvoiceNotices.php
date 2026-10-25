<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionInvoiceNotice;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use App\Notifications\InvoiceOverdueNotification;
use App\Notifications\SubscriptionReceiptReviewedNotification;
use App\Notifications\SubscriptionSuspendedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class InvoiceNotices
{
    public function __construct(private readonly TenantContext $context) {}

    public function sendOnce(SubscriptionInvoice $invoice, InvoiceNoticeType $type, ?CarbonImmutable $graceLastDay = null): bool
    {
        $organization = Organization::query()->findOrFail($invoice->organization_id);

        return $this->context->runFor($organization, function () use ($invoice, $type, $graceLastDay): bool {
            try {
                DB::transaction(function () use ($invoice, $type, $graceLastDay): void {
                    SubscriptionInvoiceNotice::query()->create([
                        'subscription_invoice_id' => $invoice->getKey(),
                        'type' => $type,
                        'sent_at' => CarbonImmutable::now(),
                    ]);

                    Notification::send($this->owners(), new InvoiceOverdueNotification(
                        $type,
                        MoneyFormatter::format($invoice->outstanding()),
                        $invoice->due_date->toDateString(),
                        $graceLastDay?->toDateString(),
                        route('subscription.invoices.index'),
                    ));
                });
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        });
    }

    public function reviewed(SubscriptionReceipt $receipt, SubscriptionInvoice $invoice): void
    {
        $organization = Organization::query()->findOrFail($receipt->organization_id);

        $this->context->runFor($organization, function () use ($receipt, $invoice): void {
            [$outcome, $outstanding, $reason] = match (true) {
                $receipt->status === ReceiptStatus::Rejected => [SubscriptionReceiptReviewedNotification::REJECTED, null, $receipt->rejection_reason],
                $invoice->status === InvoiceStatus::Paid => [SubscriptionReceiptReviewedNotification::APPROVED, null, null],
                default => [SubscriptionReceiptReviewedNotification::PARTIAL, MoneyFormatter::format($invoice->outstanding()), null],
            };

            Notification::send($this->owners(), new SubscriptionReceiptReviewedNotification(
                $outcome,
                $outstanding,
                $reason,
                route('subscription.invoices.index'),
            ));
        });
    }

    public function suspended(Organization $organization): void
    {
        $this->context->runFor($organization, function (): void {
            Notification::send($this->owners(), new SubscriptionSuspendedNotification(route('subscription.invoices.index')));
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function owners(): Collection
    {
        return User::query()
            ->where('role', OrganizationRole::Owner)
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->get();
    }
}
