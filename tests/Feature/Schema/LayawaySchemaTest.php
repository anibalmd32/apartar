<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

it('crea las dos tablas del bloque 7', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['layaways', 'installments']);

it('ata cada tabla del bloque 7 a su organización', function (string $table): void {
    expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);
    expect(SchemaInspector::foreignKey($table, TenantScope::COLUMN)['foreign_table'] ?? null)->toBe('organizations');
})->with(['layaways', 'installments']);

it('incluye organization_id en todo índice único del bloque 7', function (string $table): void {
    $offenders = [];

    foreach (SchemaInspector::indexes($table) as $index) {
        if (! $index['unique'] || $index['primary'] || in_array(TenantScope::COLUMN, $index['columns'], true)) {
            continue;
        }

        foreach ($index['columns'] as $column) {
            if (! GlobalUniqueExceptions::allowsGlobalUnique($table, $column)) {
                $offenders[] = $table.'.'.$column;
            }
        }
    }

    expect($offenders)->toBe([], 'Índices únicos sin organization_id ni excepción declarada: '.implode(', ', $offenders));
})->with(['layaways', 'installments']);

it('mantiene el código del apartado único en toda la plataforma', function (): void {
    expect(SchemaInspector::hasUniqueOn('layaways', 'code'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('layaways', 'organization_id', 'code'))->toBeFalse();

    expect(GlobalUniqueExceptions::allowsGlobalUnique('layaways', 'code'))->toBeTrue();
});

it('declara layaways según §4 bloque 7', function (): void {
    expect(array_keys(SchemaInspector::columns('layaways')))->toContain(
        'organization_id', 'code', 'customer_id', 'product_id', 'catalog_id', 'quantity',
        'unit_price_cents', 'down_payment_cents', 'installments_count', 'frequency_days',
        'product_name_snapshot', 'product_sku_snapshot', 'total_cents', 'paid_cents',
        'remaining_cents', 'status', 'expires_at', 'extended_at', 'activated_at',
        'next_due_date', 'completed_at', 'cancelled_at', 'cancellation_reason',
        'delivered_at', 'stock_released_at',
    );
});

it('declara installments según §4 bloque 7', function (): void {
    expect(array_keys(SchemaInspector::columns('installments')))->toContain(
        'organization_id', 'layaway_id', 'number', 'due_date', 'amount_cents', 'paid_cents', 'status', 'paid_at',
    );
});

it('guarda los importes del bloque 7 como BIGINT de céntimos con signo', function (string $table, string $column): void {
    $definition = SchemaInspector::columns($table)[$column];

    expect(strtolower($definition['type_name']))->toBe('bigint');
    expect(strtolower($definition['type']))->not->toContain('unsigned');
    expect($definition['nullable'])->toBeFalse();
})->with([
    ['layaways', 'unit_price_cents'],
    ['layaways', 'down_payment_cents'],
    ['layaways', 'total_cents'],
    ['layaways', 'paid_cents'],
    ['layaways', 'remaining_cents'],
    ['installments', 'amount_cents'],
    ['installments', 'paid_cents'],
]);

it('arranca lo pagado en cero y no da por defecto lo pendiente', function (): void {
    $columns = SchemaInspector::columns('layaways');

    expect($columns['paid_cents']['default'])->toBe('0');
    expect($columns['remaining_cents']['default'])->toBeNull();

    expect(SchemaInspector::columns('installments')['paid_cents']['default'])->toBe('0');
});

it('guarda los vencimientos como DATE', function (string $table, string $column): void {
    expect(strtolower(SchemaInspector::columns($table)[$column]['type_name']))->toBe('date');
})->with([
    ['installments', 'due_date'],
    ['layaways', 'next_due_date'],
]);

it('protege el producto del apartado con una clave ajena restrictiva', function (): void {
    expect(SchemaInspector::foreignKey('layaways', 'product_id'))
        ->toBe(['foreign_table' => 'products', 'on_delete' => 'restrict']);
});

it('protege la ficha del apartado con una clave ajena restrictiva', function (): void {
    expect(SchemaInspector::foreignKey('layaways', 'customer_id'))
        ->toBe(['foreign_table' => 'customers', 'on_delete' => 'restrict']);
});

it('deja el catálogo de origen opcional y lo suelta si desaparece', function (): void {
    expect(SchemaInspector::columns('layaways')['catalog_id']['nullable'])->toBeTrue();
    expect(SchemaInspector::foreignKey('layaways', 'catalog_id'))
        ->toBe(['foreign_table' => 'catalogs', 'on_delete' => 'set null']);
});

it('borra las cuotas con su apartado', function (): void {
    expect(SchemaInspector::foreignKey('installments', 'layaway_id'))
        ->toBe(['foreign_table' => 'layaways', 'on_delete' => 'cascade']);
});

it('indexa los apartados por organización, estado y próximo vencimiento', function (): void {
    expect(SchemaInspector::hasIndexOn('layaways', 'organization_id', 'status', 'next_due_date'))->toBeTrue();
    expect(SchemaInspector::hasIndexOn('layaways', 'organization_id', 'customer_id'))->toBeTrue();
});

it('hace única la cuota por organización, apartado y número', function (): void {
    expect(SchemaInspector::hasUniqueOn('installments', 'organization_id', 'layaway_id', 'number'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('installments', 'layaway_id', 'number'))->toBeFalse();
});

it('indexa las cuotas por organización, estado y vencimiento', function (): void {
    expect(SchemaInspector::hasIndexOn('installments', 'organization_id', 'status', 'due_date'))->toBeTrue();
});

it('persiste los estados como varchar con su estado inicial', function (string $table, string $initial): void {
    $column = SchemaInspector::columns($table)['status'];

    expect($column['type_name'])->toBe('varchar');
    expect($column['default'])->toContain($initial);
})->with([
    ['layaways', 'pending_down_payment'],
    ['installments', 'pending'],
]);

it('declara sin signo las cantidades que no son dinero', function (string $table, string $column): void {
    expect(strtolower(SchemaInspector::columns($table)[$column]['type']))->toContain('unsigned');
})->with([
    ['layaways', 'quantity'],
    ['layaways', 'installments_count'],
    ['layaways', 'frequency_days'],
    ['installments', 'number'],
]);
