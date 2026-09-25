<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\InvalidPhoneNumberException;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * El campo es un teléfono venezolano que se puede guardar (`REQ-IDN-007`
 * RB-4, SP-5, SP-7).
 *
 * Como `ValidDocumentNumber`: delega en `PhoneNumber::fromInput()` y enseña su
 * mensaje. El teléfono es obligatorio (`REQ-IDN-001` RB-2), así que un valor
 * que no es cadena —un campo ausente que llegó hasta aquí— falla con el mismo
 * mensaje que uno vacío.
 */
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
