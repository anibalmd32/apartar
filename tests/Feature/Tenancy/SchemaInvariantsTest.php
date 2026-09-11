<?php

declare(strict_types=1);

use App\Models\Organization;
use Tests\Concerns\CreatesNeighbourOrganization;

/**
 * Mitad estática del gate de aislamiento (`REQ-QAX-008`).
 *
 * Este archivo es lo único que la suite `Tenancy` contiene hoy, y existe para
 * que su job de CI no pase en verde sobre un directorio vacío. **`T-007` es
 * quien la completa**: `tenancy:audit`, `CrossTenantAccessTest`,
 * `BroadcastChannelIsolationTest`, `JobTenantContextTest` y
 * `AccountBoundaryTest`, que necesitan `TenantContext` (T-005) y las primeras
 * tablas del tenant.
 *
 * Lo que sí se puede comprobar ya: los invariantes del esquema, y —desde que
 * `T-004` adelantó `Organization`— que `CreatesNeighbourOrganization` siembra
 * una organización vecina de verdad, que es la precondición de todo test de
 * fuga cruzada (`REQ-QAX-003` RB-10).
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
