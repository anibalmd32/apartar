<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use RuntimeException;
use Throwable;

final class NotAcceptingLayawaysException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'Este negocio no puede recibir nuevos apartados en este momento.';

    private function __construct(Throwable $reason)
    {
        parent::__construct(self::MESSAGE, 0, $reason);
    }

    public static function because(Throwable $reason): self
    {
        return new self($reason);
    }
}
