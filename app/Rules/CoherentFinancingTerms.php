<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Layaway\ScheduleGenerator;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class CoherentFinancingTerms implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    public const MAX_PRICE_CENTS = 100_000_000;

    public const MIN_INSTALLMENTS = ScheduleGenerator::MIN_INSTALLMENTS;

    public const MAX_INSTALLMENTS = ScheduleGenerator::MAX_INSTALLMENTS;

    public const MIN_FREQUENCY_DAYS = ScheduleGenerator::MIN_FREQUENCY_DAYS;

    public const MAX_FREQUENCY_DAYS = ScheduleGenerator::MAX_FREQUENCY_DAYS;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    private Validator $validator;

    /**
     * @return array<string, string>
     */
    public static function violationsIn(
        Money $price,
        ?Money $compareAtPrice,
        Money $downPayment,
        int $installmentsCount,
        int $frequencyDays,
        int $stock,
    ): array {
        $violations = [];

        if ($price->cents <= 0) {
            $violations['price'] = 'El precio tiene que ser mayor que cero.';
        } elseif ($price->cents > self::MAX_PRICE_CENTS) {
            $violations['price'] = 'El precio no puede pasar de $1.000.000.';
        }

        if ($downPayment->cents < 0) {
            $violations['down_payment_cents'] = 'La cuota inicial no puede ser negativa.';
        } elseif ($downPayment->cents >= $price->cents) {
            $violations['down_payment_cents'] = 'La cuota inicial tiene que ser menor que el precio: '
                .'con la inicial igual al precio no queda nada que financiar.';
        }

        if ($compareAtPrice instanceof Money && $compareAtPrice->cents <= $price->cents) {
            $violations['compare_at_price'] = 'El precio tachado tiene que ser mayor que el precio de venta.';
        }

        if ($installmentsCount < self::MIN_INSTALLMENTS || $installmentsCount > self::MAX_INSTALLMENTS) {
            $violations['installments_count'] = sprintf(
                'El número de cuotas tiene que estar entre %d y %d.',
                self::MIN_INSTALLMENTS,
                self::MAX_INSTALLMENTS,
            );
        }

        $financed = $price->cents - $downPayment->cents;

        if (! isset($violations['installments_count']) && ! isset($violations['down_payment_cents'])
            && $financed > 0 && $financed < $installmentsCount) {
            $violations['installments_count'] = sprintf(
                'Con %s por financiar caben como mucho %d cuotas: cada cuota tiene que ser de al menos un céntimo.',
                MoneyFormatter::format(new Money($financed)),
                $financed,
            );
        }

        if ($frequencyDays < self::MIN_FREQUENCY_DAYS || $frequencyDays > self::MAX_FREQUENCY_DAYS) {
            $violations['frequency_days'] = sprintf(
                'Los días entre cuotas tienen que estar entre %d y %d.',
                self::MIN_FREQUENCY_DAYS,
                self::MAX_FREQUENCY_DAYS,
            );
        }

        if ($stock < 0) {
            $violations['stock'] = 'Las existencias no pueden ser negativas.';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $price = $this->amount('price');
        $downPayment = $this->amount('down_payment');
        $installmentsCount = $this->integer('installments_count');
        $frequencyDays = $this->integer('frequency_days');
        $stock = $this->integer('stock');

        if ($price === null || $downPayment === null
            || $installmentsCount === null || $frequencyDays === null || $stock === null) {
            return;
        }

        $violations = self::violationsIn(
            $price,
            $this->amount('compare_at_price'),
            $downPayment,
            $installmentsCount,
            $frequencyDays,
            $stock,
        );

        foreach ($violations as $field => $message) {
            $this->validator->errors()->add(self::formFieldFor($field), $message);
        }
    }

    public static function formFieldFor(string $field): string
    {
        return $field === 'down_payment_cents' ? 'down_payment' : $field;
    }

    private function amount(string $key): ?Money
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || $value === '' || (! is_string($value) && ! is_numeric($value))) {
            return null;
        }

        try {
            return Money::fromDecimalString((string) $value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function integer(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
