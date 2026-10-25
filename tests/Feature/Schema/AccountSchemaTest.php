<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

it('crea las tres tablas de la cuenta de consumidor', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['account_identities', 'catalog_subscriptions', 'catalog_digest_dispatches']);

it('no pone organization_id en las tablas de la cuenta', function (string $table): void {
    expect(SchemaInspector::columns($table))->not->toHaveKey('organization_id');
})->with(['account_identities', 'catalog_subscriptions', 'catalog_digest_dispatches']);

it('identifica account_identities por proveedor y provider_id', function (): void {
    $columns = SchemaInspector::columns('account_identities');

    expect(array_keys($columns))->toContain('account_id', 'provider', 'provider_id', 'email');
    expect(SchemaInspector::hasUniqueOn('account_identities', 'provider', 'provider_id'))->toBeTrue();
    expect(SchemaInspector::foreignKey('account_identities', 'account_id')['foreign_table'] ?? null)->toBe('accounts');
});

it('hace única la suscripción por cuenta y catálogo', function (): void {
    $columns = SchemaInspector::columns('catalog_subscriptions');

    expect(array_keys($columns))->toContain('account_id', 'catalog_id', 'subscribed_at', 'muted_at');
    expect($columns['muted_at']['nullable'])->toBeTrue();

    expect(SchemaInspector::hasUniqueOn('catalog_subscriptions', 'account_id', 'catalog_id'))->toBeTrue();
    expect(SchemaInspector::hasIndexOn('catalog_subscriptions', 'catalog_id'))->toBeTrue();

    expect(SchemaInspector::foreignKey('catalog_subscriptions', 'account_id')['foreign_table'] ?? null)->toBe('accounts');
    expect(SchemaInspector::foreignKey('catalog_subscriptions', 'catalog_id')['foreign_table'] ?? null)->toBe('catalogs');
});

it('hace único el digest por catálogo, cuenta y día', function (): void {
    expect(SchemaInspector::hasUniqueOn('catalog_digest_dispatches', 'catalog_id', 'account_id', 'digest_date'))->toBeTrue();

    $columns = SchemaInspector::columns('catalog_digest_dispatches');
    expect(array_keys($columns))->toContain('catalog_id', 'account_id', 'digest_date', 'products_count', 'sent_at');
});

it('declara los dos puentes en la lista blanca de tenancy:audit', function (): void {
    expect(GlobalUniqueExceptions::allowsMissingTenantColumn('catalog_subscriptions'))->toBeTrue();
    expect(GlobalUniqueExceptions::allowsMissingTenantColumn('catalog_digest_dispatches'))->toBeTrue();
});
