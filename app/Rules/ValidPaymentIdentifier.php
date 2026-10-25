<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Payment\PaymentMethod;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class ValidPaymentIdentifier implements ValidationRule
{
    private const ACCOUNT_DIGITS = 20;

    private const ADDRESS_MINIMUM = 10;

    public function __construct(private PaymentMethod $method) {}

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $identifier = is_string($value) ? trim($value) : '';

        if ($identifier === '') {
            $fail($this->expectation());

            return;
        }

        $valid = match ($this->method) {
            PaymentMethod::PagoMovil => $this->isVenezuelanMobile($identifier),
            PaymentMethod::Transferencia => $this->isNationalAccountNumber($identifier),
            PaymentMethod::Zelle => $this->isEmail($identifier),
            PaymentMethod::BinancePay => $this->isBinancePayId($identifier) || $this->isEmail($identifier),
            PaymentMethod::EfectivoUsd, PaymentMethod::EfectivoVes => $this->isDeliveryAddress($identifier),
        };

        if (! $valid) {
            $fail($this->expectation());
        }
    }

    private function expectation(): string
    {
        return match ($this->method) {
            PaymentMethod::PagoMovil => 'Escribe un teléfono venezolano de pago móvil, como 0414-1234567.',
            PaymentMethod::Transferencia => 'El número de cuenta tiene 20 dígitos, los mismos que aparecen en tu libreta.',
            PaymentMethod::Zelle => 'Escribe el correo con el que recibes Zelle.',
            PaymentMethod::BinancePay => 'Escribe tu Pay ID de Binance (solo números) o el correo de tu cuenta.',
            PaymentMethod::EfectivoUsd, PaymentMethod::EfectivoVes => 'Indica dónde se entrega el efectivo, con dirección suficiente para llegar.',
        };
    }

    private function isVenezuelanMobile(string $identifier): bool
    {
        $digits = $this->digitsOf($identifier);

        if (str_starts_with($digits, '58') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return false;
        }

        return in_array(substr($digits, 0, 3), PhoneNumber::MOBILE_PREFIXES, true);
    }

    private function isNationalAccountNumber(string $identifier): bool
    {
        return strlen($this->digitsOf($identifier)) === self::ACCOUNT_DIGITS;
    }

    private function isBinancePayId(string $identifier): bool
    {
        return preg_match('/^\d{8,20}$/', $identifier) === 1;
    }

    private function isEmail(string $identifier): bool
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isDeliveryAddress(string $identifier): bool
    {
        return mb_strlen($identifier) >= self::ADDRESS_MINIMUM;
    }

    private function digitsOf(string $identifier): string
    {
        return (string) preg_replace('/\D/', '', $identifier);
    }
}
