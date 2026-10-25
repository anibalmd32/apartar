<?php

declare(strict_types=1);

use App\Actions\Subscription\ApproveSubscriptionReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;

it('no se construye con un importe que no es positivo', function (string $amount): void {
    new ApproveSubscriptionReceiptData($amount, Currency::USD);
})->with(['0', '-5.00', 'quince'])->throws(InvalidArgumentException::class);

it('no se construye con una tasa que no es una tasa', function (): void {
    new ApproveSubscriptionReceiptData('600.00', Currency::VES, 'cuarenta');
})->throws(InvalidRateException::class);

it('usa la tasa a mano solo para convertir bolívares', function (): void {
    expect((new ApproveSubscriptionReceiptData('600.00', Currency::VES, '40.00'))->manualRate()?->rate)->toBe('40.00')
        ->and((new ApproveSubscriptionReceiptData('15.00', Currency::USD, '40.00'))->manualRate())->toBeNull()
        ->and((new ApproveSubscriptionReceiptData('15.00', Currency::USD))->settled()->cents)->toBe(1_500);
});
