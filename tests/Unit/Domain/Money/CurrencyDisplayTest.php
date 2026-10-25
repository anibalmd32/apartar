<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyDisplay;
use App\Domain\Settings\SettingKey;

it('declara exactamente los dos modos del contrato', function (): void {
    expect(CurrencyDisplay::values())->toBe(['usd_primary', 'ves_primary']);
});

it('arranca en dólares cuando no hay negocio que mande', function (): void {
    expect(CurrencyDisplay::platformDefault())->toBe(CurrencyDisplay::UsdPrimary);
});

it('descarta sin error lo que no reconoce', function (mixed $value): void {
    expect(CurrencyDisplay::tryFromValue($value))->toBeNull();
})->with([
    'una cadena cualquiera' => ['bolivares-por-favor'],
    'vacía' => [''],
    'nula' => [null],
    'un número' => [42],
    'un array' => [['usd_primary']],
    'mayúsculas' => ['USD_PRIMARY'],
]);

it('reconoce los dos valores buenos', function (): void {
    expect(CurrencyDisplay::tryFromValue('usd_primary'))->toBe(CurrencyDisplay::UsdPrimary);
    expect(CurrencyDisplay::tryFromValue('ves_primary'))->toBe(CurrencyDisplay::VesPrimary);
});

it('valida el ajuste contra el enum y no contra una copia', function (): void {
    $rules = SettingKey::CurrencyDisplayDefault->validationRules();

    expect($rules)->toContain('in:'.implode(',', CurrencyDisplay::values()));
});

it('siembra como default un modo que el enum conoce', function (): void {
    $default = SettingKey::CurrencyDisplayDefault->defaultValue();

    expect(CurrencyDisplay::tryFromValue($default))->toBe(CurrencyDisplay::UsdPrimary);
});

it('declara el nombre y la vida de su cookie', function (): void {
    expect(CurrencyDisplay::COOKIE)->toBe('currency_display');
    expect(CurrencyDisplay::COOKIE_DAYS)->toBe(365);
});
