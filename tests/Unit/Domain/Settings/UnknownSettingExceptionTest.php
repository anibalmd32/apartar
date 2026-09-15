<?php

declare(strict_types=1);

use App\Domain\Settings\SettingKey;
use App\Domain\Settings\UnknownSettingException;

/**
 * La mitad **ruidosa** del par de RB-2.
 *
 * La silenciosa —`tryFromKey()`— es la que exige el requisito para lo que llega
 * de fuera. Esta es para cuando el nombre de la clave lo escribe nuestro propio
 * código: ahí un valor desconocido es una errata, y leer un default en lugar
 * del ajuste del negocio no lo notaría nadie hasta ver un vencimiento calculado
 * con una gracia que ese negocio nunca configuró.
 */
it('nombra la clave que no existe', function (): void {
    expect(UnknownSettingException::named('gracia')->getMessage())->toContain('«gracia»');
});

/**
 * Y enumera las que sí, porque quien lee este mensaje casi siempre escribió
 * mal una que existe.
 */
it('enumera las claves que sí existen', function (): void {
    $message = UnknownSettingException::named('gracia')->getMessage();

    foreach (SettingKey::cases() as $key) {
        expect($message)->toContain($key->value);
    }
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(UnknownSettingException::named('gracia'))->toBeInstanceOf(RuntimeException::class);
});
