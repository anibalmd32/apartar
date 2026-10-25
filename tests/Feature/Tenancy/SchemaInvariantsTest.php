<?php

declare(strict_types=1);

use App\Models\Organization;
use Tests\Concerns\CreatesNeighbourOrganization;

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

it('aplica el helper de la organización vecina en el caso base', function (): void {
    expect(class_uses_recursive(Tests\TestCase::class))
        ->toContain(CreatesNeighbourOrganization::class);
});

it('mantiene el slug de la organización único en toda la plataforma', function (): void {
    $organization = Organization::factory()->create();

    expect(fn () => Organization::factory()->create(['slug' => $organization->slug]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
