<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\DocumentNumber;
use App\Support\InvalidDocumentNumberException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class ValidDocumentNumber implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(InvalidDocumentNumberException::empty()->getMessage());

            return;
        }

        try {
            DocumentNumber::fromInput($value);
        } catch (InvalidDocumentNumberException $invalid) {
            $fail($invalid->getMessage());
        }
    }
}
