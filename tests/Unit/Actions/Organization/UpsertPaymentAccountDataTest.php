<?php

declare(strict_types=1);

use App\Actions\Organization\UpsertPaymentAccountData;
use App\Domain\Payment\PaymentMethod;

function accountData(string $identifier, ?int $position = null): UpsertPaymentAccountData
{
    return new UpsertPaymentAccountData(
        method: PaymentMethod::PagoMovil,
        bank: 'Banesco',
        holderName: 'Carlos Rojas',
        holderDocument: 'V-12345678',
        identifier: $identifier,
        instructions: null,
        position: $position,
    );
}

it('reduce el identificador a lo que lo hace único', function (): void {
    expect(accountData('0414-1234567')->comparableIdentifier())
        ->toBe(accountData('04141234567')->comparableIdentifier())
        ->and(accountData('0414 1234567')->comparableIdentifier())
        ->toBe('04141234567');
});

it('no canoniza un móvil entre sus formas con y sin prefijo de país', function (): void {
    expect(accountData('+58 414 1234567')->comparableIdentifier())->toBe('584141234567');
});

it('compara los correos sin distinguir mayúsculas', function (): void {
    expect(UpsertPaymentAccountData::comparable('Pagos@ElTornillo.com'))->toBe('pagos@eltornillo.com');
});

it('no confunde dos cuentas distintas', function (): void {
    expect(accountData('0414-1234567')->comparableIdentifier())
        ->not->toBe(accountData('0424-7654321')->comparableIdentifier());
});

it('deja la posición sin decidir cuando no se pide una', function (): void {
    expect(accountData('0414-1234567')->position)->toBeNull()
        ->and(accountData('0414-1234567', position: 3)->position)->toBe(3);
});

it('no toca el identificador que se guarda', function (): void {
    expect(accountData('0414-1234567')->identifier)->toBe('0414-1234567');
});
