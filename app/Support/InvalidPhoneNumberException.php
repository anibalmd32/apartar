<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class InvalidPhoneNumberException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('Escribe tu número de teléfono, como 0414-1234567.');
    }

    public static function unexpectedCharacters(): self
    {
        return new self('Escribe solo los números del teléfono, como 0414-1234567.');
    }

    public static function foreignCountry(): self
    {
        return new self('Por ahora solo aceptamos teléfonos de Venezuela: escríbelo con el +58 delante o sin él, como 0414-1234567.');
    }

    public static function wrongLength(): self
    {
        return new self('Un teléfono venezolano tiene 11 dígitos contando el 0 del principio, como 0414-1234567.');
    }

    public static function unknownAreaCode(string $areaCode): self
    {
        return new self(sprintf(
            'El código 0%s no es de un teléfono de Venezuela: los móviles empiezan por 04 y los fijos por 02.',
            $areaCode,
        ));
    }

    public static function notE164(string $value): self
    {
        return new self(sprintf(
            'El teléfono "%s" no está en E.164 venezolano (+58 y diez dígitos). Si viene de una persona, '
            .'constrúyelo con PhoneNumber::fromInput().',
            $value,
        ));
    }
}
