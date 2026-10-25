<?php

declare(strict_types=1);

use App\Domain\Settings\SettingKey;
use App\Domain\Settings\UnknownSettingException;

it('nombra la clave que no existe', function (): void {
    expect(UnknownSettingException::named('gracia')->getMessage())->toContain('«gracia»');
});

it('enumera las claves que sí existen', function (): void {
    $message = UnknownSettingException::named('gracia')->getMessage();

    foreach (SettingKey::cases() as $key) {
        expect($message)->toContain($key->value);
    }
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(UnknownSettingException::named('gracia'))->toBeInstanceOf(RuntimeException::class);
});
