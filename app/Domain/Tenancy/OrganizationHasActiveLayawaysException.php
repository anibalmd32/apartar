<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class OrganizationHasActiveLayawaysException extends RuntimeException
{
    public const NAMED = 10;

    /**
     * @param  list<string>  $codes
     */
    public function __construct(public readonly array $codes)
    {
        $named = array_slice($codes, 0, self::NAMED);
        $rest = count($codes) - count($named);

        parent::__construct(sprintf(
            'No puedes cerrar el negocio con apartados en curso. Complétalos o cancélalos primero: %s%s.',
            implode(', ', $named),
            $rest > 0 ? sprintf(' y %d más', $rest) : '',
        ));
    }
}
