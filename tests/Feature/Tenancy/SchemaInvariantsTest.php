<?php

declare(strict_types=1);

use App\Models\Organization;
use Tests\Concerns\CreatesNeighbourOrganization;

/**
 * Invariantes del esquema que sostienen el gate de aislamiento
 * (`REQ-QAX-008`).
 *
 * Fue el primer archivo de la suite `Tenancy`, escrito para que su job de CI no
 * pasara en verde sobre un directorio vacío. `T-007` completó el resto:
 * `tenancy:audit` recorre el código con las cinco comprobaciones de RB-4 y
 * `CrossTenantAccessTest` deriva la fuga cruzada del router.
 *
 * Lo que queda aquí es lo que no cabe en ninguno de los dos: reglas del
 * esquema que se comprueban leyendo las migraciones, y la precondición de todo
 * test de fuga —que `CreatesNeighbourOrganization` siembre una organización
 * vecina de verdad (`REQ-QAX-003` RB-10)—.
 */

/**
 * `REQ-FX-001` RB-1 y `REQ-QAX-001` RB-8: todo importe es `BIGINT` de centavos.
 * Un `FLOAT` en el esquema no da un error, da un balance equivocado de un
 * céntimo que nadie detecta hasta que un cliente reclama.
 */
it('no declara ninguna columna FLOAT o DOUBLE en las migraciones', function (): void {
    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $migration) {
        $contents = (string) file_get_contents($migration);

        if (preg_match('/->(float|double|unsignedFloat|unsignedDouble)\s*\(/i', $contents) === 1) {
            $offenders[] = basename($migration);
        }
    }

    expect($offenders)->toBe([], 'Migraciones con coma flotante: '.implode(', ', $offenders));
});

/**
 * `REQ-QAX-003` RB-10: el helper está en el caso base de toda la suite, no
 * suelto para que cada test decida importarlo. Su comportamiento se ejercita
 * en `NeighbourOrganizationTest`; lo que se comprueba aquí es que sigue
 * aplicado donde tiene que estarlo.
 */
it('aplica el helper de la organización vecina en el caso base', function (): void {
    expect(class_uses_recursive(Tests\TestCase::class))
        ->toContain(CreatesNeighbourOrganization::class);
});

/**
 * `organizations.slug` es `UNIQUE` global: es una de las tres excepciones
 * declaradas a la regla de unicidad por organización, y aquí es trivial porque
 * `organizations` no es una tabla del tenant. `T-005` la añade a
 * `GlobalUniqueExceptions` con su motivo.
 */
it('mantiene el slug de la organización único en toda la plataforma', function (): void {
    $organization = Organization::factory()->create();

    expect(fn () => Organization::factory()->create(['slug' => $organization->slug]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
