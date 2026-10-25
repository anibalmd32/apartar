<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Currency;

enum PaymentMethod: string
{
    case PagoMovil = 'pago_movil';

    case Transferencia = 'transferencia';

    case Zelle = 'zelle';

    case BinancePay = 'binance_pay';

    case EfectivoUsd = 'efectivo_usd';

    case EfectivoVes = 'efectivo_ves';

    public function identifierLabel(): string
    {
        return match ($this) {
            self::PagoMovil => 'Teléfono',
            self::Transferencia => 'Número de cuenta',
            self::Zelle => 'Correo de Zelle',
            self::BinancePay => 'Pay ID o correo de Binance',

            self::EfectivoUsd, self::EfectivoVes => 'Dirección de entrega',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PagoMovil => 'Pago móvil',
            self::Transferencia => 'Transferencia bancaria',
            self::Zelle => 'Zelle',
            self::BinancePay => 'Binance Pay',
            self::EfectivoUsd => 'Efectivo en dólares',
            self::EfectivoVes => 'Efectivo en bolívares',
        };
    }

    public function isCash(): bool
    {
        return $this === self::EfectivoUsd || $this === self::EfectivoVes;
    }

    public function requiresReference(): bool
    {
        return match ($this) {
            self::PagoMovil, self::Transferencia, self::Zelle, self::BinancePay => true,
            self::EfectivoUsd, self::EfectivoVes => false,
        };
    }

    public function defaultCurrency(): Currency
    {
        return match ($this) {
            self::PagoMovil, self::Transferencia, self::EfectivoVes => Currency::VES,
            self::Zelle, self::BinancePay, self::EfectivoUsd => Currency::USD,
        };
    }
}
