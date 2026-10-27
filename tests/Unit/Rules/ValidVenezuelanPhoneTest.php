<?php

declare(strict_types=1);

use App\Rules\ValidVenezuelanPhone;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

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

it('deja pasar un celular bien escrito', function (string $value): void {
    expect(phoneFailure($value))->toBeNull();
})->with(['0414-1234567', '+58 412 123 4567', '04221234567']);

it('falla con un teléfono de otro país', function (): void {
    expect(phoneFailure('+1 305 555 0199'))->toStartWith('Por ahora solo aceptamos teléfonos de Venezuela');
});

it('falla con un código que no tiene forma de teléfono venezolano', function (): void {
    expect(phoneFailure('0500-1234567'))->toStartWith('El código 0500 no es de un teléfono de Venezuela');
});

it('rechaza un fijo o un prefijo que no es de celular, y pide elegir uno de la lista', function (string $value): void {
    expect(phoneFailure($value))->toBe(ValidVenezuelanPhone::MOBILE_ONLY);
})->with(['0212-5551234', '0250-1234567', '0413-1234567', '0415-1234567']);

it('falla con un valor que no es texto', function (mixed $value): void {
    expect(phoneFailure($value))->toBe('Escribe tu número de teléfono, como 0414-1234567.');
})->with([null, 4141234567]);
