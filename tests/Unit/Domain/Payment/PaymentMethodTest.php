<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;

it('declara los seis métodos de los contratos compartidos', function (): void {
    $values = array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases());

    expect($values)->toBe([
        'pago_movil',
        'transferencia',
        'zelle',
        'binance_pay',
        'efectivo_usd',
        'efectivo_ves',
    ]);
});

it('dice qué contiene el identifier de cada método', function (): void {
    expect(PaymentMethod::PagoMovil->identifierLabel())->toBe('Teléfono')
        ->and(PaymentMethod::Transferencia->identifierLabel())->toBe('Número de cuenta')
        ->and(PaymentMethod::Zelle->identifierLabel())->toBe('Correo de Zelle')
        ->and(PaymentMethod::BinancePay->identifierLabel())->toBe('Pay ID o correo de Binance')
        ->and(PaymentMethod::EfectivoUsd->identifierLabel())->toBe('Dirección de entrega')
        ->and(PaymentMethod::EfectivoVes->identifierLabel())->toBe('Dirección de entrega');
});

it('nombra los seis métodos para una persona', function (): void {
    foreach (PaymentMethod::cases() as $method) {
        expect($method->label())->not->toBe('')
            ->and($method->label())->not->toBe($method->value)
            ->and($method->identifierLabel())->not->toBe('');
    }
});

it('distingue el efectivo del resto', function (): void {
    expect(PaymentMethod::EfectivoUsd->isCash())->toBeTrue()
        ->and(PaymentMethod::EfectivoVes->isCash())->toBeTrue()
        ->and(PaymentMethod::PagoMovil->isCash())->toBeFalse()
        ->and(PaymentMethod::Transferencia->isCash())->toBeFalse()
        ->and(PaymentMethod::Zelle->isCash())->toBeFalse()
        ->and(PaymentMethod::BinancePay->isCash())->toBeFalse();
});

it('exige referencia a todo lo que no es efectivo', function (PaymentMethod $method): void {
    expect($method->requiresReference())->toBe(! $method->isCash());
})->with(PaymentMethod::cases());

it('sugiere la moneda en que suele llegar cada método', function (): void {
    $currencies = [];

    foreach (PaymentMethod::cases() as $method) {
        $currencies[$method->value] = $method->defaultCurrency();
    }

    expect($currencies)->toBe([
        'pago_movil' => Currency::VES,
        'transferencia' => Currency::VES,
        'zelle' => Currency::USD,
        'binance_pay' => Currency::USD,
        'efectivo_usd' => Currency::USD,
        'efectivo_ves' => Currency::VES,
    ]);
});
