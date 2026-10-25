<?php

declare(strict_types=1);

use App\Domain\Subscription\UsageMetric;

it('declara exactamente las seis métricas', function (): void {
    $values = array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases());

    expect($values)->toBe([
        'users', 'catalogs', 'products', 'active_layaways', 'storage_mb', 'payment_accounts',
    ]);
});

it('no cuenta a los suscriptores de catálogo', function (): void {
    $values = array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases());

    expect($values)->not->toContain('subscribers');
    expect($values)->not->toContain('catalog_subscriptions');
});

it('mapea cada métrica a su columna de límite', function (UsageMetric $metric, string $column): void {
    expect($metric->planColumn())->toBe($column);
})->with([
    [UsageMetric::Users, 'max_users'],
    [UsageMetric::Catalogs, 'max_catalogs'],
    [UsageMetric::Products, 'max_products'],
    [UsageMetric::ActiveLayaways, 'max_active_layaways'],
    [UsageMetric::StorageMb, 'max_storage_mb'],
    [UsageMetric::PaymentAccounts, 'max_payment_accounts'],
]);

it('deja sin métrica la única columna que se cuenta por catálogo', function (): void {
    $columns = array_map(fn (UsageMetric $metric): string => $metric->planColumn(), UsageMetric::cases());

    expect($columns)->not->toContain('max_products_per_catalog');
    expect($columns)->toHaveCount(6);
});

it('marca solo el almacenamiento como métrica derivada', function (UsageMetric $metric): void {
    expect($metric->isDerived())->toBe($metric === UsageMetric::StorageMb);
})->with(UsageMetric::cases());

it('nombra la unidad del almacenamiento en MB', function (): void {
    expect(UsageMetric::StorageMb->unit())->toBe('MB');
    expect(UsageMetric::Products->unit())->toBe('unidades');
});

it('nombra cada métrica concordando con la cantidad', function (UsageMetric $metric, string $one, string $many): void {
    expect($metric->label(1))->toBe($one);
    expect($metric->label(5))->toBe($many);
    expect($metric->label())->toBe($many);
})->with([
    'usuarios' => [UsageMetric::Users, 'usuario', 'usuarios'],
    'catálogos' => [UsageMetric::Catalogs, 'catálogo publicado', 'catálogos publicados'],
    'productos' => [UsageMetric::Products, 'producto', 'productos'],
    'apartados' => [UsageMetric::ActiveLayaways, 'apartado activo', 'apartados activos'],
    'almacenamiento' => [UsageMetric::StorageMb, 'MB de almacenamiento', 'MB de almacenamiento'],
    'cuentas de cobro' => [UsageMetric::PaymentAccounts, 'cuenta de cobro', 'cuentas de cobro'],
]);
