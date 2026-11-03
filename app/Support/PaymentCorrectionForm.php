<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Models\Payment;

final class PaymentCorrectionForm
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Payment $payment): array
    {
        $receipt = $payment->receipt;
        $rate = rtrim(rtrim($payment->rate_applied, '0'), '.');

        return [
            'storeUrl' => route('payments.correct', $payment),
            'previewUrl' => route('payments.correct.preview', $payment),
            'targetUrl' => route('payments.target', $payment),
            'values' => [
                'method' => $payment->payment_method->value ?? $receipt?->payment_method->value ?? '',
                'payment_account' => (string) ($payment->payment_account_id ?? $receipt->payment_account_id ?? ''),
                'amount' => number_format((float) $payment->declared_amount, 2, ',', '.'),
                'currency' => $payment->declared_currency->value,
                'rate' => $payment->declared_currency !== Currency::USD && $payment->rate_source === RateSource::Manual ? str_replace('.', ',', $rate) : '',
                'paid_at' => $payment->paid_on?->toDateString() ?? $receipt?->paid_at->toDateString() ?? '',
                'bank' => $payment->bank ?? $receipt->bank ?? '',
                'reference' => $payment->reference ?? $receipt->reference ?? '',
            ],
        ];
    }
}
