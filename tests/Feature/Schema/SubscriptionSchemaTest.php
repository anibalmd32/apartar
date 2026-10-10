<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantScope;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

/**
 * Bloque 2 del esquema, parte de suscripción y consumo ([05 §4]).
 *
 * Se crean dos de las cinco tablas del bloque: `subscriptions` y
 * `usage_counters`. `subscription_invoices`, `subscription_receipts` y
 * `support_access_grants` llegan con H5, cuando exista quien las escriba.
 *
 * Las dos llevan `organization_id`, así que son tablas del tenant y
 * `tenancy:audit` las trata como tales: columna, índice y unicidad que la
 * incluya.
 */
it('crea las dos tablas de suscripción y consumo', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['subscriptions', 'usage_counters']);

it('ata las dos tablas a su organización', function (string $table): void {
    expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);

    $referenced = array_map(
        fn (array $key): string => $key['foreign_table'],
        Schema::getForeignKeys($table),
    );

    expect($referenced)->toContain('organizations');
})->with(['subscriptions', 'usage_counters']);

/**
 * `REQ-SUB-002` RB-1: **exactamente una** suscripción por organización. La
 * unicidad no es una comodidad: una organización con dos suscripciones no
 * tiene plan, tiene una ambigüedad, y cada consulta de límites elegiría la
 * suya.
 */
it('permite una sola suscripción por organización', function (): void {
    expect(SchemaInspector::hasUniqueOn('subscriptions', 'organization_id'))->toBeTrue();
});

it('declara subscriptions según REQ-SUB-002', function (): void {
    $columns = SchemaInspector::columns('subscriptions');

    expect(array_keys($columns))->toContain(
        'organization_id', 'plan_id', 'status', 'started_at',
        'current_period_start', 'current_period_end', 'grace_ends_at', 'cancelled_at',
    );

    expect($columns['status']['type_name'])->toBe('varchar');
    expect($columns['started_at']['nullable'])->toBeFalse();
    expect($columns['current_period_start']['nullable'])->toBeFalse();
});

/**
 * RB-2: en Free no hay fecha de fin, así que la columna **tiene** que admitir
 * nulo. Con la columna obligatoria habría que inventar una fecha, y con ella
 * volvería el acantilado que el diseño evita (RB-3).
 */
it('deja sin fecha de fin a las suscripciones abiertas', function (): void {
    $columns = SchemaInspector::columns('subscriptions');

    expect($columns['current_period_end']['nullable'])->toBeTrue();
    expect($columns['grace_ends_at']['nullable'])->toBeTrue();
    expect($columns['cancelled_at']['nullable'])->toBeTrue();
});

/**
 * El plan no se borra estando en uso: dejaría organizaciones sin plan, que es
 * justo lo que `REQ-SUB-002` RB-6 prohíbe.
 */
it('apunta a plans sin borrado en cascada', function (): void {
    $planKey = array_values(array_filter(
        Schema::getForeignKeys('subscriptions'),
        fn (array $key): bool => $key['foreign_table'] === 'plans',
    ));

    // `plan_id` y, desde `T-068`, `scheduled_plan_id`: un plan programado
    // tampoco se borra, se despublica.
    expect(array_map(fn (array $key): array => $key['columns'], $planKey))->toEqualCanonicalizing([['plan_id'], ['scheduled_plan_id']]);

    foreach ($planKey as $key) {
        expect(strtolower((string) $key['on_delete']))->not->toBe('cascade');
    }
});

/**
 * `REQ-SUB-003`: una fila por métrica y organización. Sin la unicidad, dos
 * inicializaciones concurrentes dejarían dos contadores de la misma cosa.
 */
it('declara usage_counters con una fila por métrica', function (): void {
    $columns = SchemaInspector::columns('usage_counters');

    expect(array_keys($columns))->toContain('organization_id', 'metric', 'value', 'computed_at');

    expect(SchemaInspector::hasUniqueOn('usage_counters', 'organization_id', 'metric'))->toBeTrue();
});

/**
 * RB-8: un contador nunca queda negativo. La columna sin signo convierte en
 * error lo que de otro modo sería un cupo regalado.
 */
it('guarda el consumo sin signo', function (): void {
    expect(SchemaInspector::columns('usage_counters')['value']['type'])->toContain('unsigned');
});

/**
 * `T-069` · las facturas y los comprobantes de la suscripción son tablas del
 * tenant ([05 §4] bloque 2): `subscription_invoices` con
 * `INDEX(organization_id, due_date)`, y `subscription_receipts` con sus dos
 * únicos **compuestos con la organización**, que es la elección entre las dos
 * que dejaba la nota de [05 §4] —llevar la columna obliga a que el único la
 * incluya— y la que hace el bloqueo de duplicados el mismo de `receipts`.
 */
it('declara las facturas y los comprobantes de la suscripción en su organización', function (): void {
    foreach (['subscription_invoices', 'subscription_receipts'] as $table) {
        expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);
    }

    expect(array_keys(SchemaInspector::columns('subscription_invoices')))->toContain(
        'plan_id', 'kind', 'period_start', 'period_end', 'amount_cents', 'paid_cents', 'currency', 'status', 'due_date', 'issued_at', 'paid_at', 'voided_at',
    );
    expect(SchemaInspector::hasUniqueOn('subscription_receipts', 'organization_id', 'file_hash'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('subscription_receipts', 'organization_id', 'bank', 'reference'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('subscription_receipts', 'file_hash'))->toBeFalse();
});

/**
 * `T-070` · las exportaciones son del tenant (`REQ-BOF-043`): su organización,
 * el peso del archivo en `BIGINT` (`storage_mb`) y los dos índices de lo que
 * se pregunta —una en curso por tipo (RB-10) y la purga (RB-7)—.
 */
it('declara export_jobs en su organización', function (): void {
    $columns = SchemaInspector::columns('export_jobs');

    expect($columns)->toHaveKey(TenantScope::COLUMN);
    expect(array_keys($columns))->toContain('requested_by', 'type', 'status', 'filters', 'file_path', 'file_size', 'row_count', 'error_message', 'expires_at');
    expect(strtolower($columns['file_size']['type_name']))->toBe('bigint');
    expect(array_map(fn (array $index): array => $index['columns'], SchemaInspector::indexes('export_jobs')))
        ->toContain(['organization_id', 'type', 'status'], ['status', 'expires_at']);
});
