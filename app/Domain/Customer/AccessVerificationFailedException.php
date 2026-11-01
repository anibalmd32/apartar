<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use DomainException;

final class AccessVerificationFailedException extends DomainException
{
    public const DOCUMENT_MISSING = 'falta el documento';

    public const DOCUMENT_MISSING_MESSAGE = 'Escribe tu cédula o RIF junto con el código.';

    public const MESSAGE = 'No encontramos un apartado con esa cédula y ese código. Revisa los dos datos: el código está en tu confirmación y empieza por AP-.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason === self::DOCUMENT_MISSING ? self::DOCUMENT_MISSING_MESSAGE : self::MESSAGE);
    }
}
