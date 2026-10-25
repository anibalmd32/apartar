<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceNotices;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class RejectSubscriptionReceiptAction
{
    public const REASON_MAX = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoiceNotices $notices,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws ReceiptAlreadyProcessedException
     */
    public function handle(SubscriptionReceipt $receipt, string $reason, ?PlatformUser $actor = null): SubscriptionReceipt
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException(sprintf('El motivo del rechazo es obligatorio y tiene como mucho %d caracteres.', self::REASON_MAX));
        }

        $organization = Organization::query()->findOrFail($receipt->organization_id);

        return $this->context->runFor($organization, fn (): SubscriptionReceipt => DB::transaction(function () use ($receipt, $reason, $actor): SubscriptionReceipt {
            /** @var SubscriptionInvoice $invoice */
            $invoice = SubscriptionInvoice::query()->whereKey($receipt->subscription_invoice_id)->lockForUpdate()->firstOrFail();

            /** @var SubscriptionReceipt $locked */
            $locked = SubscriptionReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->awaitsReview()) {
                throw new ReceiptAlreadyProcessedException($locked->status, null);
            }

            $locked->forceFill([
                'status' => ReceiptStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_at' => CarbonImmutable::now(),
                'reviewed_by' => $actor?->getKey(),
            ])->save();

            $othersPending = SubscriptionReceipt::query()
                ->where('subscription_invoice_id', $invoice->getKey())
                ->where('status', ReceiptStatus::PendingReview)
                ->exists();

            if ($invoice->status === InvoiceStatus::UnderReview && ! $othersPending) {
                $invoice->forceFill(['status' => InvoiceStatus::Pending])->save();
            }

            DB::afterCommit(function () use ($locked, $invoice): void {
                try {
                    $this->notices->reviewed($locked, $invoice);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            return $locked;
        }));
    }
}
