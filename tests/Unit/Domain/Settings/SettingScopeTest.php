<?php

declare(strict_types=1);

use App\Domain\Settings\SettingScope;

/**
 * El alcance de un cambio de ajuste (`REQ-ORG-006` RB-4).
 *
 * RB-4 exige que **cada clave declare su alcance en la UI**, y este enum es esa
 * frase. Va aquí y no en el `.tsx` por el mismo motivo por el que el reparto de
 * roles dejó de estar en dos sitios: una frase escrita en la pantalla acabaría
 * diciendo algo distinto de lo que hace el cálculo.
 */
it('declara los tres alcances posibles', function (): void {
    $values = array_map(fn (SettingScope $scope): string => $scope->value, SettingScope::cases());

    expect($values)->toBe(['immediate', 'daily', 'future_only']);
});

/**
 * La frase de `future_only` es la que evita la conversación más cara: quien
 * cambia la ventana del abono inicial tiene que saber que **no mueve** los
 * vencimientos que ya se le comunicaron al cliente.
 */
it('avisa de que lo ya fijado no se mueve', function (): void {
    expect(SettingScope::FutureOnly->description())
        ->toContain('a partir de ahora')
        ->toContain('no mueve fechas ya fijadas');
});

it('distingue lo inmediato de lo que espera al recálculo diario', function (): void {
    expect(SettingScope::Immediate->description())->toContain('siguiente operación');
    expect(SettingScope::Daily->description())->toContain('mañana');
});

/**
 * Ninguna frase vacía: un alcance sin explicar es un campo del que nadie sabe
 * qué esperar, y entonces la regla vale lo mismo que no tenerla.
 */
it('explica todos sus casos', function (SettingScope $scope): void {
    expect($scope->description())->not->toBe('');
})->with(SettingScope::cases());
