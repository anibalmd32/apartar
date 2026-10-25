<?php

declare(strict_types=1);

use App\Domain\Tenancy\SlugTakenException;

it('lleva el slug pedido y una variante libre', function (): void {
    $exception = SlugTakenException::suggesting('ferreteria-el-tornillo', 'ferreteria-el-tornillo-2');

    expect($exception->slug)->toBe('ferreteria-el-tornillo');
    expect($exception->suggestion)->toBe('ferreteria-el-tornillo-2');

    expect($exception->getMessage())
        ->toContain('ferreteria-el-tornillo-2')
        ->toContain('libre');
});

it('distingue el reservado por la plataforma del ocupado por otro negocio', function (): void {
    $reserved = SlugTakenException::reserved('precios', 'precios-2');
    $taken = SlugTakenException::suggesting('tienda', 'tienda-2');

    expect($reserved->getMessage())->toContain('reservado por la plataforma');
    expect($reserved->getMessage())->not->toContain('otro negocio');

    expect($taken->getMessage())->toContain('otro negocio');
    expect($taken->getMessage())->not->toContain('reservado');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(SlugTakenException::suggesting('a', 'a-2'))->toBeInstanceOf(RuntimeException::class);
});
