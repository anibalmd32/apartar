<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * La plataforma rechaza el comprobante de una factura de la suscripción
 * (`REQ-SUB-006` RB-7, SP-3, `REQ-PLT-004` RB-3, `T-069`).
 *
 * El motivo es obligatorio, la factura **sigue pendiente** —vuelve a
 * `pending` si no queda otro comprobante en revisión— y el negocio puede
 * subir otro, con la misma mecánica que `REQ-PAY-006`. Si ya venció, entrar en
 * `past_due` con su gracia es del impago (`T-071`): este rechazo no toca la
 * suscripción, y la prórroga de 24 h de `REQ-SUB-007` SP-2 se calcula allí a
 * partir de `reviewed_at`.
 *
 * Sin auditoría de la organización: la lista cerrada de `REQ-BOF-005` RB-1 no
 * tiene un evento de rechazo de suscripción, y el registro de la plataforma
 * (`platform_activity_log`, `REQ-PLT-004` RB-7) es de `T-086`. El negocio lo
 * ve en su historial de facturas, con el motivo.
 */
final class RejectSubscriptionReceiptAction
{
    public const REASON_MAX = 500;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws InvalidArgumentException si no hay motivo, o pasa del máximo
     * @throws ReceiptAlreadyProcessedException si el comprobante ya no está en revisión
     */
    public function handle(SubscriptionReceipt $receipt, string $reason): SubscriptionReceipt
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException(sprintf('El motivo del rechazo es obligatorio y tiene como mucho %d caracteres.', self::REASON_MAX));
        }

        $organization = Organization::query()->findOrFail($receipt->organization_id);

        return $this->context->runFor($organization, fn (): SubscriptionReceipt => DB::transaction(function () use ($receipt, $reason): SubscriptionReceipt {
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
            ])->save();

            $othersPending = SubscriptionReceipt::query()
                ->where('subscription_invoice_id', $invoice->getKey())
                ->where('status', ReceiptStatus::PendingReview)
                ->exists();

            if ($invoice->status === InvoiceStatus::UnderReview && ! $othersPending) {
                $invoice->forceFill(['status' => InvoiceStatus::Pending])->save();
            }

            return $locked;
        }));
    }
}
