<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * La plataforma aprueba el comprobante de una factura de la suscripción
 * (`REQ-SUB-006` RB-4, RB-6, SP-2, SP-5, SP-8, `REQ-PLT-004` RB-3, `T-069`).
 *
 * Lo que hace, en una transacción y en este orden de bloqueo —suscripción,
 * factura, comprobante—:
 *
 *  1. **Convierte con la tasa de la plataforma** (RB-4): un comprobante en
 *     bolívares se pasa a dólares con la vigente de la plataforma
 *     (`ExchangeRateService::current(null)`), no la del negocio, o con la que
 *     escriba el operador; y la deja **congelada** en el comprobante. Sin tasa,
 *     `422` (SP-5).
 *  2. **Liquida la factura**, entera o en parte (SP-2): lo cobrado suma, y si
 *     no llega, la factura queda pendiente por el faltante exacto, sin tocar la
 *     suscripción.
 *  3. **Si llega, activa**: la factura `paid`, la suscripción `active`, el
 *     período extendido **al de esa factura** —no «el actual más un mes», que
 *     regalaría uno al pagar una deuda vieja— y cualquier suspensión levantada
 *     **si no queda otra factura vencida** (RB-6, `REQ-SUB-007` RB-5), sin
 *     republicar catálogos
 *     (`REQ-ORG-008` RB-4). Los demás comprobantes de la misma factura que
 *     siguieran en revisión se rechazan por «ya pagada» (SP-8).
 *  4. **Audita** (RB-10) con lo conciliado y la tasa.
 *
 * El operador es un `PlatformUser`, que no tiene modelo hasta `T-086`: por eso
 * el actor es un `Model` cualquiera o nulo, y `reviewed_by` queda nulo hasta
 * entonces.
 */
final class ApproveSubscriptionReceiptAction
{
    use LogsSensitiveActivity;

    /** El motivo con el que se rechazan los demás comprobantes de una factura liquidada (SP-8). */
    public const ALREADY_PAID_REASON = 'La factura ya está pagada con otro comprobante.';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * @throws ReceiptAlreadyProcessedException si el comprobante ya no está en revisión (`REQ-PLT-004` SP-1)
     * @throws InvoiceNotPayableException si la factura ya está pagada o anulada (SP-8)
     * @throws MissingExchangeRateException si es en bolívares y la plataforma no tiene tasa (SP-5)
     * @throws InvalidArgumentException si lo conciliado se convierte en cero dólares
     */
    public function handle(SubscriptionReceipt $receipt, ApproveSubscriptionReceiptData $data, ?Model $actor = null): SubscriptionInvoice
    {
        $organization = Organization::query()->findOrFail($receipt->organization_id);

        return $this->context->runFor($organization, fn (): SubscriptionInvoice => DB::transaction(function () use ($receipt, $data, $actor, $organization): SubscriptionInvoice {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->lockForUpdate()->firstOrFail();

            // Todo con bloqueo y **ninguna lectura sin bloqueo antes**: en
            // `REPEATABLE READ` la primera abre la instantánea, y un
            // comprobante que otro acaba de subir a esta factura no se vería
            // al buscar los demás (revisión de `T-069`). La factura de un
            // comprobante no cambia nunca, así que se toma del que llega.
            /** @var SubscriptionInvoice $invoice */
            $invoice = SubscriptionInvoice::query()->whereKey($receipt->subscription_invoice_id)->lockForUpdate()->firstOrFail();

            /** @var SubscriptionReceipt $locked */
            $locked = SubscriptionReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->awaitsReview()) {
                throw new ReceiptAlreadyProcessedException($locked->status, null);
            }

            if (! $invoice->status->acceptsReceipts()) {
                throw InvoiceNotPayableException::of($invoice);
            }

            [$dollars, $frozen] = $this->toDollars($data);

            $locked->forceFill([
                'status' => ReceiptStatus::Approved,
                'settled_amount' => $data->settledAmount,
                'settled_currency' => $data->settledCurrency,
                'settled_cents_usd' => $dollars->cents,
                'rate_applied' => $frozen['rate'],
                'rate_source' => $frozen['source'],
                'rate_published_at' => $frozen['publishedAt'],
                'reviewed_at' => CarbonImmutable::now(),
            ])->save();

            $before = ['status' => $invoice->status->value, 'paid_cents' => $invoice->paid_cents];
            $paid = $invoice->paid_cents + $dollars->cents;
            $settles = $paid >= $invoice->amount_cents;

            $invoice->forceFill([
                'paid_cents' => $paid,
                'status' => match (true) {
                    $settles => InvoiceStatus::Paid,
                    $this->othersAwaitReview($invoice, $locked) => InvoiceStatus::UnderReview,
                    default => InvoiceStatus::Pending,
                },
                'paid_at' => $settles ? CarbonImmutable::now() : null,
            ])->save();

            if ($settles) {
                $this->activate($subscription, $invoice, $organization);
                $this->rejectTheOthers($invoice, $locked);
            }

            $this->audit(
                AuditEvent::SubscriptionInvoicePaid,
                subject: $invoice,
                actor: $actor,
                before: $before,
                after: [
                    'status' => $invoice->status->value,
                    'paid_cents' => $invoice->paid_cents,
                    'outstanding_cents' => $invoice->outstanding()->cents,
                    'settled' => $data->settledAmount.' '.$data->settledCurrency->value,
                    'rate_applied' => $frozen['rate'],
                    'rate_source' => $frozen['source']->value,
                ],
                process: $actor === null ? 'plataforma' : null,
            );

            return $invoice;
        }));
    }

    /**
     * RB-6 · la suscripción queda al día hasta el fin del período que paga esa
     * factura, y cualquier suspensión se levanta.
     *
     * `grace` se respeta: es una bajada programada que el pago no cancela. Y
     * la organización suspendida vuelve a `active` sin republicar nada
     * (`REQ-ORG-008` RB-4).
     */
    private function activate(Subscription $subscription, SubscriptionInvoice $invoice, Organization $organization): void
    {
        $extends = $subscription->current_period_end === null || $invoice->period_end->greaterThan($subscription->current_period_end);

        // La factura paga el plan con el que se emitió: el actual, o el de la
        // bajada programada, que es el que factura la renovación cuando la
        // bajada vence al acabar el período (`IssueRenewalInvoices`). Si el
        // período que cubre va más allá del actual, ese es el nuevo período;
        // pagar una deuda vieja no lo encoge.
        $paysThisSubscription = $invoice->plan_id === $subscription->plan_id || $invoice->plan_id === $subscription->scheduled_plan_id;

        if ($extends && $paysThisSubscription) {
            $subscription->forceFill([
                'current_period_start' => $invoice->period_start,
                'current_period_end' => $invoice->period_end,
            ]);
        }

        // **Solo se levanta el impago si no queda otra factura vencida**
        // (revisión de `T-069`): pagar la de `$15` no salda la de `$39`.
        // Una suscripción cerrada (`cancelled`) no revive por un pago tardío.
        $owesMore = SubscriptionInvoice::query()
            ->whereKeyNot($invoice->getKey())
            ->where('status', InvoiceStatus::Pending)
            ->where('due_date', '<', CarbonImmutable::now($organization->timezone)->toDateString())
            ->exists();

        if (! $owesMore && $subscription->status !== SubscriptionStatus::Cancelled) {
            $subscription->forceFill([
                // Con una bajada programada, al día es `grace` (`ChangePlanAction`).
                'status' => $subscription->hasScheduledChange() ? SubscriptionStatus::Grace : SubscriptionStatus::Active,
                'grace_ends_at' => null,
            ]);
        }

        $subscription->save();

        // `T-071` · cuando exista `ReactivateOrganizationAction`, esto pasa
        // por ella (y su auditoría, `subscription.reactivated`).
        if (! $owesMore && $organization->status === OrganizationStatus::Suspended) {
            $organization->forceFill(['status' => OrganizationStatus::Active, 'suspended_at' => null])->save();
        }
    }

    /**
     * SP-8 · el primero aprobado liquida la factura y los demás que seguían en
     * revisión se rechazan por «ya pagada»: el negocio no tiene que esperar a
     * que alguien los mire para saber que sobran.
     */
    private function rejectTheOthers(SubscriptionInvoice $invoice, SubscriptionReceipt $approved): void
    {
        SubscriptionReceipt::query()
            ->where('subscription_invoice_id', $invoice->getKey())
            ->whereKeyNot($approved->getKey())
            ->where('status', ReceiptStatus::PendingReview)
            ->lockForUpdate()
            ->get()
            ->each(fn (SubscriptionReceipt $other) => $other->forceFill([
                'status' => ReceiptStatus::Rejected,
                'rejection_reason' => self::ALREADY_PAID_REASON,
                'reviewed_at' => CarbonImmutable::now(),
            ])->save());
    }

    private function othersAwaitReview(SubscriptionInvoice $invoice, SubscriptionReceipt $approved): bool
    {
        return SubscriptionReceipt::query()
            ->where('subscription_invoice_id', $invoice->getKey())
            ->whereKeyNot($approved->getKey())
            ->where('status', ReceiptStatus::PendingReview)
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Lo conciliado en dólares y la tasa que se congela con ello (RB-4).
     *
     * La tasa con la que se convierte es **la misma cadena** que se guarda en
     * `rate_applied`, a su escala (`DECIMAL(18,8)`), como hace `PaymentApplier`:
     * si no, el comprobante diría haber usado una tasa que no usó.
     *
     * @return array{0: Money, 1: array{rate: string, source: RateSource, publishedAt: ?CarbonImmutable}}
     *
     * @throws MissingExchangeRateException
     */
    private function toDollars(ApproveSubscriptionReceiptData $data): array
    {
        $settled = $data->settled();

        if ($settled->currency === Currency::USD) {
            return [$settled, ['rate' => '1.00000000', 'source' => $this->rates->platformSource(), 'publishedAt' => null]];
        }

        $rate = $data->manualRate() ?? $this->rates->current(null);

        if ($rate === null) {
            throw MissingExchangeRateException::whileSettling($this->rates->platformSource());
        }

        Money::assertUsableRate($rate->rate);
        $applied = bcadd($rate->rate, '0.000000005', ManualRate::SCALE);
        $dollars = $settled->convertTo(Currency::USD, new RateSnapshot($applied, RateSource::Manual, CarbonImmutable::now(), CarbonImmutable::now()));

        if ($dollars->cents <= 0) {
            throw new InvalidArgumentException(sprintf('%s a %s no llega a un centavo de dólar.', $data->settledAmount, $applied));
        }

        return [$dollars, $rate instanceof RateSnapshot
            ? ['rate' => $applied, 'source' => $rate->source, 'publishedAt' => $rate->publishedAt]
            : ['rate' => $applied, 'source' => RateSource::Manual, 'publishedAt' => null]];
    }
}
