<?php

declare(strict_types=1);

use App\Domain\Catalog\CopywriterUnavailableException;

it('responde 429 con el cupo del día agotado', function (): void {
    $exception = CopywriterUnavailableException::quotaExhausted(30);

    expect($exception->status)->toBe(429);
    expect($exception->getMessage())->toBe('Ya usaste las 30 sugerencias de hoy. Vuelve mañana.');
});

it('concuerda el mensaje con un cupo de una sola sugerencia', function (): void {
    expect(CopywriterUnavailableException::quotaExhausted(1)->getMessage())
        ->toBe('Ya usaste la única sugerencia de hoy. Vuelve mañana.');
});

it('responde 503 cuando el proveedor falla y conserva la causa', function (): void {
    $cause = new RuntimeException('timeout');
    $exception = CopywriterUnavailableException::providerFailed($cause);

    expect($exception->status)->toBe(503);
    expect($exception->getMessage())->toBe('La IA no respondió. Intenta de nuevo en un momento.');
    expect($exception->getPrevious())->toBe($cause);
});

it('responde 503 cuando falta la clave del proveedor', function (): void {
    $exception = CopywriterUnavailableException::notConfigured();

    expect($exception->status)->toBe(503);
    expect($exception->getMessage())->toBe('La IA todavía no está configurada.');
});
