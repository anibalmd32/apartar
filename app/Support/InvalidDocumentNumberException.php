<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Un documento que no se puede guardar (`REQ-IDN-007` SP-1, SP-2).
 *
 * **Sus mensajes son para la persona que lo escribió**, y `ValidDocumentNumber`
 * los enseña tal cual en el `422`. Quien teclea su cédula en un formulario de
 * apartado está a punto de comprar, así que cada mensaje dice **qué
 * arreglar** —el rango, las letras que valen— y no que algo «no es válido».
 */
final class InvalidDocumentNumberException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('Escribe el número de tu cédula o RIF.');
    }

    /**
     * Quedó algo que no es ni la letra ni un dígito.
     */
    public static function unexpectedCharacters(): self
    {
        return new self('Escribe solo la letra y los números del documento, como V-12.345.678 o J-12345678-9.');
    }

    /**
     * SP-2: una letra que no es de ningún documento que se acepte.
     */
    public static function unknownPrefix(string $letter): self
    {
        return new self(sprintf(
            'Un documento empieza por V o E si es una cédula, o por J o G si es un RIF, y "%s" no es ninguna de ellas.',
            $letter,
        ));
    }

    /**
     * SP-1: fuera del rango, **con el rango escrito**.
     *
     * A una cédula de más dígitos de la cuenta se le añade la salida de RB-2:
     * es el aspecto que tiene un RIF escrito sin su letra, que sin letra se
     * lee como cédula.
     */
    public static function digitCount(DocumentKind $kind, int $count): self
    {
        $message = match ($kind) {
            DocumentKind::Cedula => sprintf('Una cédula tiene entre %d y %d dígitos, y aquí hay %d.', $kind->minDigits(), $kind->maxDigits(), $count),
            DocumentKind::Rif => sprintf('Un RIF tiene entre %d y %d dígitos contando el verificador del final, y aquí hay %d.', $kind->minDigits(), $kind->maxDigits(), $count),
        };

        if ($kind === DocumentKind::Cedula && $count > $kind->maxDigits()) {
            $message .= ' Si es el RIF de una empresa, escríbelo con su letra delante (J o G): sin letra, el número se lee como cédula.';
        }

        return new self($message);
    }
}
