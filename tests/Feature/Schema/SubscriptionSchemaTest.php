<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantScope;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

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

it('permite una sola suscripción por organización', function (): void {
    expect(SchemaInspector::hasUniqueOn('subscriptions', 'organization_id'))->toBeTrue();
});

it('declara la tabla subscriptions', function (): void {
    $columns = SchemaInspector::columns('subscriptions');

    expect(array_keys($columns))->toContain(
        'organization_id', 'plan_id', 'status', 'started_at',
        'current_period_start', 'current_period_end', 'grace_ends_at', 'cancelled_at',
    );

    expect($columns['status']['type_name'])->toBe('varchar');
    expect($columns['started_at']['nullable'])->toBeFalse();
    expect($columns['current_period_start']['nullable'])->toBeFalse();
});

it('deja sin fecha de fin a las suscripciones abiertas', function (): void {
    $columns = SchemaInspector::columns('subscriptions');

    expect($columns['current_period_end']['nullable'])->toBeTrue();
    expect($columns['grace_ends_at']['nullable'])->toBeTrue();
    expect($columns['cancelled_at']['nullable'])->toBeTrue();
});

it('apunta a plans sin borrado en cascada', function (): void {
    $planKey = array_values(array_filter(
        Schema::getForeignKeys('subscriptions'),
        fn (array $key): bool => $key['foreign_table'] === 'plans',
    ));

    expect(array_map(fn (array $key): array => $key['columns'], $planKey))->toEqualCanonicalizing([['plan_id'], ['scheduled_plan_id']]);

    foreach ($planKey as $key) {
        expect(strtolower((string) $key['on_delete']))->not->toBe('cascade');
    }
});

it('declara usage_counters con una fila por métrica', function (): void {
    $columns = SchemaInspector::columns('usage_counters');

    expect(array_keys($columns))->toContain('organization_id', 'metric', 'value', 'computed_at');

    expect(SchemaInspector::hasUniqueOn('usage_counters', 'organization_id', 'metric'))->toBeTrue();
});

it('guarda el consumo sin signo', function (): void {
    expect(SchemaInspector::columns('usage_counters')['value']['type'])->toContain('unsigned');
});

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

it('declara export_jobs en su organización', function (): void {
    $columns = SchemaInspector::columns('export_jobs');

    expect($columns)->toHaveKey(TenantScope::COLUMN);
    expect(array_keys($columns))->toContain('requested_by', 'type', 'status', 'filters', 'file_path', 'file_size', 'row_count', 'error_message', 'expires_at');
    expect(strtolower($columns['file_size']['type_name']))->toBe('bigint');
    expect(array_map(fn (array $index): array => $index['columns'], SchemaInspector::indexes('export_jobs')))
        ->toContain(['organization_id', 'type', 'status'], ['status', 'expires_at']);
});
