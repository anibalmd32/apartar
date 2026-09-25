<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

/**
 * Bloque 4 del esquema: la identidad del cliente final ([05 §4]).
 *
 * De este bloque son dos tablas y no tres: `account_identities` llega en H6
 * con las cuentas. Lo que se afirma aquí sostiene todo el acceso del cliente
 * en H3 —quién es una ficha, con qué se entra y con qué no—, y un error se
 * corrige con una migración de alteración cuando el portal ya está encima.
 */
it('crea las dos tablas del bloque 4', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['customers', 'customer_login_tokens']);

it('ata cada tabla del bloque 4 a su organización', function (string $table): void {
    expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);
    expect(SchemaInspector::foreignKey($table, TenantScope::COLUMN)['foreign_table'] ?? null)->toBe('organizations');
})->with(['customers', 'customer_login_tokens']);

/**
 * `REQ-ORG-002` RB-3: aquí **no hay** excepción que valga. Ninguna de las dos
 * tablas tiene una clave que haya que resolver sin tenant.
 */
it('incluye organization_id en todo índice único del bloque 4', function (string $table): void {
    $offenders = [];

    foreach (SchemaInspector::indexes($table) as $index) {
        if ($index['unique'] && ! $index['primary'] && ! in_array(TenantScope::COLUMN, $index['columns'], true)) {
            $offenders[] = $table.'('.implode(', ', $index['columns']).')';
        }
    }

    expect($offenders)->toBe([], 'Índices únicos sin organization_id: '.implode(', ', $offenders));
})->with(['customers', 'customer_login_tokens']);

/**
 * `REQ-IDN-001` RB-5. La ausencia **es** el diseño: se entra con documento +
 * código, con enlace o con cuenta vinculada, y una columna de contraseña sería
 * la invitación a escribir el login que no debe existir. Su gemelo, del lado
 * de `accounts`, está en `PlatformSchemaTest`.
 */
it('no guarda contraseña en customers', function (): void {
    $columns = array_keys(SchemaInspector::columns('customers'));

    $forbidden = array_values(array_filter(
        $columns,
        fn (string $column): bool => (bool) preg_match('/(password|passwd|pwd)/i', $column),
    ));

    expect($forbidden)->toBe([], 'customers no tiene contraseña (REQ-IDN-001 RB-5): se entra con documento + código, '
        .'enlace por correo o cuenta vinculada, y una columna así es la invitación a escribir un login que no '
        .'debe existir. Encontrada: '.implode(', ', $forbidden));
});

it('declara customers según §4 bloque 4', function (): void {
    expect(array_keys(SchemaInspector::columns('customers')))->toContain(
        'organization_id', 'document_number', 'document_kind', 'full_name', 'phone',
        'email', 'account_id', 'terms_accepted_at', 'terms_ip_address', 'last_login_at',
    );
});

/**
 * RB-2 y RB-3: el teléfono es el canal real y es obligatorio; el correo es
 * opcional y no identifica a nadie.
 */
it('exige teléfono y deja el correo opcional', function (): void {
    $columns = SchemaInspector::columns('customers');

    expect($columns['phone']['nullable'])->toBeFalse();
    expect($columns['email']['nullable'])->toBeTrue();
    expect($columns['account_id']['nullable'])->toBeTrue();
});

it('no hace único el correo del cliente', function (): void {
    foreach (SchemaInspector::indexes('customers') as $index) {
        expect($index['unique'] && in_array('email', $index['columns'], true))
            ->toBeFalse('Dos fichas de la misma organización pueden compartir correo (REQ-IDN-001 RB-3).');
    }
});

/**
 * RB-1: el mismo documento en dos negocios son dos fichas. Un único global
 * sería el registro de cédulas que el modelo de dos identidades evita.
 */
it('acota el documento a su organización', function (): void {
    expect(SchemaInspector::hasUniqueOn('customers', 'organization_id', 'document_number'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('customers', 'document_number'))->toBeFalse();
});

it('indexa las fichas por organización y teléfono', function (): void {
    expect(SchemaInspector::hasIndexOn('customers', 'organization_id', 'phone'))->toBeTrue();
});

/**
 * [02 §5]: el tipo de documento es un enum de PHP sobre `VARCHAR` (`T-040`).
 * Sin valor por defecto: confundir una cédula con un RIF cambia la naturaleza
 * del cliente (`REQ-IDN-007` RB-2), y eso lo decide el VO.
 */
it('persiste el tipo de documento como varchar sin valor por defecto', function (): void {
    $column = SchemaInspector::columns('customers')['document_kind'];

    expect($column['type_name'])->toBe('varchar');
    expect($column['nullable'])->toBeFalse();
    expect($column['default'])->toBeNull();
});

/**
 * `REQ-ACC-004` RB-3: una cuenta se vincula con **como máximo una** ficha por
 * organización. Con `account_id` nullable, el índice tiene que dejar pasar
 * cualquier número de fichas sin cuenta —MySQL no considera iguales dos
 * `NULL`— y rechazar la segunda ficha de la misma cuenta. Se afirma con filas
 * y no leyendo el índice, porque lo que parece un error es el comportamiento
 * del motor, no la declaración.
 */
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

/**
 * La ficha es del negocio y la cuenta es de la persona: si la cuenta
 * desaparece, el negocio conserva a su cliente y su deuda.
 */
it('desvincula la ficha, sin borrarla, si desaparece la cuenta', function (): void {
    expect(SchemaInspector::foreignKey('customers', 'account_id'))
        ->toBe(['foreign_table' => 'accounts', 'on_delete' => 'set null']);
});

/**
 * **`UNIQUE(organization_id, token_hash)`, no global** (`REQ-IDN-003`,
 * [05 §4.1]). El global habría sido la cuarta entrada de
 * `GlobalUniqueExceptions`; el token lleva delante el identificador de su
 * organización y el tenant se fija antes de buscarlo.
 */
it('acota el hash del enlace a su organización, sin abrir una cuarta excepción', function (): void {
    expect(SchemaInspector::hasUniqueOn('customer_login_tokens', 'organization_id', 'token_hash'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('customer_login_tokens', 'token_hash'))->toBeFalse();

    expect(GlobalUniqueExceptions::allowsGlobalUnique('customer_login_tokens', 'token_hash'))->toBeFalse();
});

/**
 * RB-4: solo se guarda el `sha256` del token, en hexadecimal.
 */
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

/**
 * RB-5 invalida los tokens vivos de un cliente al emitir otro.
 */
it('indexa los enlaces por cliente y caducidad', function (): void {
    expect(SchemaInspector::hasIndexOn('customer_login_tokens', 'customer_id', 'expires_at'))->toBeTrue();
});

it('borra los enlaces con su ficha', function (): void {
    expect(SchemaInspector::foreignKey('customer_login_tokens', 'customer_id'))
        ->toBe(['foreign_table' => 'customers', 'on_delete' => 'cascade']);
});
