<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\PaymentMethod;
use App\Models\PaymentAccount;

/**
 * El aviso de que un borrado se convirtió en una desactivación
 * (`REQ-ORG-007` RB-6, SP-3).
 *
 * Es una excepción que **no señala un fallo**: la operación se completó, solo
 * que de otra forma que la pedida. Lo que se fija aquí es que el mensaje sirva
 * para lo único que existe —que el dueño entienda qué ha quedado— y que la
 * cuenta viaje dentro, porque quien lo recoge tiene que poder seguir operando
 * sobre ella.
 */
function inUseAccount(): PaymentAccount
{
    return (new PaymentAccount)->forceFill([
        'method' => PaymentMethod::PagoMovil,
        'identifier' => '0414-1234567',
    ]);
}

/**
 * El mensaje dice **las tres cosas que hacen falta**: que no se eliminó, qué
 * queda en su lugar y por qué. Sin la tercera, quien lo lee cree que el sistema
 * le desobedeció.
 */
it('explica que la cuenta quedó desactivada y por qué', function (): void {
    $message = PaymentAccountInUseException::deactivatedInstead(inUseAccount())->getMessage();

    expect($message)
        ->toContain('no se ha eliminado')
        ->toContain('desactivada')
        ->toContain('comprobantes');
});

/**
 * Y la nombra por su método y su identificador, no por su identificador a
 * secas: quien tiene varias cuentas necesita saber **cuál** acaba de
 * desactivar.
 */
it('nombra la cuenta por su método y su dato de pago', function (): void {
    expect(PaymentAccountInUseException::deactivatedInstead(inUseAccount())->getMessage())
        ->toContain('Pago móvil')
        ->toContain('0414-1234567');
});

/**
 * La cuenta viaja dentro: el controlador la usa para volver a preguntar por los
 * avisos de la pantalla sin tener que volver a buscarla.
 */
it('lleva dentro la cuenta afectada', function (): void {
    $account = inUseAccount();

    expect(PaymentAccountInUseException::deactivatedInstead($account)->account)->toBe($account);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(PaymentAccountInUseException::deactivatedInstead(inUseAccount()))->toBeInstanceOf(RuntimeException::class);
});
