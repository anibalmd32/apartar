<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;

/**
 * La lista blanca del aislamiento (`REQ-ORG-002` RB-3, `REQ-QAX-008` RB-4).
 *
 * Lo que se fija aquí es que la lista **no crezca**. Su valor entero depende
 * de que sea corta y deliberada: una lista blanca que admite excepciones
 * nuevas sin discusión deja de ser una lista blanca y pasa a ser el sitio
 * donde se archivan los descuidos.
 */
it('declara exactamente los tres índices únicos globales', function (): void {
    expect(array_keys(GlobalUniqueExceptions::indexes()))
        ->toBe(['users.email', 'catalogs.slug', 'layaways.code']);
});

/**
 * Las tablas exentas son de dos clases y conviene no confundirlas: las dos
 * primeras son **puentes de la capa de plataforma** —relacionan una `Account`
 * global con un catálogo y cruzan la frontera a propósito— y las dos que trajo
 * `T-024` son **pivotes entre tablas ya aisladas**, sin modelo ni consultas
 * propias, a cuyas filas solo se llega desde padres que ya llevan el global
 * scope.
 *
 * La lista sigue estando fijada aquí: una quinta entrada pone esto en rojo y
 * hay que justificarla, que es todo el valor que tiene una lista blanca.
 */
it('declara exactamente las cuatro tablas exentas de organization_id', function (): void {
    expect(array_keys(GlobalUniqueExceptions::tables()))
        ->toBe(['catalog_subscriptions', 'catalog_digest_dispatches', 'product_tag', 'catalog_product']);
});

it('distingue los pivotes de los puentes de plataforma', function (string $pivot): void {
    expect(GlobalUniqueExceptions::allowsMissingTenantColumn($pivot))->toBeTrue();
    expect(GlobalUniqueExceptions::reasonFor($pivot))->toContain('Pivote');
})->with(['product_tag', 'catalog_product']);

/**
 * El motivo es la mitad útil de la lista: sin él, dentro de seis meses las
 * tres excepciones parecerán descuidos y alguien las "arreglará".
 */
it('escribe el motivo de cada excepción', function (): void {
    foreach (GlobalUniqueExceptions::indexes() + GlobalUniqueExceptions::tables() as $key => $reason) {
        expect($reason)->not->toBe('', "La excepción {$key} no declara motivo.");
        expect(strlen($reason))->toBeGreaterThan(40, "El motivo de {$key} es demasiado escueto.");
    }
});

it('responde si un índice único puede prescindir de organization_id', function (): void {
    expect(GlobalUniqueExceptions::allowsGlobalUnique('users', 'email'))->toBeTrue();
    expect(GlobalUniqueExceptions::allowsGlobalUnique('catalogs', 'slug'))->toBeTrue();
    expect(GlobalUniqueExceptions::allowsGlobalUnique('layaways', 'code'))->toBeTrue();
});

it('rechaza cualquier otro índice único sin organization_id', function (string $table, string $column): void {
    expect(GlobalUniqueExceptions::allowsGlobalUnique($table, $column))->toBeFalse();
})->with([
    ['products', 'sku'],
    ['products', 'slug'],
    ['customers', 'document_number'],
    ['receipts', 'file_hash'],
    ['users', 'name'],
    ['catalogs', 'name'],
]);

it('responde si una tabla puede prescindir de la columna', function (): void {
    expect(GlobalUniqueExceptions::allowsMissingTenantColumn('catalog_subscriptions'))->toBeTrue();
    expect(GlobalUniqueExceptions::allowsMissingTenantColumn('catalog_digest_dispatches'))->toBeTrue();
    expect(GlobalUniqueExceptions::allowsMissingTenantColumn('products'))->toBeFalse();
});

it('devuelve el motivo declarado de una excepción', function (): void {
    expect(GlobalUniqueExceptions::reasonFor('users.email'))->toContain('login');
    expect(GlobalUniqueExceptions::reasonFor('catalogs.slug'))->toContain('URL pública');
    expect(GlobalUniqueExceptions::reasonFor('layaways.code'))->toContain('portal');
    expect(GlobalUniqueExceptions::reasonFor('catalog_subscriptions'))->toContain('plataforma');
});

it('no inventa motivo para lo que no está en la lista', function (): void {
    expect(GlobalUniqueExceptions::reasonFor('products.sku'))->toBeNull();
});
