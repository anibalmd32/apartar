<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use DomainException;

/**
 * Un enlace de acceso que no abre nada (`REQ-IDN-003` SP-1, SP-2).
 *
 * **Una sola excepción para los tres casos** —caducado, ya canjeado, o que no
 * existe en absoluto (también con un prefijo de organización inventado)—,
 * porque SP-2 pide que sean **indistinguibles**: la persona ve la misma
 * pantalla con la misma salida, pedir otro, y quien pruebe tokens no aprende
 * cuáles existieron. El motivo viaja en `$reason`, solo para el log.
 *
 * El contrato de [10] nombraba `ExpiredLoginTokenException` y
 * `ConsumedLoginTokenException`; con un único tratamiento posible, dos tipos
 * solo habrían invitado a tratarlos distinto.
 */
final class UnusableLoginLinkException extends DomainException
{
    public const MESSAGE = 'Este enlace ya no sirve: caducó, ya se usó o no es correcto. Pide otro, o entra con tu cédula y el código de tu apartado.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGE);
    }
}
