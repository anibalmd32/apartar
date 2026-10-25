<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

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

it('deja el email de usuario único dentro de cada organización', function (): void {
    expect(SchemaInspector::hasUniqueOn('users', 'organization_id', 'email'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('users', 'email'))->toBeFalse();

    expect(GlobalUniqueExceptions::allowsGlobalUnique('users', 'email'))->toBeFalse();
});

it('indexa los usuarios por organización y rol', function (): void {
    expect(SchemaInspector::hasIndexOn('users', 'organization_id', 'role'))->toBeTrue();
});

it('deja la contraseña del usuario nullable', function (): void {
    expect(SchemaInspector::columns('users')['password']['nullable'])->toBeTrue();
});

it('marca la invitación y la desactivación como opcionales', function (string $column): void {
    expect(SchemaInspector::columns('users')[$column]['nullable'])->toBeTrue();
})->with(['invited_at', 'disabled_at', 'email_verified_at']);

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

it('guarda los ajustes de organización como clave-valor único por tenant', function (): void {
    $columns = SchemaInspector::columns('organization_settings');

    expect(array_keys($columns))->toContain('organization_id', 'key', 'value', 'updated_by');
    expect($columns['value']['type_name'])->toBe('json');
    expect($columns['updated_by']['nullable'])->toBeTrue();

    expect(SchemaInspector::hasUniqueOn('organization_settings', 'organization_id', 'key'))->toBeTrue();
});

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

it('acota las instrucciones de cobro a 300 caracteres', function (): void {
    expect(SchemaInspector::columns('payment_accounts')['instructions']['type'])->toContain('300');
});
