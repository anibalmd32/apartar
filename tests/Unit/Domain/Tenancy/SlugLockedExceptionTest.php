<?php

declare(strict_types=1);

use App\Domain\Tenancy\SlugLockedException;

it('explica que rompería los enlaces ya compartidos', function (): void {
    expect(SlugLockedException::hasPublishedCatalogs('ferreteria-el-tornillo')->getMessage())
        ->toContain('ferreteria-el-tornillo')
        ->toContain('catálogos publicados')
        ->toContain('ya compartiste');
});

it('se responde con 422 y no con 403', function (): void {
    expect(SlugLockedException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(SlugLockedException::hasPublishedCatalogs('tienda'))->toBeInstanceOf(RuntimeException::class);
});
