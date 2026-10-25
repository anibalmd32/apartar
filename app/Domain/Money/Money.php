<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Exchange\RateSnapshot;
use InvalidArgumentException;

final readonly class Money
{
    private const RATE_SCALE = 8;

    private const DECIMAL_PATTERN = '/^-?(\d+(\.\d{1,2})?|\.\d{1,2})$/';

    private const RATE_PATTERN = '/^-?(\d+(\.\d+)?|\.\d+)$/';

    public function __construct(public int $cents, public Currency $currency = Currency::USD) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromDecimalString(string $amount, Currency $c = Currency::USD): self
    {
        if (preg_match(self::DECIMAL_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El importe "%s" no es un decimal de hasta dos cifras. Se admite un signo negativo, dígitos y '
                .'como mucho dos decimales ("19.99", "-0.50", ".99", "1200"): ni separadores de millar —"1.234" '
                .'es ambiguo entre mil doscientos treinta y cuatro y uno con pico—, ni notación exponencial, ni '
                .'espacios alrededor. Si vienen más decimales, decide tú cómo redondearlos antes de llamar.',
                $amount,
            ));
        }

        return new self((int) bcmul($amount, '100', 0), $c);
    }

    /**
     * @throws CurrencyMismatchException
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other, 'sumar');

        return new self($this->cents + $other->cents, $this->currency);
    }

    /**
     * @throws CurrencyMismatchException
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other, 'restar');

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->cents * $factor, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isZeroOrBelowTolerance(int $toleranceCents): bool
    {
        return $this->cents <= $toleranceCents;
    }

    /**
     * @return list<self>
     *
     * @throws InvalidArgumentException
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException(sprintf(
                'No se puede repartir un importe en %d partes: hacen falta al menos 1. Un reparto en cero partes '
                .'devolvería una lista vacía que suma cero y perdería los %d céntimos por el camino.',
                $parts,
                $this->cents,
            ));
        }

        $base = intdiv($this->cents, $parts);

        $allocated = [];

        for ($part = 1; $part < $parts; $part++) {
            $allocated[] = new self($base, $this->currency);
        }

        $allocated[] = new self($this->cents - $base * ($parts - 1), $this->currency);

        return $allocated;
    }

    /**
     * @throws InvalidRateException
     */
    public function convertTo(Currency $target, RateSnapshot $rate): self
    {
        if ($target === $this->currency) {
            return new self($this->cents, $this->currency);
        }

        $multiplier = self::assertUsableRate($rate->rate);

        $converted = match ($this->currency) {
            Currency::USD => bcmul((string) $this->cents, $multiplier, self::RATE_SCALE),
            Currency::VES => bcdiv((string) $this->cents, $multiplier, self::RATE_SCALE),
        };

        return new self((int) self::roundHalfUp($converted), $target);
    }

    /**
     * @throws CurrencyMismatchException
     */
    private function assertSameCurrency(self $other, string $operation): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency, $operation);
        }
    }

    /**
     * @throws InvalidRateException
     */
    public static function assertUsableRate(string $rate): string
    {
        if (preg_match(self::RATE_PATTERN, $rate) !== 1) {
            throw InvalidRateException::malformed($rate);
        }

        if (bccomp($rate, '0', self::RATE_SCALE) <= 0) {
            throw InvalidRateException::notPositive($rate);
        }

        return $rate;
    }

    private static function roundHalfUp(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.5' : '0.5', 0);
    }
}
