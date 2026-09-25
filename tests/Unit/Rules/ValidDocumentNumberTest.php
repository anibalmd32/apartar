<?php

declare(strict_types=1);

use App\Rules\ValidDocumentNumber;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/**
 * La regla del campo `document_number` (`REQ-IDN-007`, `REQ-IDN-001` SP-1).
 *
 * Lo que se afirma es que el `422` lleva **el mensaje del VO**, que dice qué
 * arreglar: la regla no tiene reglas propias que puedan discrepar de él.
 */
function documentFailure(mixed $value): ?string
{
    $message = null;
    $translator = new Translator(new ArrayLoader, 'es');

    (new ValidDocumentNumber)->validate(
        'document_number',
        $value,
        function (string $failure) use (&$message, $translator): PotentiallyTranslatedString {
            $message = $failure;

            return new PotentiallyTranslatedString($failure, $translator);
        },
    );

    return $message;
}

it('deja pasar una cédula y un RIF bien escritos', function (string $value): void {
    expect(documentFailure($value))->toBeNull();
})->with(['v-12.345.678', '12345678', 'J-12345678-9', 'V-0012345']);

it('falla con el rango explícito (SP-1)', function (): void {
    expect(documentFailure('V-12.345'))->toBe('Una cédula tiene entre 6 y 9 dígitos, y aquí hay 5.');
});

it('falla con las letras que valen (SP-2)', function (): void {
    expect(documentFailure('P-123'))->toBe('Un documento empieza por V o E si es una cédula, o por J o G si es un RIF, y "P" no es ninguna de ellas.');
});

it('falla con un valor que no es texto', function (mixed $value): void {
    expect(documentFailure($value))->toBe('Escribe el número de tu cédula o RIF.');
})->with([null, 12345678, [['V12345678']]]);
