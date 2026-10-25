<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentMethod;
use App\Rules\ValidPaymentIdentifier;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

function reject(PaymentMethod $method, string $identifier): ?string
{
    $message = null;
    $translator = new Translator(new ArrayLoader, 'es');

    (new ValidPaymentIdentifier($method))->validate(
        'identifier',
        $identifier,
        function (string $failure) use (&$message, $translator): PotentiallyTranslatedString {
            $message = $failure;

            return new PotentiallyTranslatedString($failure, $translator);
        },
    );

    return $message;
}

dataset('identificadores', [
    'pago móvil' => [PaymentMethod::PagoMovil, '0414-1234567', '+1 305 555 0199'],

    'transferencia' => [PaymentMethod::Transferencia, '0134-0123-45-0123456789', '1234'],

    'zelle' => [PaymentMethod::Zelle, 'pagos@eltornillo.com', '0414-1234567'],

    'binance pay' => [PaymentMethod::BinancePay, '384756291', 'mi-wallet'],

    'efectivo en dólares' => [PaymentMethod::EfectivoUsd, 'Av. Bolívar, local 4, Valencia', 'aquí'],
    'efectivo en bolívares' => [PaymentMethod::EfectivoVes, 'Calle 12 con carrera 3, Barquisimeto', 'ahí'],
]);

it('acepta el identificador correcto de cada método', function (PaymentMethod $method, string $valid): void {
    expect(reject($method, $valid))->toBeNull();
})->with('identificadores');

it('rechaza el identificador que no tiene la forma de su método', function (PaymentMethod $method, string $_valid, string $invalid): void {
    expect(reject($method, $invalid))->not->toBeNull();
})->with('identificadores');

it('explica el formato que esperaba en vez de decir que está mal', function (): void {
    expect(reject(PaymentMethod::PagoMovil, '123'))->toContain('0414-1234567')
        ->and(reject(PaymentMethod::Transferencia, '123'))->toContain('20 dígitos')
        ->and(reject(PaymentMethod::Zelle, 'no-es-un-correo'))->toContain('correo');
});

it('acepta el mismo móvil con guiones, sin ellos y con prefijo de país', function (string $written): void {
    expect(reject(PaymentMethod::PagoMovil, $written))->toBeNull();
})->with(['0414-1234567', '04141234567', '+58 414 1234567', '0424 1234567']);

it('rechaza un número con longitud correcta y prefijo que no es de móvil', function (): void {
    expect(reject(PaymentMethod::PagoMovil, '0314-1234567'))->not->toBeNull();
});

it('acepta el Pay ID y el correo en Binance Pay', function (): void {
    expect(reject(PaymentMethod::BinancePay, '384756291'))->toBeNull()
        ->and(reject(PaymentMethod::BinancePay, 'pagos@eltornillo.com'))->toBeNull();
});

it('rechaza un identificador vacío', function (): void {
    expect(reject(PaymentMethod::Transferencia, '   '))->not->toBeNull();
});
