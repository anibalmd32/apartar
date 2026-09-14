<?php

declare(strict_types=1);

use App\Domain\Subscription\UsageMetric;

/**
 * Las seis métricas de `REQ-SUB-003` RB-2 y su mapa a las columnas del plan.
 *
 * Lo que se fija aquí es que la lista **no crezca por simetría**. La tentación
 * concreta está documentada: añadir `max_subscribers` porque los demás límites
 * tienen métrica. [03 §4.6] y `REQ-SUB-001` RB-4 dicen lo contrario, y el
 * motivo es de producto: el número de suscriptores es lo que hace valioso el
 * catálogo, y cobrarlo frenaría lo único que crece solo.
 */
it('declara exactamente las seis métricas de RB-2', function (): void {
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

/**
 * Cada métrica sabe qué columna la limita, y ese mapa es lo que permite que
 * ningún `if` compare contra el código de un plan (`REQ-SUB-001` RB-1).
 */
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

/**
 * La columna que ninguna métrica reclama es `max_products_per_catalog`, y no
 * es un olvido: se cuenta por catálogo y los contadores son por organización.
 */
it('deja sin métrica la única columna que se cuenta por catálogo', function (): void {
    $columns = array_map(fn (UsageMetric $metric): string => $metric->planColumn(), UsageMetric::cases());

    expect($columns)->not->toContain('max_products_per_catalog');
    expect($columns)->toHaveCount(6);
});

/**
 * RB-5: `storage_mb` es la única derivada. Las conversiones de imagen y las
 * purgas la mueven de forma asíncrona, así que sumar y restar a mano
 * acumularía deriva.
 */
it('marca solo el almacenamiento como métrica derivada', function (UsageMetric $metric): void {
    expect($metric->isDerived())->toBe($metric === UsageMetric::StorageMb);
})->with(UsageMetric::cases());

it('nombra la unidad del almacenamiento en MB', function (): void {
    expect(UsageMetric::StorageMb->unit())->toBe('MB');
    expect(UsageMetric::Products->unit())->toBe('unidades');
});

/**
 * El nombre que lee una persona, concordado con la cifra de la que se habla.
 *
 * Hace falta porque el mensaje de `PlanLimitReachedException` lo lee el dueño
 * de un negocio (`REQ-SUB-004` RB-5): «tu plan permite 1 users» no es un
 * mensaje, es el nombre de una columna. El singular importa de verdad, porque
 * el techo de Free es exactamente uno.
 */
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
