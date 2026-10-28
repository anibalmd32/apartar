<?php

declare(strict_types=1);

use App\Actions\Product\BulkPublishResult;

it('cuenta lo publicado en singular y en plural', function (): void {
    expect((new BulkPublishResult(['Taladro'], [], 0))->summary())->toBe('Publicaste 1 producto.');
    expect((new BulkPublishResult(['Taladro', 'Sierra', 'Lija'], [], 0))->summary())->toBe('Publicaste 3 productos.');
});

it('nombra cada producto que no se pudo publicar y lo que le falta', function (): void {
    $result = new BulkPublishResult(['Taladro', 'Sierra', 'Lija'], [
        ['name' => 'Mochila', 'reason' => 'le falta: al menos una foto'],
        ['name' => 'Lentes', 'reason' => 'le falta: la descripción'],
    ], 0);

    expect($result->summary())->toBe(
        'Publicaste 3 productos. 2 no se pudieron publicar: «Mochila» (le falta: al menos una foto) '
        .'y «Lentes» (le falta: la descripción).',
    );
});

it('dice cuando no se publicó nada', function (): void {
    $result = new BulkPublishResult([], [['name' => 'Mochila', 'reason' => 'le falta: una categoría']], 0);

    expect($result->summary())->toBe('No se publicó ningún producto. 1 no se pudo publicar: «Mochila» (le falta: una categoría).');
});

it('corta la lista de fallos y resume el resto', function (): void {
    $failed = array_map(
        fn (int $number): array => ['name' => 'Producto '.$number, 'reason' => 'le falta: la descripción'],
        range(1, BulkPublishResult::LISTED_FAILURES + 3),
    );

    $summary = (new BulkPublishResult([], $failed, 0))->summary();

    expect($summary)
        ->toContain('8 no se pudieron publicar')
        ->toContain('«Producto 5»')
        ->not->toContain('«Producto 6»')
        ->toEndWith('y 3 más.');
});

it('avisa de los que ya no estaban en borrador', function (): void {
    expect((new BulkPublishResult(['Taladro'], [], 1))->summary())
        ->toBe('Publicaste 1 producto. 1 ya no estaba en borrador y se quedó como estaba.');

    expect((new BulkPublishResult([], [], 2))->summary())
        ->toBe('No se publicó ningún producto. 2 ya no estaban en borrador y se quedaron como estaban.');
});
