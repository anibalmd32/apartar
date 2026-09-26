<?php

declare(strict_types=1);

use App\Rules\ValidVenezuelanPhone;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/**
 * La regla del campo `phone` (`REQ-IDN-007` RB-4, SP-5, SP-7;
 * `REQ-IDN-001` SP-2).
 */
function phoneFailure(mixed $value): ?string
{
    $message = null;
    $translator = new Translator(new ArrayLoader, 'es');

    (new ValidVenezuelanPhone)->validate(
        'phone',
        $value,
        function (string $failure) use (&$message, $translator): PotentiallyTranslatedString {
            $message = $failure;

            return new PotentiallyTranslatedString($failure, $translator);
        },
    );

    return $message;
}

it('deja pasar un móvil y un fijo bien escritos', function (string $value): void {
    expect(phoneFailure($value))->toBeNull();
})->with(['0414-1234567', '+58 412 123 4567', '0212-5551234']);

it('falla con un teléfono de otro país (SP-5)', function (): void {
    expect(phoneFailure('+1 305 555 0199'))->toStartWith('Por ahora solo aceptamos teléfonos de Venezuela');
});

it('falla con un código que no tiene forma de teléfono venezolano', function (): void {
    expect(phoneFailure('0500-1234567'))->toStartWith('El código 0500 no es de un teléfono de Venezuela');
});

/**
 * Desde `T-045` se valida la forma, no el censo (`REQ-IDN-007`): un prefijo
 * fuera del censo que empieza por `2` o `4` pasa, y el aviso que no bloquea
 * es del formulario.
 */
it('deja pasar un prefijo fuera del censo con forma de teléfono venezolano', function (string $value): void {
    expect(phoneFailure($value))->toBeNull();
})->with(['0250-1234567', '0413-1234567']);

/**
 * `REQ-IDN-001` RB-2: el teléfono es obligatorio, y un campo ausente que
 * llegue hasta la regla falla como uno vacío.
 */
it('falla con un valor que no es texto', function (mixed $value): void {
    expect(phoneFailure($value))->toBe('Escribe tu número de teléfono, como 0414-1234567.');
})->with([null, 4141234567]);
