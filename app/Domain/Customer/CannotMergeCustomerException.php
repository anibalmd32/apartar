<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use RuntimeException;

final class CannotMergeCustomerException extends RuntimeException
{
    public const STATUS = 422;

    public static function sameRecord(): self
    {
        return new self('No se puede fusionar una ficha consigo misma.');
    }

    public static function gone(): self
    {
        return new self('Una de las dos fichas ya no existe: puede que otra fusión se la haya llevado. Recarga el perfil.');
    }

    public static function otherOrganization(): self
    {
        return new self('Solo se fusionan fichas de tu propio negocio.');
    }

    public static function bothLinked(): self
    {
        return new self('Las dos fichas están vinculadas a cuentas distintas: fusionarlas dejaría a una persona sin acceso a sus apartados. Escribe a soporte para resolverlo.');
    }

    public static function unconfirmed(): self
    {
        return new self('Para confirmar, escribe el documento de la ficha que se va a absorber.');
    }
}
