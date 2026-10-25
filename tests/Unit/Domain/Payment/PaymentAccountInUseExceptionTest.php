<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\PaymentMethod;
use App\Models\PaymentAccount;

function inUseAccount(): PaymentAccount
{
    return (new PaymentAccount)->forceFill([
        'method' => PaymentMethod::PagoMovil,
        'identifier' => '0414-1234567',
    ]);
}

it('explica que la cuenta quedó desactivada y por qué', function (): void {
    $message = PaymentAccountInUseException::deactivatedInstead(inUseAccount())->getMessage();

    expect($message)
        ->toContain('no se ha eliminado')
        ->toContain('desactivada')
        ->toContain('comprobantes');
});

it('nombra la cuenta por su método y su dato de pago', function (): void {
    expect(PaymentAccountInUseException::deactivatedInstead(inUseAccount())->getMessage())
        ->toContain('Pago móvil')
        ->toContain('0414-1234567');
});

it('lleva dentro la cuenta afectada', function (): void {
    $account = inUseAccount();

    expect(PaymentAccountInUseException::deactivatedInstead($account)->account)->toBe($account);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(PaymentAccountInUseException::deactivatedInstead(inUseAccount()))->toBeInstanceOf(RuntimeException::class);
});
