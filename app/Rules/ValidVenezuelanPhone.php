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
            PhoneNumber::fromInput($value);
        } catch (InvalidPhoneNumberException $invalid) {
            $fail($invalid->getMessage());
        }
    }
}
