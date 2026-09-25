<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\DocumentNumber;
use App\Support\InvalidDocumentNumberException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * El campo es una cédula o un RIF que se puede guardar (`REQ-IDN-007`, SP-1,
 * SP-2, SP-3).
 *
 * **Delega en `DocumentNumber::fromInput()` y no repite nada**: la regla y el
 * VO no pueden discrepar sobre qué es un documento, porque solo uno de los dos
 * lo sabe. Lo que añade es el mensaje, que sale de la excepción tal cual y
 * dice qué arreglar —el rango, las letras que valen—: quien lo lee está a
 * punto de apartar.
 *
 * Es la puerta que lanza, usada del lado que la necesita. La búsqueda del
 * backoffice no pasa por aquí: pregunta con `DocumentNumber::tryFromInput()`.
 */
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
