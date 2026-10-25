<?php

declare(strict_types=1);

use App\Domain\Tenancy\AdminDisabledException;

it('dice qué pasa y a quién preguntar', function (): void {
    expect(AdminDisabledException::MESSAGE)
        ->toContain('desactivado')
        ->toContain('dueño');
});

it('no revela quién ni cuándo', function (): void {
    expect(AdminDisabledException::MESSAGE)
        ->not->toContain('desactivó')
        ->not->toContain('fecha');
});

it('acompaña el correo del intento', function (): void {
    expect(AdminDisabledException::forEmail('ana@ejemplo.test')->getMessage())
        ->toContain('ana@ejemplo.test')
        ->toContain(AdminDisabledException::MESSAGE);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(AdminDisabledException::forEmail('ana@ejemplo.test'))->toBeInstanceOf(RuntimeException::class);
});
