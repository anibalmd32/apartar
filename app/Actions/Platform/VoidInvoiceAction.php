<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceAlreadyPaidException;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\PlatformUser;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class VoidInvoiceAction
{
    use LogsSensitiveActivity;

    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws InvoiceAlreadyPaidException
     */
    public function handle(SubscriptionInvoice $invoice, PlatformUser $operator, string $reason): SubscriptionInvoice
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException(sprintf('El motivo de la anulación va de %d a %d caracteres.', self::REASON_MIN, self::REASON_MAX));
        }

        $organization = $this->resolver->forOrganizationId((int) $invoice->organization_id);

        $voided = $this->context->runFor($organization, fn (): SubscriptionInvoice => DB::transaction(function () use ($invoice, $operator, $reason): SubscriptionInvoice {
            /** @var SubscriptionInvoice $locked */
            $locked = SubscriptionInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceStatus::Paid) {
                throw new InvoiceAlreadyPaidException;
            }

            if ($locked->status === InvoiceStatus::Void) {
                return $locked;
            }

            $now = CarbonImmutable::now();
            $before = ['status' => $locked->status->value];

            SubscriptionReceipt::query()
                ->where('subscription_invoice_id', $locked->getKey())
                ->where('status', ReceiptStatus::PendingReview)
                ->update(['status' => ReceiptStatus::Rejected, 'rejection_reason' => 'La factura se anuló: '.$reason, 'reviewed_at' => $now, 'reviewed_by' => $operator->getKey()]);

            $locked->forceFill(['status' => InvoiceStatus::Void, 'voided_at' => $now, 'void_reason' => $reason])->save();

            $this->audit(AuditEvent::PlatformSubscriptionOverridden, $locked, $operator, $before, ['status' => InvoiceStatus::Void->value, 'void_reason' => $reason]);

            return $locked;
        }));

        PlatformAudit::record(PlatformAudit::INVOICE_VOIDED, 'Anuló la factura #'.$invoice->getKey().': '.$reason, $operator, (int) $invoice->organization_id, ['invoice_id' => $invoice->getKey()]);

        return $voided;
    }
}
