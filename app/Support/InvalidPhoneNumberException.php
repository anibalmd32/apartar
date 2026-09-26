<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Un teléfono que no se puede guardar (`REQ-IDN-007` RB-4, SP-5, SP-7).
 *
 * Igual que los de `InvalidDocumentNumberException`, sus mensajes son para
 * quien lo escribió y `ValidVenezuelanPhone` los enseña tal cual: dicen cómo
 * se escribe uno bueno, con un ejemplo, porque el teléfono es obligatorio
 * (`REQ-IDN-001` RB-2) y sin él no hay apartado.
 */
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

    /**
     * SP-5: un prefijo internacional que no es `+58`.
     */
    public static function foreignCountry(): self
    {
        return new self('Por ahora solo aceptamos teléfonos de Venezuela: escríbelo con el +58 delante o sin él, como 0414-1234567.');
    }

    /**
     * La longitud no es la de un teléfono.
     *
     * Sin la cuenta de lo escrito, a diferencia del documento: con tres
     * grafías válidas —con cero, sin él, con `+58`— «aquí hay 10» es correcto
     * para una y un error para otra, y un número que confunde es peor que
     * ninguno. Lo que se da es la forma entera, que es lo que se copia.
     */
    public static function wrongLength(): self
    {
        return new self('Un teléfono venezolano tiene 11 dígitos contando el 0 del principio, como 0414-1234567.');
    }

    /**
     * Un código que no tiene forma de teléfono venezolano: ni empieza por `2`
     * ni por `4` (`0500`, `0800`). Desde `T-045` es la forma lo que se
     * rechaza, no la ausencia en el censo (ver `PhoneNumber`).
     */
    public static function unknownAreaCode(string $areaCode): self
    {
        return new self(sprintf(
            'El código 0%s no es de un teléfono de Venezuela: los móviles empiezan por 04 y los fijos por 02.',
            $areaCode,
        ));
    }

    /**
     * La forma canónica no lo es: esto no lo escribe una persona, lo escribe
     * código que construyó el VO sin pasar por `fromInput()`.
     */
    public static function notE164(string $value): self
    {
        return new self(sprintf(
            'El teléfono "%s" no está en E.164 venezolano (+58 y diez dígitos). Si viene de una persona, '
            .'constrúyelo con PhoneNumber::fromInput().',
            $value,
        ));
    }
}
