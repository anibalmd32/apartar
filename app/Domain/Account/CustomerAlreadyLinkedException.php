<?php

declare(strict_types=1);

namespace App\Domain\Account;

use DomainException;

final class CustomerAlreadyLinkedException extends DomainException
{
    public const STATUS = 422;

    public const TO_ANOTHER_ACCOUNT = 'Ese apartado ya está conectado a otra cuenta de Google. Escríbele al negocio para revisarlo.';

    public const ACCOUNT_HAS_ANOTHER_RECORD = 'Tu cuenta ya tiene otra ficha en ese negocio. Escríbele al negocio si crees que es un error.';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function toAnotherAccount(): self
    {
        return new self('ficha vinculada a otra cuenta', self::TO_ANOTHER_ACCOUNT);
    }

    public static function accountHasAnotherRecord(): self
    {
        return new self('la cuenta ya tiene otra ficha en la organización', self::ACCOUNT_HAS_ANOTHER_RECORD);
    }
}
