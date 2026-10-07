<?php

declare(strict_types=1);

namespace App\Rules;

use App\Actions\Payment\SubmitReceiptAction;
use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\ReceiptFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * El comprobante no se había reportado ya **en esta organización**
 * (`REQ-PAY-003`). Se aplica sobre el atributo `file`.
 *
 * Es la primera de las dos barreras: la que responde con un `422` antes de
 * guardar nada (RB-1). La segunda es `SubmitReceiptAction`, que hace **la
 * misma pregunta** —por eso esta regla se la hace a ella, con
 * `duplicateOf()`, en vez de escribir su propia consulta— y, detrás, los
 * únicos de la tabla para la carrera (SP-5).
 *
 * ## Los dos criterios, acotados por organización
 *
 *  - (a) El `sha256` del archivo **tal como se subió** (RB-7), el mismo que
 *    calcula la acción (`ReceiptFile::fingerprint()`).
 *  - (b) La pareja (`bank`, `reference`), solo si hay referencia: en efectivo
 *    no hay nada que comparar (RB-3).
 *
 * Los dos cuentan también los **rechazados** (RB-5). Y los dos miran solo la
 * organización fijada, por el global scope: la misma referencia en otro
 * negocio se acepta (RB-4), así que el tenant **tiene que estar fijado** al
 * validar —en el portal lo fija `ResolvePortalTenant`—, y sin él la consulta
 * lanza en vez de mirar en todas.
 *
 * El mensaje es el de RB-6 y nada más: ni de quién, ni cuándo.
 */
final class UniqueReceiptFingerprint implements ValidationRule
{
    public function __construct(
        private readonly ?string $bank,
        private readonly ?string $reference,
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $action = app(SubmitReceiptAction::class);

        $criterion = match (true) {
            // Sobre el archivo: su huella, y la pareja si se le dio una.
            $value instanceof UploadedFile => $action->duplicateOf(ReceiptFile::fingerprint($value), $this->bank, $this->reference),
            // Sobre la referencia misma (`T-059`): solo la pareja, para que el
            // formulario devuelva ese duplicado en su campo y no en el archivo.
            is_string($value) => $action->referenceTaken($this->bank, trim($value)) ? DuplicateCriterion::BankReference : null,
            // Que sea un archivo o un texto lo dicen `file` y `string`, como
            // en `RealMimeType`: dos mensajes para un fallo son dos problemas.
            default => null,
        };

        if ($criterion !== null) {
            $fail(DuplicateReceiptException::MESSAGE);
        }
    }
}
