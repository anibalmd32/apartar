<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

it('crea las dos tablas del bloque 4', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['customers', 'customer_login_tokens']);

it('ata cada tabla del bloque 4 a su organización', function (string $table): void {
    expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);
    expect(SchemaInspector::foreignKey($table, TenantScope::COLUMN)['foreign_table'] ?? null)->toBe('organizations');
})->with(['customers', 'customer_login_tokens']);

it('incluye organization_id en todo índice único del bloque 4', function (string $table): void {
    $offenders = [];

    foreach (SchemaInspector::indexes($table) as $index) {
        if ($index['unique'] && ! $index['primary'] && ! in_array(TenantScope::COLUMN, $index['columns'], true)) {
            $offenders[] = $table.'('.implode(', ', $index['columns']).')';
        }
    }

    expect($offenders)->toBe([], 'Índices únicos sin organization_id: '.implode(', ', $offenders));
})->with(['customers', 'customer_login_tokens']);

it('no guarda contraseña en customers', function (): void {
    $columns = array_keys(SchemaInspector::columns('customers'));

    $forbidden = array_values(array_filter(
        $columns,
        fn (string $column): bool => (bool) preg_match('/(password|passwd|pwd)/i', $column),
    ));

    expect($forbidden)->toBe([], 'customers no tiene contraseña: se entra con documento + código, '
        .'enlace por correo o cuenta vinculada, y una columna así es la invitación a escribir un login que no '
        .'debe existir. Encontrada: '.implode(', ', $forbidden));
});

it('declara customers según §4 bloque 4', function (): void {
    expect(array_keys(SchemaInspector::columns('customers')))->toContain(
        'organization_id', 'document_number', 'document_kind', 'full_name', 'phone',
        'email', 'account_id', 'terms_accepted_at', 'terms_ip_address', 'last_login_at',
    );
});

it('exige teléfono y deja el correo opcional', function (): void {
    $columns = SchemaInspector::columns('customers');

    expect($columns['phone']['nullable'])->toBeFalse();
    expect($columns['email']['nullable'])->toBeTrue();
    expect($columns['account_id']['nullable'])->toBeTrue();
});

it('no hace único el correo del cliente', function (): void {
    foreach (SchemaInspector::indexes('customers') as $index) {
        expect($index['unique'] && in_array('email', $index['columns'], true))
            ->toBeFalse('Dos fichas de la misma organización pueden compartir correo.');
    }
});

it('acota el documento a su organización', function (): void {
    expect(SchemaInspector::hasUniqueOn('customers', 'organization_id', 'document_number'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('customers', 'document_number'))->toBeFalse();
});

it('indexa las fichas por organización y teléfono', function (): void {
    expect(SchemaInspector::hasIndexOn('customers', 'organization_id', 'phone'))->toBeTrue();
});

it('persiste el tipo de documento como varchar sin valor por defecto', function (): void {
    $column = SchemaInspector::columns('customers')['document_kind'];

    expect($column['type_name'])->toBe('varchar');
    expect($column['nullable'])->toBeFalse();
    expect($column['default'])->toBeNull();
});

it('admite muchas fichas sin cuenta y una sola por cuenta en cada organización', function (): void {
    expect(SchemaInspector::hasUniqueOn('customers', 'organization_id', 'account_id'))->toBeTrue();

    $organization = Organization::factory()->create();

    $row = fn (string $document, ?int $account): array => [
        'organization_id' => $organization->getKey(),
        'document_number' => $document,
        'document_kind' => 'cedula',
        'full_name' => 'Cliente '.$document,
        'phone' => '+584121234567',
        'account_id' => $account,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('customers')->insert([$row('V10000001', null), $row('V10000002', null), $row('V10000003', null)]);

    expect(DB::table('customers')->whereNull('account_id')->count())->toBe(3);

    $account = DB::table('accounts')->insertGetId([
        'display_name' => 'Ana',
        'email' => 'ana@ejemplo.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('customers')->insert($row('V10000004', $account));

    expect(fn () => DB::table('customers')->insert($row('V10000005', $account)))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('desvincula la ficha, sin borrarla, si desaparece la cuenta', function (): void {
    expect(SchemaInspector::foreignKey('customers', 'account_id'))
        ->toBe(['foreign_table' => 'accounts', 'on_delete' => 'set null']);
});

it('acota el hash del enlace a su organización, sin abrir una cuarta excepción', function (): void {
    expect(SchemaInspector::hasUniqueOn('customer_login_tokens', 'organization_id', 'token_hash'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('customer_login_tokens', 'token_hash'))->toBeFalse();

    expect(GlobalUniqueExceptions::allowsGlobalUnique('customer_login_tokens', 'token_hash'))->toBeFalse();
});

it('declara customer_login_tokens según §4 bloque 4', function (): void {
    $columns = SchemaInspector::columns('customer_login_tokens');

    expect(array_keys($columns))->toContain(
        'organization_id', 'customer_id', 'token_hash', 'expires_at', 'consumed_at', 'requested_ip',
    );

    expect($columns['token_hash']['type'])->toBe('varchar(64)');
    expect($columns['expires_at']['nullable'])->toBeFalse();
    expect($columns['consumed_at']['nullable'])->toBeTrue();
    expect($columns['requested_ip']['nullable'])->toBeFalse();
});

it('indexa los enlaces por cliente y caducidad', function (): void {
    expect(SchemaInspector::hasIndexOn('customer_login_tokens', 'customer_id', 'expires_at'))->toBeTrue();
});

it('borra los enlaces con su ficha', function (): void {
    expect(SchemaInspector::foreignKey('customer_login_tokens', 'customer_id'))
        ->toBe(['foreign_table' => 'customers', 'on_delete' => 'cascade']);
});
