<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use RuntimeException;

/**
 * Una fusión de fichas que no se puede hacer (`REQ-IDN-006`). Se comprueba
 * antes de mover nada, bajo el bloqueo de las dos fichas: si lanza, ninguna
 * queda tocada (SP-6). El controlador la devuelve al formulario.
 */
final class CannotMergeCustomerException extends RuntimeException
{
    public const STATUS = 422;

    /** RB-7 · una ficha consigo misma. */
    public static function sameRecord(): self
    {
        return new self('No se puede fusionar una ficha consigo misma.');
    }

    /** SP-1 · una de las dos ya no existe —o la absorbió otra fusión en curso—. */
    public static function gone(): self
    {
        return new self('Una de las dos fichas ya no existe: puede que otra fusión se la haya llevado. Recarga el perfil.');
    }

    /** RB-1 · fichas de dos negocios distintos son personas distintas para cada uno. */
    public static function otherOrganization(): self
    {
        return new self('Solo se fusionan fichas de tu propio negocio.');
    }

    /** RB-4, SP-2 · fusionar dejaría a una persona sin acceso a su deuda. */
    public static function bothLinked(): self
    {
        return new self('Las dos fichas están vinculadas a cuentas distintas: fusionarlas dejaría a una persona sin acceso a sus apartados. Escribe a soporte para resolverlo.');
    }

    /** RB-8 · la confirmación no es el documento de la ficha absorbida. */
    public static function unconfirmed(): self
    {
        return new self('Para confirmar, escribe el documento de la ficha que se va a absorber.');
    }
}
