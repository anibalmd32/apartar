<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;
use Throwable;

final class CopywriterUnavailableException extends RuntimeException
{
    private function __construct(public readonly int $status, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(503, 'La IA todavía no está configurada.');
    }

    public static function quotaExhausted(int $limit): self
    {
        return new self(429, sprintf(
            'Ya usaste %s de hoy. Vuelve mañana.',
            $limit === 1 ? 'la única sugerencia' : sprintf('las %d sugerencias', $limit),
        ));
    }

    public static function providerFailed(?Throwable $previous = null): self
    {
        return new self(503, 'La IA no respondió. Intenta de nuevo en un momento.', $previous);
    }
}
