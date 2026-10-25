<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class InvalidDocumentNumberException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('Escribe el número de tu cédula o RIF.');
    }

    public static function unexpectedCharacters(): self
    {
        return new self('Escribe solo la letra y los números del documento, como V-12.345.678 o J-12345678-9.');
    }

    public static function unknownPrefix(string $letter): self
    {
        return new self(sprintf(
            'Un documento empieza por V o E si es una cédula, o por J o G si es un RIF, y "%s" no es ninguna de ellas.',
            $letter,
        ));
    }

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
