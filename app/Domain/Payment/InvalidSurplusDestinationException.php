<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use RuntimeException;

final class InvalidSurplusDestinationException extends RuntimeException
{
    public const STATUS = 422;

    public static function noSurplus(): self
    {
        return new self('Este pago no tiene sobrante: no hay nada que asignar.');
    }

    public static function layawayClosed(LayawayStatus $status): self
    {
        return new self(sprintf('El apartado está «%s»: su historial se conserva, pero su sobrante ya no se cambia.', mb_strtolower($status->label())));
    }

    public static function missingNote(): self
    {
        return new self('El destino «Otro» necesita una nota corta que diga qué se hizo con el sobrante.');
    }

    public static function longNote(): self
    {
        return new self(sprintf('La nota del destino tiene como mucho %d caracteres.', SurplusDestination::NOTE_MAX));
    }

    public static function unknownMember(): self
    {
        return new self('Elige a qué persona del equipo va el sobrante.');
    }

    public static function foreignMember(): self
    {
        return new self('Esa persona no es del equipo de este negocio.');
    }

    public static function inactiveMember(): self
    {
        return new self('Ese miembro del equipo ya no tiene acceso: elige otra persona.');
    }
}
