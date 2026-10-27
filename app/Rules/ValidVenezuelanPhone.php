<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\InvalidPhoneNumberException;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class ValidVenezuelanPhone implements ValidationRule
{
    public const MOBILE_ONLY = 'Elige el prefijo de tu celular: 0412, 0414, 0416, 0422, 0424 o 0426.';

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(InvalidPhoneNumberException::empty()->getMessage());

            return;
        }

        try {
            $phone = PhoneNumber::fromInput($value);
        } catch (InvalidPhoneNumberException $invalid) {
            $fail($invalid->getMessage());

            return;
        }

        if (! $phone->isMobile()) {
            $fail(self::MOBILE_ONLY);
        }
    }
}
