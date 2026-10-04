<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;

/**
 * El enum compartido de `REQ-ORG-007` y `REQ-PAY-001`: los seis casos y sus
 * tres preguntas del contrato de [22], más el nombre para una persona.
 */

/**
 * **Seis, no tres.** La línea de `T-015` del plan decía «3 métodos» y era un
 * error de redacción; manda el bloque de contratos compartidos de [22].
 */
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

/**
 * RB-2 · el `identifier` cambia de significado según el método, y esto es lo
 * que evita que la pantalla diga «identificador» seis veces.
 */
it('dice qué contiene el identifier de cada método', function (): void {
    expect(PaymentMethod::PagoMovil->identifierLabel())->toBe('Teléfono')
        ->and(PaymentMethod::Transferencia->identifierLabel())->toBe('Número de cuenta')
        ->and(PaymentMethod::Zelle->identifierLabel())->toBe('Correo de Zelle')
        ->and(PaymentMethod::BinancePay->identifierLabel())->toBe('Pay ID o correo de Binance')
        ->and(PaymentMethod::EfectivoUsd->identifierLabel())->toBe('Dirección de entrega')
        ->and(PaymentMethod::EfectivoVes->identifierLabel())->toBe('Dirección de entrega');
});

/**
 * Y ninguna etiqueta se queda vacía ni repite el nombre del caso: son las que
 * lee alguien que está a punto de mover dinero.
 */
it('nombra los seis métodos para una persona', function (): void {
    foreach (PaymentMethod::cases() as $method) {
        expect($method->label())->not->toBe('')
            ->and($method->label())->not->toBe($method->value)
            ->and($method->identifierLabel())->not->toBe('');
    }
});

/**
 * Los dos métodos de efectivo no tienen banco detrás, y eso gobierna qué se le
 * pide al formulario.
 */
it('distingue el efectivo del resto', function (): void {
    expect(PaymentMethod::EfectivoUsd->isCash())->toBeTrue()
        ->and(PaymentMethod::EfectivoVes->isCash())->toBeTrue()
        ->and(PaymentMethod::PagoMovil->isCash())->toBeFalse()
        ->and(PaymentMethod::Transferencia->isCash())->toBeFalse()
        ->and(PaymentMethod::Zelle->isCash())->toBeFalse()
        ->and(PaymentMethod::BinancePay->isCash())->toBeFalse();
});

/**
 * `REQ-PAY-001` RB-2 y `REQ-PAY-003` RB-3: todo lo que pasa por un banco o una
 * plataforma exige banco y referencia; el efectivo, no. Llegó con `T-055`,
 * que borró el test que esperaba este método.
 */
it('exige referencia a todo lo que no es efectivo', function (PaymentMethod $method): void {
    expect($method->requiresReference())->toBe(! $method->isCash());
})->with(PaymentMethod::cases());

/**
 * `REQ-PAY-001` SP-10: la moneda que el método sugiere, sin imponerla.
 */
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
