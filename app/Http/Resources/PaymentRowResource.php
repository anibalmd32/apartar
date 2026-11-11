<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\HumanDate;
use App\Support\PaymentCorrectionForm;
use App\Support\SignedFileUrl;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentRowResource extends JsonResource
{
    public function __construct(Payment $payment, private readonly DateTimeZone $timezone, private readonly bool $voidable = false)
    {
        parent::__construct($payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;
        $inDollars = $payment->declared_currency === Currency::USD;
        $manual = $payment->receipt_id === null;
        $allocated = (int) $payment->allocations->sum(fn (PaymentAllocation $allocation): int => $allocation->amount_cents->cents);
        $voidedAt = $payment->voided_at;
        $downPayment = $voidedAt === null ? $payment->amount_cents_usd->cents - $allocated - $payment->unallocated_cents->cents : 0;

        return [
            'settledAt' => HumanDate::short($payment->settled_at->setTimezone($this->timezone)),
            'amountUsd' => [
                'cents' => $payment->amount_cents_usd->cents,
                'usd' => MoneyFormatter::format($payment->amount_cents_usd),
                'ves' => null,
            ],
            'declaredAmount' => MoneyFormatter::format(Money::fromDecimalString($payment->declared_amount, $payment->declared_currency)),
            'rateApplied' => $inDollars ? null : $payment->rate_applied,
            'rateSource' => $payment->rate_source->value,
            'rateSourceLabel' => $payment->rate_source->label(),
            'method' => $payment->payment_method?->label() ?? $payment->receipt?->payment_method->label() ?? 'Registrado por el negocio',
            'appliedTo' => $payment->allocations
                ->map(fn (PaymentAllocation $allocation): ?int => $allocation->installment?->number)
                ->filter()
                ->sort()
                ->values()
                ->all(),
            'downPayment' => $downPayment > 0 ? MoneyFormatter::format(new Money($downPayment)) : null,
            'unallocated' => $voidedAt === null && $payment->unallocated_cents->cents > 0 ? MoneyFormatter::format($payment->unallocated_cents) : null,
            'registeredByBusiness' => $manual,
            'fromReceipt' => ! $manual,
            'note' => $manual ? $payment->note : null,
            'bankReference' => $manual && $payment->reference !== null ? sprintf('%s · ref. %s', $payment->bank, $payment->reference) : null,
            'bank' => $manual && $payment->reference !== null ? $payment->bank : null,
            'bankLogoUrl' => $manual && $payment->reference !== null ? $payment->catalogBank?->logoUrl() : null,
            'attachmentUrl' => $manual && $payment->hasAttachment()
                ? ($request->routeIs('portal.*') ? SignedFileUrl::forCustomerPayment($payment) : SignedFileUrl::forBackofficePayment($payment))
                : null,
            'voided' => $voidedAt !== null ? [
                'at' => HumanDate::short($voidedAt->setTimezone($this->timezone)),
                'by' => $payment->voidedBy?->name,
                'reason' => $payment->void_reason,
            ] : null,
            'voidUrl' => $this->voidable && $voidedAt === null ? route('payments.void', $payment) : null,
            'correction' => $this->voidable && $voidedAt === null ? PaymentCorrectionForm::for($payment) : null,
        ];
    }
}
