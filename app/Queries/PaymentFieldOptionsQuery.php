<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentAccount;

final readonly class PaymentFieldOptionsQuery
{
    public function __construct(
        private TenantContext $context,
        private ExchangeRateService $rates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $earliest, string $latest): array
    {
        $rate = $this->rates->current($this->context->organization());

        return [
            'methods' => array_map(fn (PaymentMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'defaultCurrency' => $method->defaultCurrency()->value,
                'requiresReference' => $method->requiresReference(),
            ], PaymentMethod::cases()),
            'paymentAccounts' => PaymentAccount::query()->orderBy('position')->get()
                ->map(fn (PaymentAccount $account): array => [
                    'id' => (int) $account->getKey(),
                    'methodLabel' => $account->method->label(),
                    'identifier' => $account->identifier,
                    'isActive' => $account->is_active,
                ])
                ->values()
                ->all(),
            'currencies' => array_map(fn (Currency $currency): array => ['value' => $currency->value, 'label' => $currency->label()], Currency::cases()),
            'paidAt' => ['min' => $earliest, 'max' => $latest],
            'rate' => $rate instanceof RateSnapshot ? ['rate' => $rate->rate, 'sourceLabel' => $rate->source->label()] : null,
            'file' => ['maxMb' => ReceiptFile::maxSizeMb(), 'accept' => ReceiptFile::ACCEPT],
        ];
    }
}
