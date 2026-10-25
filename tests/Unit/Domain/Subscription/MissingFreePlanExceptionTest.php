<?php

declare(strict_types=1);

use App\Domain\Subscription\MissingFreePlanException;

it('explica cómo sembrar el catálogo de planes', function (): void {
    $exception = MissingFreePlanException::withCode('free');

    expect($exception->getMessage())
        ->toContain('free')
        ->toContain('plans')
        ->toContain('PlanSeeder');
});

it('nombra el plan que buscaba', function (): void {
    expect(MissingFreePlanException::withCode('gratuito')->getMessage())->toContain('gratuito');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(MissingFreePlanException::withCode('free'))->toBeInstanceOf(RuntimeException::class);
});
