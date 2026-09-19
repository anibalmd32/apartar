<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;

/**
 * Las tres fuentes de `REQ-FX-003` RB-1, y el censo único que las declara.
 */
it('declara exactamente las tres fuentes de RB-1', function (): void {
    $values = array_column(RateSource::cases(), 'value');

    expect($values)->toBe(['bcv', 'parallel', 'manual']);
});

/**
 * La unión de `resources/js/types/exchange.ts` está escrita con estas cadenas,
 * y el enum viaja a la base de datos con ellas: cambiar una es una migración,
 * no un renombrado.
 */
it('usa como valor la cadena que se persiste y que espera TypeScript', function (RateSource $source, string $value): void {
    expect($source->value)->toBe($value);
})->with([
    [RateSource::Bcv, 'bcv'],
    [RateSource::Parallel, 'parallel'],
    [RateSource::Manual, 'manual'],
]);

/**
 * **El censo es uno solo.** `SettingKey` tenía las tres cadenas escritas a
 * mano hasta `T-019`; ahora valida contra el enum. Con dos listas, la cuarta
 * fuente deja corta a una de ellas y el fallo no aparece donde se escribió:
 * aparece como un `RateSource::from()` que lanza sobre un ajuste que la
 * validación había dado por bueno.
 */
it('valida el ajuste de fuente contra el enum y no contra una copia', function (): void {
    $rules = SettingKey::ExchangeRateSource->validationRules();

    expect($rules)->toContain('in:'.implode(',', array_column(RateSource::cases(), 'value')));
});

it('admite las tres fuentes como valor del ajuste', function (RateSource $source): void {
    $rules = SettingKey::ExchangeRateSource->validationRules();

    $in = array_values(array_filter(
        $rules,
        fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'in:'),
    ));

    expect($in[0])->toContain($source->value);
})->with([RateSource::Bcv, RateSource::Parallel, RateSource::Manual]);

/**
 * El default de `organization_settings.exchange_rate_source` es una fuente
 * real, y no una cadena que solo existe en ese `match`.
 */
it('siembra como fuente por defecto una que el enum conoce', function (): void {
    $default = SettingKey::ExchangeRateSource->defaultValue();

    expect($default)->toBeString();
    expect(RateSource::tryFrom((string) $default))->toBe(RateSource::Bcv);
});
