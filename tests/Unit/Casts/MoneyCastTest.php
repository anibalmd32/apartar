<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Models\Plan;

function moneyCast(): MoneyCast
{
    return new MoneyCast;
}

it('devuelve null y no Money(0) para una columna vacía', function (): void {
    $value = moneyCast()->get(new Plan, 'compare_at_price_cents', null, []);

    expect($value)->toBeNull();
    expect($value)->not->toBeInstanceOf(Money::class);
});

it('deja null al persistir una columna opcional vacía', function (): void {
    expect(moneyCast()->set(new Plan, 'compare_at_price_cents', null, []))->toBeNull();
});

it('hidrata la cadena del driver como importe entero en dólares', function (mixed $stored): void {
    $money = moneyCast()->get(new Plan, 'price_cents', $stored, []);

    expect($money)->toBeInstanceOf(Money::class);
    expect($money?->cents)->toBe(2_000);
    expect($money?->cents)->toBeInt();
    expect($money?->currency)->toBe(Currency::USD);
})->with([
    'como cadena, que es lo que devuelve MySQL' => ['2000'],
    'como entero' => [2_000],
]);

it('hidrata el negativo, que es un crédito', function (): void {
    expect(moneyCast()->get(new Plan, 'price_cents', '-500', [])?->cents)->toBe(-500);
});

it('persiste un Money como sus céntimos', function (): void {
    expect(moneyCast()->set(new Plan, 'price_cents', new Money(2_000), []))->toBe(2_000);
});

it('admite también el entero pelado que escriben las factories', function (): void {
    expect(moneyCast()->set(new Plan, 'price_cents', 2_000, []))->toBe(2_000);
});

it('rechaza un float en los dos sentidos', function (): void {
    expect(fn (): ?int => moneyCast()->set(new Plan, 'price_cents', 19.99, []))
        ->toThrow(InvalidArgumentException::class, 'Money o un entero de céntimos');

    expect(fn (): ?Money => moneyCast()->get(new Plan, 'price_cents', 19.99, []))
        ->toThrow(InvalidArgumentException::class, 'float');
});

it('rechaza una cadena decimal y manda a convertirla antes', function (): void {
    expect(fn (): ?int => moneyCast()->set(new Plan, 'price_cents', '19.99', []))
        ->toThrow(InvalidArgumentException::class, 'Money::fromDecimalString()');
});

it('lanza al intentar persistir un importe que no es en USD', function (): void {
    expect(fn (): ?int => moneyCast()->set(new Plan, 'price_cents', new Money(85_000, Currency::VES), []))
        ->toThrow(CurrencyMismatchException::class, 'plans.price_cents');
});

it('no admite configurar la moneda de la columna', function (): void {
    expect((new ReflectionClass(MoneyCast::class))->getConstructor())->toBeNull();
});
