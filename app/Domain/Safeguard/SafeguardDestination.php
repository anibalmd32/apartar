<?php

declare(strict_types=1);

namespace App\Domain\Safeguard;

enum SafeguardDestination: string
{
    case BinanceUsdt = 'binance_usdt';

    case CryptoWallet = 'crypto_wallet';

    case CashUsd = 'cash_usd';

    case Zelle = 'zelle';

    case BankAbroad = 'bank_abroad';

    case CreditCard = 'credit_card';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BinanceUsdt => 'Binance (USDT)',
            self::CryptoWallet => 'Otra billetera de criptomonedas',
            self::CashUsd => 'Divisas en efectivo',
            self::Zelle => 'Zelle',
            self::BankAbroad => 'Cuenta bancaria en el exterior',
            self::CreditCard => 'Tarjeta de crédito',
            self::Other => 'Otro',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $destination): array => ['value' => $destination->value, 'label' => $destination->label()], self::cases());
    }
}
