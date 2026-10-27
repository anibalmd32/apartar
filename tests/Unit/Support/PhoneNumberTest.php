<?php

declare(strict_types=1);

use App\Support\InvalidPhoneNumberException;
use App\Support\PhoneNumber;

dataset('escrituras de +584121234567', [
    'nacional con cero' => ['04121234567'],
    'nacional sin cero' => ['4121234567'],
    'internacional' => ['+584121234567'],
    'con guion y puntos' => ['0412-123.45.67'],
    'con paréntesis y espacios' => ['(0412) 123 4567'],
    'internacional con separadores' => ['+58 412-123-4567'],
]);

it('reduce cada escritura del teléfono al mismo E.164', function (string $raw): void {
    expect(PhoneNumber::fromInput($raw)->value)->toBe('+584121234567');
})->with('escrituras de +584121234567');

it('admite también el 58 sin más y el cero detrás del +58', function (string $raw): void {
    expect(PhoneNumber::fromInput($raw)->value)->toBe('+584141234567');
})->with(['584141234567', '+58 0414 1234567']);

it('reconoce los seis prefijos móviles', function (string $prefix): void {
    $phone = PhoneNumber::fromInput('0'.$prefix.'1234567');

    expect($phone->isMobile())->toBeTrue()
        ->and($phone->isRecognised())->toBeTrue()
        ->and($phone->areaCode())->toBe($prefix);
})->with(['412', '414', '416', '422', '424', '426']);

it('ya no trata el 0415 como móvil', function (): void {
    expect(PhoneNumber::fromInput('04151234567')->isMobile())->toBeFalse();
});

it('acepta un fijo con código de área válido', function (string $raw, string $e164): void {
    $phone = PhoneNumber::fromInput($raw);

    expect($phone->value)->toBe($e164)
        ->and($phone->isMobile())->toBeFalse();
})->with([
    'Caracas' => ['0212-5551234', '+582125551234'],
    'Valencia' => ['0241 555 1234', '+582415551234'],
    'Porlamar' => ['02955551234', '+582955551234'],
]);

it('rechaza un código sin forma de teléfono venezolano', function (string $raw, string $code): void {
    expect(fn () => PhoneNumber::fromInput($raw))->toThrow(
        InvalidPhoneNumberException::class,
        "El código {$code} no es de un teléfono de Venezuela: los móviles empiezan por 04 y los fijos por 02.",
    );
})->with([
    ['05001234567', '0500'],
    ['08001234567', '0800'],
    ['01231234567', '0123'],
    ['09001234567', '0900'],
]);

it('acepta un prefijo fuera del censo y dice que no lo reconoce', function (string $raw, bool $mobile): void {
    $phone = PhoneNumber::fromInput($raw);

    expect($phone->isRecognised())->toBeFalse()
        ->and($phone->isMobile())->toBe($mobile);
})->with([
    'zona sin asignar' => ['02501234567', false],
    'móvil fuera del censo' => ['04131234567', false],
]);

it('reconoce un fijo del censo', function (): void {
    expect(PhoneNumber::fromInput('0212-5551234')->isRecognised())->toBeTrue();
});

it('rechaza un teléfono de otro país', function (string $raw): void {
    expect(fn () => PhoneNumber::fromInput($raw))->toThrow(
        InvalidPhoneNumberException::class,
        'Por ahora solo aceptamos teléfonos de Venezuela',
    );
})->with(['+1 305 555 0199', '+57 300 1234567', '+34 612 345 678']);

it('rechaza la longitud equivocada con la forma entera en el mensaje', function (string $raw): void {
    expect(fn () => PhoneNumber::fromInput($raw))->toThrow(
        InvalidPhoneNumberException::class,
        'Un teléfono venezolano tiene 11 dígitos contando el 0 del principio, como 0414-1234567.',
    );
})->with(['0414123456', '041412345678', '414123', '+58 414 12345', '1234567']);

it('rechaza lo que no son dígitos', function (string $raw): void {
    expect(fn () => PhoneNumber::fromInput($raw))->toThrow(
        InvalidPhoneNumberException::class,
        'Escribe solo los números del teléfono',
    );
})->with(['0414-ABC-4567', 'llámame', '+', '0414/1234567']);

it('rechaza el teléfono vacío', function (): void {
    expect(fn () => PhoneNumber::fromInput('  '))->toThrow(InvalidPhoneNumberException::class, 'Escribe tu número de teléfono');
});

it('lanza en el constructor si lo que recibe no es E.164', function (string $value): void {
    expect(fn () => new PhoneNumber($value))->toThrow(InvalidPhoneNumberException::class);
})->with(['04141234567', '+5804141234567', '+14155550199', '+585001234567']);

it('enseña la grafía nacional', function (): void {
    expect(PhoneNumber::fromInput('+584141234567')->formatted())->toBe('0414-1234567')
        ->and(PhoneNumber::fromInput('02125551234')->formatted())->toBe('0212-5551234');
});

it('compara por el E.164', function (): void {
    expect(PhoneNumber::fromInput('0414-1234567')->equals(PhoneNumber::fromInput('+584141234567')))->toBeTrue()
        ->and(PhoneNumber::fromInput('0414-1234567')->equals(PhoneNumber::fromInput('0424-1234567')))->toBeFalse();
});

it('devuelve null en la puerta de preguntar cuando la otra lanzaría', function (mixed $raw): void {
    expect(PhoneNumber::tryFromInput($raw))->toBeNull();
})->with(['0414', '+1 305 555 0199', 'María', '', null, 4141234567]);

it('devuelve el teléfono en la puerta de preguntar cuando lo es', function (): void {
    expect(PhoneNumber::tryFromInput('0414-1234567')?->value)->toBe('+584141234567');
});
