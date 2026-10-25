<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Actions\Organization\ReactivateOrganizationAction;
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
use App\Domain\Subscription\InvoiceNotices;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Queries\OverdueSubscriptionInvoices;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ApproveSubscriptionReceiptAction
{
    use LogsSensitiveActivity;

    public const ALREADY_PAID_REASON = 'La factura ya está pagada con otro comprobante.';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
        private readonly OverdueSubscriptionInvoices $overdue,
        private readonly ReactivateOrganizationAction $reactivate,
        private readonly InvoiceNotices $notices,
    ) {}

    /**
     * @throws ReceiptAlreadyProcessedException
     * @throws InvoiceNotPayableException
     * @throws MissingExchangeRateException
     * @throws InvalidArgumentException
     */
    public function handle(SubscriptionReceipt $receipt, ApproveSubscriptionReceiptData $data, ?Model $actor = null): SubscriptionInvoice
    {
        $organization = Organization::query()->findOrFail($receipt->organization_id);

        return $this->context->runFor($organization, fn (): SubscriptionInvoice => DB::transaction(function () use ($receipt, $data, $actor, $organization): SubscriptionInvoice {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->lockForUpdate()->firstOrFail();

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
                'reviewed_by' => $actor instanceof PlatformUser ? $actor->getKey() : null,
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
                $this->activate($subscription, $invoice, $organization, $actor);
                $this->rejectTheOthers($invoice, $locked);
            }

            DB::afterCommit(function () use ($locked, $invoice): void {
                try {
                    $this->notices->reviewed($locked, $invoice);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

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

    private function activate(Subscription $subscription, SubscriptionInvoice $invoice, Organization $organization, ?Model $actor): void
    {
        $extends = $subscription->current_period_end === null || $invoice->period_end->greaterThan($subscription->current_period_end);

        $invoice->loadMissing('plan');
        $subscription->loadMissing('plan');
        $paysThisSubscription = $invoice->plan_id === $subscription->plan_id
            || $invoice->plan_id === $subscription->scheduled_plan_id
            || $invoice->plan->ranksAbove($subscription->plan);

        if ($extends && $paysThisSubscription) {
            $subscription->forceFill([
                'current_period_start' => $invoice->period_start,
                'current_period_end' => $invoice->period_end,
            ])->save();
        }

        if ($subscription->status === SubscriptionStatus::Cancelled || $this->overdue->anyBesides($invoice, CarbonImmutable::now(), $organization->timezone)) {
            return;
        }

        if ($this->reactivate->handle($organization, $actor, 'plataforma')) {
            return;
        }

        $subscription->forceFill([
            'status' => $subscription->hasScheduledChange() ? SubscriptionStatus::Grace : SubscriptionStatus::Active,
            'grace_ends_at' => null,
        ])->save();
    }

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
