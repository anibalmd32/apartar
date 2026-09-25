<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Qué clase de documento identifica a un cliente (`REQ-IDN-007` RB-1, RB-2).
 *
 * Se persiste en `customers.document_kind` como `VARCHAR` ([02 §5]) y **es
 * parte de lo que se guarda**, no una etiqueta derivada: una cédula es una
 * persona y un RIF es una empresa o un ente (`REQ-IDN-001` RB-7), y
 * confundirlos cambia la naturaleza del cliente.
 *
 * El censo de las letras y de los rangos vive aquí, en el tipo, y no en quien
 * lo consulta: `DocumentNumber` lo lee para validar y para presentar, y la
 * máscara del frontend recorre la misma tabla de casos que él
 * (`identity-format.cases.json`), que es lo que impide que las dos opinen
 * distinto sobre qué letra es un RIF.
 */
enum DocumentKind: string
{
    case Cedula = 'cedula';
    case Rif = 'rif';

    /**
     * Letras con las que empieza este documento.
     *
     * La primera de la cédula es también la que se pone cuando no se escribe
     * ninguna (RB-1): sin letra es cédula venezolana, **nunca** RIF (RB-2,
     * SP-3). Esa asimetría es deliberada y la decide `DocumentNumber`, no
     * esta lista.
     *
     * @return non-empty-list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Cedula => ['V', 'E'],
            self::Rif => ['J', 'G'],
        };
    }

    /**
     * Dígitos que admite, contados **sobre la cadena** y con los ceros a la
     * izquierda incluidos (RB-7).
     *
     * El RIF va de 8 a 10 porque RB-2 dice «8 a 9 dígitos + dígito
     * verificador opcional», y sin separadores el verificador es un dígito
     * más. No se comprueba (RB-8).
     */
    public function minDigits(): int
    {
        return match ($this) {
            self::Cedula => 6,
            self::Rif => 8,
        };
    }

    public function maxDigits(): int
    {
        return match ($this) {
            self::Cedula => 9,
            self::Rif => 10,
        };
    }

    /**
     * Cómo se nombra en un mensaje para una persona.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cedula => 'cédula',
            self::Rif => 'RIF',
        };
    }

    /**
     * El tipo al que pertenece esa letra, o `null` si no es de ninguno.
     */
    public static function fromPrefix(string $letter): ?self
    {
        foreach (self::cases() as $kind) {
            if (in_array($letter, $kind->prefixes(), true)) {
                return $kind;
            }
        }

        return null;
    }
}
