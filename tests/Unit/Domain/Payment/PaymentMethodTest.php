<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentMethod;

/**
 * El enum compartido de `REQ-ORG-007` y `REQ-PAY-001`.
 *
 * Lo que se fija aquí es sobre todo **el reparto**: los seis casos y
 * `identifierLabel()` son de esta tarea; `requiresReference()` y
 * `defaultCurrency()` llegan con la tarea de comprobantes, porque el segundo
 * devuelve `Currency` y `Currency` nace en `T-017`. El test que lo comprueba no
 * es una formalidad: sin él, alguien lo completaría con un `Currency`
 * provisional y `T-017` tendría que desmontar una segunda fuente de verdad de
 * una moneda.
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
 * **El reparto con H4, fijado.** `defaultCurrency()` devuelve `Currency`, que
 * nace en `T-017`: escribirlo aquí con una moneda provisional fabricaría la
 * segunda fuente de verdad que esa tarea tendría que desmontar.
 * `requiresReference()` llega con los comprobantes que lo usan.
 *
 * El día que `T-017` y la tarea de comprobantes los añadan, este test se cae y
 * se borra: es lo que hace que el reparto sea una decisión y no un olvido.
 */
it('deja requiresReference y defaultCurrency para la tarea de comprobantes', function (): void {
    // Por reflexión y no con `method_exists()`: el análisis estático sabe que
    // esos métodos no están y pliega la llamada a `false`, con lo que el test
    // dejaría de comprobar nada el día que alguien los añadiera.
    $enum = new ReflectionEnum(PaymentMethod::class);

    expect($enum->hasMethod('requiresReference'))
        ->toBeFalse('`requiresReference()` es de la tarea de comprobantes (`REQ-PAY-001`); si ya está, borra este test.')
        ->and($enum->hasMethod('defaultCurrency'))
        ->toBeFalse('`defaultCurrency()` devuelve `Currency`, que nace en `T-017`; si ya está, borra este test.');
});
