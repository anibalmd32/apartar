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

    expect($planKey)->toHaveCount(1);
    expect(strtolower((string) $planKey[0]['on_delete']))->not->toBe('cascade');
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
