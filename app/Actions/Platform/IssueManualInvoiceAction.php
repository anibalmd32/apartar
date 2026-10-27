<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Subscription\IssueInvoiceAction;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IssueManualInvoiceAction
{
    public const REASON_MIN = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly IssueInvoiceAction $invoices,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(Organization $organization, PlatformUser $operator, string $reason): SubscriptionInvoice
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(sprintf('El motivo va de %d a 500 caracteres.', self::REASON_MIN));
        }

        $invoice = $this->context->runFor($organization, fn (): SubscriptionInvoice => DB::transaction(function () use ($organization, $operator): SubscriptionInvoice {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->with('plan')->lockForUpdate()->firstOrFail();

            if ($subscription->status === SubscriptionStatus::Cancelled || $subscription->plan->price_cents <= 0) {
                throw new InvalidArgumentException('Solo se factura una suscripción vigente de un plan de pago.');
            }

            $outstanding = array_map(fn (InvoiceStatus $status): string => $status->value, array_filter(InvoiceStatus::cases(), fn (InvoiceStatus $status): bool => $status->isOutstanding()));

            if (SubscriptionInvoice::query()->whereIn('status', $outstanding)->exists()) {
                throw new InvalidArgumentException('El negocio ya tiene una factura por pagar: cóbrala o anúlala antes de emitir otra.');
            }

            $now = CarbonImmutable::now();
            $start = $subscription->current_period_end?->toImmutable() ?? $now;

            if (SubscriptionInvoice::query()->where('period_start', $start)->where('status', '!=', InvoiceStatus::Void->value)->exists()) {
                throw new InvalidArgumentException('Ese período ya está facturado.');
            }

            $today = $now->setTimezone($organization->timezone)->startOfDay();
            $due = $start->setTimezone($organization->timezone)->startOfDay();

            try {
                return $this->invoices->handle(
                    $subscription,
                    $subscription->plan,
                    InvoiceKind::Manual,
                    $start,
                    $subscription->plan->periodEndFrom($start, $start->day),
                    $due->greaterThan($today) ? $due : $today,
                    $operator,
                );
            } catch (OrganizationDocumentRequiredException) {
                throw new InvalidArgumentException('El negocio no tiene RIF ni cédula del dueño y es su primera factura: pídele que lo complete.');
            }
        }));

        $number = $this->context->runFor($organization, fn (): string => $invoice->number());

        PlatformAudit::record(PlatformAudit::INVOICE_ISSUED, sprintf('Emitió la factura %s, del %s al %s: %s', $number, $invoice->period_start->toDateString(), $invoice->period_end->toDateString(), $reason), $operator, (int) $organization->getKey(), [
            'invoice_id' => $invoice->getKey(),
        ]);

        return $invoice;
    }
}
