<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Notifications\CustomerMessageNotification;
use Carbon\CarbonInterface;
use DateTimeZone;

final class CustomerNotices
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly NotifiableResolver $resolver,
    ) {}

    public function activated(Layaway $layaway): void
    {
        $this->send($layaway, NotificationType::LayawayActivated, [$this->line('layaway_activated.line', $layaway)]);
    }

    public function completed(Layaway $layaway): void
    {
        $this->send($layaway, NotificationType::LayawayCompleted, [$this->line('layaway_completed.line', $layaway)]);
    }

    public function cancelled(Layaway $layaway): void
    {
        $this->send($layaway, NotificationType::LayawayCancelled, [$this->line('layaway_cancelled.line', $layaway)]);
    }

    public function expired(Layaway $layaway): void
    {
        $this->send($layaway, NotificationType::LayawayExpired, [$this->line('layaway_expired.line', $layaway)]);
    }

    public function delivered(Layaway $layaway): void
    {
        $this->send($layaway, NotificationType::LayawayDelivered, [$this->line('layaway_delivered.line', $layaway)]);
    }

    public function paymentCredited(Layaway $layaway, Money $amount): void
    {
        $this->send($layaway, NotificationType::PaymentCredited, [$this->line('payment_credited.line', $layaway, [
            'amount' => MoneyFormatter::format($amount),
            'remaining' => MoneyFormatter::format($layaway->remaining_cents),
        ])]);
    }

    public function receiptRejected(Layaway $layaway, string $reason, ?CarbonInterface $newDeadline): void
    {
        $lines = [$this->line('receipt_rejected.line', $layaway, ['reason' => $reason])];

        if ($newDeadline !== null) {
            $lines[] = __('notifications.receipt_rejected.extended', ['date' => $this->localDate($newDeadline)], 'es');
        }

        $this->send($layaway, NotificationType::ReceiptRejected, $lines, action: __('notifications.report_payment', [], 'es'), url: route('portal.receipts.create', ['code' => $layaway->code]));
    }

    public function downPaymentReminder(Layaway $layaway): void
    {
        $this->send($layaway, NotificationType::DownPaymentReminder, [$this->line('down_payment_reminder.line', $layaway, [
            'amount' => MoneyFormatter::format($layaway->terms()->totalDownPayment()),
            'date' => $layaway->expires_at === null ? '' : $this->localDate($layaway->expires_at),
        ])], action: __('notifications.report_payment', [], 'es'), url: route('portal.receipts.create', ['code' => $layaway->code]));
    }

    /**
     * @param  list<array{installment: Installment, layaway: Layaway, offset: int}>  $items
     */
    public function installmentReminder(Customer $customer, array $items): void
    {
        if ($items === []) {
            return;
        }

        $lines = array_map(function (array $item): string {
            $key = match (true) {
                $item['offset'] < 0 => 'line_upcoming',
                $item['offset'] === 0 => 'line_due',
                default => 'line_overdue',
            };

            return __('notifications.installment_reminder.'.$key, [
                'product' => $item['layaway']->product_name_snapshot,
                'code' => $item['layaway']->code,
                'number' => $item['installment']->number,
                'amount' => MoneyFormatter::format($item['installment']->amount_cents->subtract($item['installment']->paid_cents)),
                'date' => $item['installment']->due_date->locale('es')->isoFormat('D [de] MMMM'),
            ], 'es');
        }, $items);

        $first = $items[0]['layaway'];

        $this->deliver($customer, NotificationType::InstallmentReminder, $lines, __('notifications.report_payment', [], 'es'), route('portal.receipts.create', ['code' => $first->code]));
    }

    /**
     * @param  list<string>  $lines
     */
    private function send(Layaway $layaway, NotificationType $type, array $lines, ?string $action = null, ?string $url = null): void
    {
        $customer = Customer::query()->find($layaway->customer_id);

        if (! $customer instanceof Customer) {
            return;
        }

        $this->deliver(
            $customer,
            $type,
            $lines,
            $action ?? __('notifications.open_portal', [], 'es'),
            $url ?? route('portal.layaways.show', ['code' => $layaway->code]),
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function deliver(Customer $customer, NotificationType $type, array $lines, string $action, string $url): void
    {
        $business = $this->context->organization()->name;
        $feature = $type->pushFeature();

        $this->resolver->for($customer)->notify(new CustomerMessageNotification(
            $type,
            $business,
            __('notifications.'.$type->value.'.title', ['negocio' => $business], 'es'),
            $lines,
            $url,
            $action,
            push: $feature === null || $this->limits->hasFeature($feature),
        ));
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function line(string $key, Layaway $layaway, array $extra = []): string
    {
        return __('notifications.'.$key, ['product' => $layaway->product_name_snapshot, 'code' => $layaway->code, ...$extra], 'es');
    }

    private function localDate(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(new DateTimeZone($this->context->organization()->timezone))->locale('es')->isoFormat('D [de] MMMM');
    }
}
