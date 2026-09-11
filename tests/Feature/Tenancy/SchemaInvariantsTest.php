<?php

declare(strict_types=1);

use Tests\Concerns\CreatesNeighbourOrganization;

/**
 * Mitad estática del gate de aislamiento (`REQ-QAX-008`).
 *
 * Este archivo es lo único que la suite `Tenancy` contiene hoy, y existe para
 * que su job de CI no pase en verde sobre un directorio vacío. **`T-007` es
 * quien la completa**: `tenancy:audit`, `CrossTenantAccessTest`,
 * `BroadcastChannelIsolationTest`, `JobTenantContextTest` y
 * `AccountBoundaryTest`, que necesitan `TenantContext` (T-005) y las primeras
 * tablas del tenant (T-004).
 *
 * Lo que sí se puede comprobar ya son los invariantes del esquema que no
 * dependen de que exista ninguna tabla nuestra.
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
 * `REQ-QAX-003` RB-10: el helper que obliga a sembrar una organización vecina
 * tiene que existir y anunciarse, aunque su cuerpo llegue con T-005.
 */
it('expone el helper que siembra la organización vecina', function (): void {
    $reflection = new ReflectionClass(CreatesNeighbourOrganization::class);

    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        $reflection->getMethods(),
    );

    expect($reflection->isTrait())->toBeTrue();
    expect($methods)->toEqualCanonicalizing(['createNeighbourOrganization', 'hasNeighbourOrganization']);
});

/**
 * Mientras `Organization` no exista, el helper falla con un mensaje que dice
 * qué falta. Lo que no puede hacer es devolver un escenario de un solo tenant
 * y dejar que un test de aislamiento se declare verde sobre él.
 */
it('falla de forma explícita mientras Organization no exista', function (): void {
    $harness = new class
    {
        use CreatesNeighbourOrganization;

        public function attempt(): object
        {
            return $this->createNeighbourOrganization();
        }
    };

    if (class_exists('App\\Models\\Organization')) {
        expect($harness->attempt())->toBeObject();

        return;
    }

    expect(fn () => $harness->attempt())->toThrow(RuntimeException::class, 'T-005');
});
