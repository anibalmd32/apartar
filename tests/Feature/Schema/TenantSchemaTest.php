<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

/**
 * Bloque 3 del esquema: las tres primeras tablas del tenant ([05 §4]).
 *
 * Son las primeras que llevan `organization_id`, así que además de sus
 * columnas se comprueba aquí lo que las convierte en tablas del tenant: la
 * columna, su clave foránea y que sus índices únicos la incluyan.
 */
it('crea las tres tablas del bloque 3', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['users', 'organization_settings', 'payment_accounts']);

it('ata cada tabla del bloque 3 a su organización', function (string $table): void {
    expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);

    $referenced = array_map(
        fn (array $key): string => $key['foreign_table'],
        Schema::getForeignKeys($table),
    );

    expect($referenced)->toContain('organizations');
})->with(['users', 'organization_settings', 'payment_accounts']);

/**
 * `REQ-ORG-002` RB-3: **todo índice único de negocio incluye
 * `organization_id`**, salvo las tres excepciones declaradas. Este test lo
 * comprueba contra el esquema real y contra la lista blanca, que es de donde
 * `tenancy:audit` sacará lo mismo en `T-007`.
 */
it('incluye organization_id en todo índice único del tenant', function (string $table): void {
    $offenders = [];

    foreach (Schema::getIndexes($table) as $index) {
        if (! $index['unique'] || $index['primary']) {
            continue;
        }

        if (in_array(TenantScope::COLUMN, $index['columns'], true)) {
            continue;
        }

        foreach ($index['columns'] as $column) {
            if (! GlobalUniqueExceptions::allowsGlobalUnique($table, $column)) {
                $offenders[] = $table.'.'.$column;
            }
        }
    }

    expect($offenders)->toBe([], 'Índices únicos sin organization_id ni excepción declarada: '.implode(', ', $offenders));
})->with(['users', 'organization_settings', 'payment_accounts']);

/**
 * Y la excepción declarada existe de verdad: `users.email` es único en toda la
 * plataforma porque el login no pide organización.
 */
it('mantiene el email de usuario único en toda la plataforma', function (): void {
    expect(SchemaInspector::hasUniqueOn('users', 'email'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('users', 'organization_id', 'email'))->toBeFalse();

    expect(GlobalUniqueExceptions::allowsGlobalUnique('users', 'email'))->toBeTrue();
});

it('indexa los usuarios por organización y rol', function (): void {
    expect(SchemaInspector::hasIndexOn('users', 'organization_id', 'role'))->toBeTrue();
});

/**
 * `REQ-ORG-004` RB-3: el invitado existe sin contraseña hasta que acepta. Con
 * la columna `NOT NULL` —como la deja Laravel— la invitación no se podría
 * implementar sin una migración de alteración.
 */
it('deja la contraseña del usuario nullable', function (): void {
    expect(SchemaInspector::columns('users')['password']['nullable'])->toBeTrue();
});

it('marca la invitación y la desactivación como opcionales', function (string $column): void {
    expect(SchemaInspector::columns('users')[$column]['nullable'])->toBeTrue();
})->with(['invited_at', 'disabled_at', 'email_verified_at']);

/**
 * Igual que en `platform_users`: quien tenga que forzar la rotación lo pone en
 * `true` explícitamente.
 */
it('no obliga por defecto a cambiar la contraseña', function (): void {
    $column = SchemaInspector::columns('users')['must_change_password'];

    expect($column['nullable'])->toBeFalse();
    expect($column['default'])->toContain('0');
});

it('persiste el rol como varchar con member por defecto', function (): void {
    $column = SchemaInspector::columns('users')['role'];

    expect($column['type_name'])->toBe('varchar');
    expect($column['default'])->toContain('member');
});

/**
 * `REQ-ORG-006`: clave-valor, para que una clave ausente caiga a su default
 * sin necesidad de migración.
 */
it('guarda los ajustes de organización como clave-valor único por tenant', function (): void {
    $columns = SchemaInspector::columns('organization_settings');

    expect(array_keys($columns))->toContain('organization_id', 'key', 'value', 'updated_by');
    expect($columns['value']['type_name'])->toBe('json');
    expect($columns['updated_by']['nullable'])->toBeTrue();

    expect(SchemaInspector::hasUniqueOn('organization_settings', 'organization_id', 'key'))->toBeTrue();
});

/**
 * `REQ-ORG-007`: titular y cédula son parte del dato de pago, no metadatos.
 */
it('declara payment_accounts según §4 bloque 3', function (): void {
    $columns = SchemaInspector::columns('payment_accounts');

    expect(array_keys($columns))->toContain(
        'method', 'bank', 'holder_name', 'holder_document',
        'identifier', 'instructions', 'is_active', 'position',
    );

    expect($columns['holder_name']['nullable'])->toBeFalse();
    expect($columns['holder_document']['nullable'])->toBeFalse();
    expect($columns['bank']['nullable'])->toBeTrue();
    expect($columns['instructions']['nullable'])->toBeTrue();

    expect(SchemaInspector::hasIndexOn('payment_accounts', 'organization_id', 'is_active'))->toBeTrue();
});

/**
 * RB-7: la nota que se muestra al cliente es corta por contrato.
 */
it('acota las instrucciones de cobro a 300 caracteres', function (): void {
    expect(SchemaInspector::columns('payment_accounts')['instructions']['type'])->toContain('300');
});
