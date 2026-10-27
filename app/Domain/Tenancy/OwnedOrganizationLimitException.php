<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class OwnedOrganizationLimitException extends RuntimeException
{
    public static function reached(int $limit): self
    {
        return new self($limit === 1
            ? 'Ya tienes un negocio propio. Por ahora cada persona puede tener uno solo.'
            : sprintf('Ya tienes %d negocios propios, el máximo por persona.', $limit));
    }
}
