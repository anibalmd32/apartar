<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyDisplay;
use App\Domain\Settings\SettingKey;

/**
 * Cuál de las dos monedas se muestra primero (`REQ-FX-004`).
 */
it('declara exactamente los dos modos del contrato', function (): void {
    expect(CurrencyDisplay::values())->toBe(['usd_primary', 'ves_primary']);
});

/**
 * RB-5 · el arranque de quien no ha elegido nada y no tiene negocio detrás: el
 * feed, donde conviven varias organizaciones y ninguna manda.
 */
it('arranca en dólares cuando no hay negocio que mande', function (): void {
    expect(CurrencyDisplay::platformDefault())->toBe(CurrencyDisplay::UsdPrimary);
});

/**
 * **SP-3 · una cookie con basura se descarta sin error.** No es un `422` ni un
 * log: quien la lleva no puede hacer nada al respecto y la página tiene que
 * abrir igual.
 */
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

/**
 * **El censo es uno solo.** `SettingKey` tenía las dos cadenas escritas a mano
 * hasta `T-022`; ahora valida contra el enum, por lo mismo que `RateSource`
 * desde `T-019`: con dos listas, el tercer modo deja corta a una de ellas y el
 * síntoma es un `from()` que lanza sobre un ajuste ya validado.
 */
it('valida el ajuste contra el enum y no contra una copia', function (): void {
    $rules = SettingKey::CurrencyDisplayDefault->validationRules();

    expect($rules)->toContain('in:'.implode(',', CurrencyDisplay::values()));
});

it('siembra como default un modo que el enum conoce', function (): void {
    $default = SettingKey::CurrencyDisplayDefault->defaultValue();

    expect(CurrencyDisplay::tryFromValue($default))->toBe(CurrencyDisplay::UsdPrimary);
});

/**
 * El nombre de la cookie vive aquí y lo consumen `bootstrap/app.php` —para
 * excluirla del cifrado— y el hook del cliente, que es quien la escribe.
 *
 * Que la exclusión **funcione** no se comprueba leyendo el `bootstrap`: lo
 * prueba `CurrencyPagePropsTest`, que manda una cookie sin cifrar y exige que
 * el servidor la lea. Un test que busca una cadena en un archivo de
 * configuración pasa igual el día que la exclusión deje de surtir efecto.
 */
it('declara el nombre y la vida de su cookie', function (): void {
    expect(CurrencyDisplay::COOKIE)->toBe('currency_display');
    expect(CurrencyDisplay::COOKIE_DAYS)->toBe(365);
});
