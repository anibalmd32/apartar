<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentAccountView;
use App\Domain\Payment\PaymentMethod;
use App\Models\PaymentAccount;

function viewOf(PaymentMethod $method, string $identifier, bool $isActive = true): PaymentAccountView
{
    return PaymentAccountView::of((new PaymentAccount)->forceFill([
        'method' => $method,
        'bank' => 'Banesco',
        'holder_name' => 'Carlos Rojas',
        'holder_document' => 'V-12345678',
        'identifier' => $identifier,
        'instructions' => null,
        'is_active' => $isActive,
        'position' => 2,
    ]));
}

it('resuelve el nombre del método y el de su identificador', function (): void {
    $view = viewOf(PaymentMethod::Transferencia, '01340123450123456789');

    expect($view->methodLabel)->toBe('Transferencia bancaria')
        ->and($view->identifierLabel)->toBe('Número de cuenta');
});

it('viaja con el método como valor, para el espejo de TypeScript', function (): void {
    expect(viewOf(PaymentMethod::Zelle, 'pagos@eltornillo.com')->toArray())
        ->toMatchArray([
            'method' => 'zelle',
            'methodLabel' => 'Zelle',
            'identifierLabel' => 'Correo de Zelle',
            'identifier' => 'pagos@eltornillo.com',
            'isActive' => true,
            'position' => 2,
        ]);
});

it('pinta también las cuentas desactivadas, marcadas', function (): void {
    expect(viewOf(PaymentMethod::PagoMovil, '0414-1234567', isActive: false)->toArray())
        ->toMatchArray(['isActive' => false, 'identifier' => '0414-1234567']);
});

it('lleva siempre el titular y su cédula', function (): void {
    $view = viewOf(PaymentMethod::PagoMovil, '0414-1234567');

    expect($view->holderName)->toBe('Carlos Rojas')
        ->and($view->holderDocument)->toBe('V-12345678');
});
