<?php

declare(strict_types=1);

use App\Domain\Settings\SettingScope;

it('declara los tres alcances posibles', function (): void {
    $values = array_map(fn (SettingScope $scope): string => $scope->value, SettingScope::cases());

    expect($values)->toBe(['immediate', 'daily', 'future_only']);
});

it('avisa de que lo ya fijado no se mueve', function (): void {
    expect(SettingScope::FutureOnly->description())
        ->toContain('a partir de ahora')
        ->toContain('no mueve fechas ya fijadas');
});

it('distingue lo inmediato de lo que espera al recálculo diario', function (): void {
    expect(SettingScope::Immediate->description())->toContain('siguiente operación');
    expect(SettingScope::Daily->description())->toContain('mañana');
});

it('explica todos sus casos', function (SettingScope $scope): void {
    expect($scope->description())->not->toBe('');
})->with(SettingScope::cases());
