<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentMethod;
use App\Rules\ValidPaymentIdentifier;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/**
 * El formato del `identifier` de cada método (`REQ-ORG-007` RB-2, SP-5).
 *
 * **Un caso válido y uno inválido por cada uno de los seis**, que es lo que
 * pide el gate de validación del requisito. No es una cuota de cobertura: lo
 * que se está comprobando es que un dato de pago mal escrito **rebota aquí** y
 * no tres pasos más allá, en el banco de un cliente que cree que ya pagó su
 * cuota.
 */

/**
 * Devuelve el mensaje con el que falló la regla, o `null` si pasó.
 *
 * El `$fail` devuelve un `PotentiallyTranslatedString` y no `void` porque es
 * la firma con la que el validador lo llama: una regla puede encadenar
 * `->translate()` sobre lo que devuelve, y un doble que no lo respetara pasaría
 * el test contra un contrato que no es el real. El traductor va suelto —estos
 * son tests de unidad y no arrancan la aplicación— y no traduce nada: los
 * mensajes de esta regla son literales.
 */
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
    // Pago móvil · un móvil venezolano. El inválido es el caso literal de SP-5:
    // un teléfono que no es venezolano.
    'pago móvil' => [PaymentMethod::PagoMovil, '0414-1234567', '+1 305 555 0199'],

    // Transferencia · los veinte dígitos de una cuenta nacional. Cuatro no son
    // una cuenta a medio escribir: son otra cosa.
    'transferencia' => [PaymentMethod::Transferencia, '0134-0123-45-0123456789', '1234'],

    // Zelle · un correo, que es lo que declara RB-2.
    'zelle' => [PaymentMethod::Zelle, 'pagos@eltornillo.com', '0414-1234567'],

    // Binance Pay · el Pay ID numérico o el correo de la cuenta.
    'binance pay' => [PaymentMethod::BinancePay, '384756291', 'mi-wallet'],

    // Efectivo · una dirección a la que alguien pueda presentarse. «Aquí» no lo
    // es, y es el único formato que se comprueba por longitud porque es el
    // único que no describe un instrumento.
    'efectivo en dólares' => [PaymentMethod::EfectivoUsd, 'Av. Bolívar, local 4, Valencia', 'aquí'],
    'efectivo en bolívares' => [PaymentMethod::EfectivoVes, 'Calle 12 con carrera 3, Barquisimeto', 'ahí'],
]);

it('acepta el identificador correcto de cada método', function (PaymentMethod $method, string $valid): void {
    expect(reject($method, $valid))->toBeNull();
})->with('identificadores');

it('rechaza el identificador que no tiene la forma de su método', function (PaymentMethod $method, string $_valid, string $invalid): void {
    expect(reject($method, $invalid))->not->toBeNull();
})->with('identificadores');

/**
 * Y el mensaje dice **qué se esperaba**, no que lo escrito esté mal: quien
 * rellena esto está copiando de su libreta del banco y lo que necesita saber es
 * el formato. Un «no es válido» lo deja probando variantes.
 */
it('explica el formato que esperaba en vez de decir que está mal', function (): void {
    expect(reject(PaymentMethod::PagoMovil, '123'))->toContain('0414-1234567')
        ->and(reject(PaymentMethod::Transferencia, '123'))->toContain('20 dígitos')
        ->and(reject(PaymentMethod::Zelle, 'no-es-un-correo'))->toContain('correo');
});

/**
 * Las tres grafías con las que se escribe el mismo teléfono valen las tres.
 *
 * Rechazar un guion sería rechazar un dato correcto por cómo se escribió, y
 * quien lo sufre es quien lo copia de su propia libreta.
 */
it('acepta el mismo móvil con guiones, sin ellos y con prefijo de país', function (string $written): void {
    expect(reject(PaymentMethod::PagoMovil, $written))->toBeNull();
})->with(['0414-1234567', '04141234567', '+58 414 1234567', '0424 1234567']);

/**
 * Lo que no vale es un número que no sale de una operadora venezolana, aunque
 * tenga la longitud correcta: `0314` no es un prefijo móvil.
 */
it('rechaza un número con longitud correcta y prefijo que no es de móvil', function (): void {
    expect(reject(PaymentMethod::PagoMovil, '0314-1234567'))->not->toBeNull();
});

/**
 * Binance Pay admite las dos cosas que Binance acepta como destino: el Pay ID y
 * el correo de la cuenta.
 */
it('acepta el Pay ID y el correo en Binance Pay', function (): void {
    expect(reject(PaymentMethod::BinancePay, '384756291'))->toBeNull()
        ->and(reject(PaymentMethod::BinancePay, 'pagos@eltornillo.com'))->toBeNull();
});

/**
 * Un identificador vacío o de solo espacios falla con el mismo mensaje que uno
 * mal escrito. No sobra por tener `required` delante: la regla se puede usar
 * desde un comando o un seeder, donde no hay `FormRequest` que la preceda.
 */
it('rechaza un identificador vacío', function (): void {
    expect(reject(PaymentMethod::Transferencia, '   '))->not->toBeNull();
});
