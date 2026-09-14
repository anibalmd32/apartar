<?php

declare(strict_types=1);

use App\Domain\Tenancy\AdminDisabledException;

/**
 * `REQ-BOF-001` RB-4 y `REQ-ORG-005` RB-1.
 *
 * Lo que se fija aquí es lo que el mensaje **no** dice: ni quién desactivó a
 * la persona ni cuándo. Eso es una conversación con su `owner`, no un dato que
 * deba leer cualquiera que teclee un correo ajeno en el formulario de acceso.
 */
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

/**
 * El correo va en la excepción —para el log y para quien depure— y no en el
 * mensaje que se le enseña a quien la provoca.
 */
it('acompaña el correo del intento', function (): void {
    expect(AdminDisabledException::forEmail('ana@ejemplo.test')->getMessage())
        ->toContain('ana@ejemplo.test')
        ->toContain(AdminDisabledException::MESSAGE);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(AdminDisabledException::forEmail('ana@ejemplo.test'))->toBeInstanceOf(RuntimeException::class);
});
