<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Notifications\LayawayConfirmationNotification;
use App\Queries\ActivePaymentAccountsQuery;
use DateTimeZone;
use Illuminate\Support\Facades\Notification;

final class LayawayConfirmationMailer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
    ) {}

    public function send(Layaway $layaway): void
    {
        $email = $this->emailOf((int) $layaway->customer_id);

        if ($email === null) {
            return;
        }

        $terms = $layaway->terms();
        $first = $this->nextInstallment($layaway);
        $partial = $layaway->status === LayawayStatus::PendingDownPayment && $layaway->paid_cents->cents > 0
            ? new Money(min($terms->totalDownPayment()->cents, $layaway->paid_cents->cents))
            : null;

        Notification::route('mail', $email)->notify(new LayawayConfirmationNotification(
            code: $layaway->code,
            businessName: $this->context->organization()->name,
            productName: $layaway->product_name_snapshot,
            quantity: $terms->quantity,
            total: MoneyFormatter::format($terms->total()),
            downPayment: MoneyFormatter::format($terms->totalDownPayment()),
            expiresAt: $this->expiresAt($layaway) ?? '',
            paymentAccounts: $this->paymentAccounts->all(),
            requiresDownPayment: $terms->requiresDownPayment(),
            firstInstallment: $first === null ? null : MoneyFormatter::format($first->amount_cents->subtract($first->paid_cents)),
            firstDueDate: $first?->due_date->locale('es')->isoFormat('D [de] MMMM'),
            downPaymentPaid: $terms->requiresDownPayment() && $layaway->activated_at !== null,
            firstInstallmentNumber: $first === null ? 1 : (int) $first->number,
            firstInstallmentStarted: $first !== null && $first->paid_cents->cents > 0,
            fullyPaid: $layaway->status === LayawayStatus::Completed,
            downPaymentPaidSoFar: $partial === null ? null : MoneyFormatter::format($partial),
            downPaymentMissing: $partial === null ? null : MoneyFormatter::format($terms->totalDownPayment()->subtract($partial)),
        ));
    }

    public function emailOf(int $customerId): ?string
    {
        $email = Customer::query()->whereKey($customerId)->value('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function expiresAt(Layaway $layaway): ?string
    {
        return $layaway->expires_at
            ?->setTimezone(new DateTimeZone($this->context->organization()->timezone))
            ->locale('es')
            ->isoFormat('D [de] MMMM [a las] h:mm a');
    }

    private function nextInstallment(Layaway $layaway): ?Installment
    {
        return Installment::query()
            ->where('layaway_id', $layaway->getKey())
            ->where('status', '!=', InstallmentStatus::Paid)
            ->orderBy('number')
            ->first();
    }
}
