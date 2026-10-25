<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class OrganizationNotActiveException extends RuntimeException
{
    public const CLOSED_MESSAGE = 'Este negocio cerró su cuenta en Apartar y su backoffice ya no está disponible.';

    public static function closed(string $organizationName): self
    {
        return new self(sprintf('%s (%s)', self::CLOSED_MESSAGE, $organizationName));
    }
}
