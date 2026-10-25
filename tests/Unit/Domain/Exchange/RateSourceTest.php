<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;

it('declara exactamente las tres fuentes', function (): void {
    $values = array_column(RateSource::cases(), 'value');

    expect($values)->toBe(['bcv', 'parallel', 'manual']);
});

it('usa como valor la cadena que se persiste y que espera TypeScript', function (RateSource $source, string $value): void {
    expect($source->value)->toBe($value);
})->with([
    [RateSource::Bcv, 'bcv'],
    [RateSource::Parallel, 'parallel'],
    [RateSource::Manual, 'manual'],
]);

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

it('siembra como fuente por defecto una que el enum conoce', function (): void {
    $default = SettingKey::ExchangeRateSource->defaultValue();

    expect($default)->toBeString();
    expect(RateSource::tryFrom((string) $default))->toBe(RateSource::Bcv);
});
