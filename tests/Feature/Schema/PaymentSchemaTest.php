<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantScope;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

it('crea las tres tablas de la cobranza, atadas a su organización', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
    expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);
    expect(SchemaInspector::foreignKey($table, TenantScope::COLUMN)['foreign_table'] ?? null)->toBe('organizations');
})->with(['receipts', 'payments', 'payment_allocations']);

it('declara receipts según §4 bloque 7', function (): void {
    expect(array_keys(SchemaInspector::columns('receipts')))->toContain(
        'organization_id', 'layaway_id', 'customer_id', 'payment_account_id', 'payment_method', 'bank',
        'reference', 'paid_at', 'declared_amount', 'declared_currency', 'note', 'file_path', 'file_hash',
        'file_mime', 'file_size', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason_code',
        'rejection_reason', 'replaces_receipt_id',
    );
});

it('declara payments con las seis columnas del snapshot de tasa', function (): void {
    $columns = SchemaInspector::columns('payments');

    expect(array_keys($columns))->toContain(
        'organization_id', 'layaway_id', 'receipt_id', 'amount_cents_usd', 'declared_currency', 'declared_amount',
        'rate_applied', 'rate_source', 'rate_published_at', 'settled_at', 'registered_by', 'note',
    );
    expect($columns)->not->toHaveKey('rate_fetched_at');
    expect(strtolower($columns['rate_applied']['type']))->toBe('decimal(18,8)');
});

it('declara payment_allocations con la columna del tenant', function (): void {
    expect(array_keys(SchemaInspector::columns('payment_allocations')))->toContain(
        'organization_id', 'payment_id', 'installment_id', 'amount_cents',
    );
});

it('hace únicos los comprobantes por organización y no en toda la plataforma', function (): void {
    expect(SchemaInspector::hasUniqueOn('receipts', 'organization_id', 'file_hash'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('receipts', 'organization_id', 'bank', 'reference'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('receipts', 'file_hash'))->toBeFalse();
    expect(SchemaInspector::hasUniqueOn('receipts', 'bank', 'reference'))->toBeFalse();
});

it('hace único el pago de cada comprobante dentro de su organización', function (): void {
    expect(SchemaInspector::hasUniqueOn('payments', 'organization_id', 'receipt_id'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('payments', 'receipt_id'))->toBeFalse();
});

it('hace única la clave de idempotencia del pago manual dentro de su organización', function (): void {
    expect(SchemaInspector::columns('payments')['idempotency_key']['nullable'])->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('payments', 'organization_id', 'idempotency_key'))->toBeTrue();
});

it('guarda los importes de la cobranza como BIGINT con signo', function (string $table, string $column): void {
    $definition = SchemaInspector::columns($table)[$column];

    expect(strtolower($definition['type_name']))->toBe('bigint');
    expect(strtolower($definition['type']))->not->toContain('unsigned');
})->with([
    ['payments', 'amount_cents_usd'],
    ['payments', 'unallocated_cents'],
    ['payment_allocations', 'amount_cents'],
]);

it('guarda el sobrante de cada pago, obligatorio y sin valor por defecto', function (): void {
    $column = SchemaInspector::columns('payments')['unallocated_cents'];

    expect($column['nullable'])->toBeFalse();
    expect($column['default'])->toBeNull();
});

it('guarda lo declarado como decimal, sin convertir', function (string $table): void {
    expect(strtolower(SchemaInspector::columns($table)['declared_amount']['type']))->toBe('decimal(16,2)');
})->with(['receipts', 'payments']);

it('protege con claves restrictivas lo que un comprobante prueba', function (string $table, string $column, string $foreign): void {
    expect(SchemaInspector::foreignKey($table, $column))->toBe(['foreign_table' => $foreign, 'on_delete' => 'restrict']);
})->with([
    ['receipts', 'payment_account_id', 'payment_accounts'],
    ['receipts', 'layaway_id', 'layaways'],
    ['payments', 'receipt_id', 'receipts'],
    ['payment_allocations', 'installment_id', 'installments'],
]);

it('borra el reparto con su pago', function (): void {
    expect(SchemaInspector::foreignKey('payment_allocations', 'payment_id'))->toBe(['foreign_table' => 'payments', 'on_delete' => 'cascade']);
});

it('nace el comprobante en revisión, como varchar', function (): void {
    $column = SchemaInspector::columns('receipts')['status'];

    expect($column['type_name'])->toBe('varchar');
    expect($column['default'])->toContain('pending_review');
});

it('indexa la bandeja de conciliación y los comprobantes de un apartado', function (): void {
    expect(SchemaInspector::hasIndexOn('receipts', 'organization_id', 'status', 'created_at'))->toBeTrue();
    expect(SchemaInspector::hasIndexOn('receipts', 'organization_id', 'layaway_id', 'status'))->toBeTrue();
    expect(SchemaInspector::hasIndexOn('payments', 'organization_id', 'settled_at'))->toBeTrue();
});
