<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Una cédula o un RIF venezolanos, en su forma canónica (`REQ-IDN-007`).
 *
 * **Es la clave por la que un negocio encuentra a su cliente**
 * —`UNIQUE(organization_id, document_number)`— y la mitad de la credencial
 * del portal (`REQ-IDN-008` RB-2). Si la misma persona pudiera quedar guardada
 * de dos formas, el negocio tendría dos fichas del mismo cliente con dos
 * saldos, y eso no se arregla después. De ahí que solo haya **una** forma de
 * guardarlo, y que todo lo demás sea entrada o presentación.
 *
 * ## Canónico y presentación son dos cosas
 *
 * `value` es lo que viaja a la base y al índice: letra en mayúscula y dígitos,
 * sin separadores —`V12345678`, `J123456789`— (RB-3). `formatted()` es lo que
 * se enseña —`V-12.345.678`, `J-12345678-9`— y **no se parsea nunca** para
 * volver atrás: quien tenga la presentación y necesite la clave tiene que
 * haberse quedado con el `value`.
 *
 * ## Los ceros a la izquierda se conservan
 *
 * RB-7: `V-0012345` no es `V-12345`. Recortarlos colapsaría dos documentos
 * distintos en uno, y el efecto no sería un error sino dos personas
 * compartiendo ficha, saldo y apartados bajo un índice único que además
 * impediría crear la segunda. Por eso **el número es una cadena de principio a
 * fin**: aquí no hay un `(int)`, ni un `ltrim(…, '0')`, ni un `intval` para
 * medir, y los dígitos se cuentan con `strlen`. «6 a 9 dígitos» (RB-1) se
 * cuenta sobre la cadena, así que `0012345` son siete y es válida.
 *
 * ## Dos puertas
 *
 * El constructor y `fromInput()` **lanzan** (RB-6): no existe un
 * `DocumentNumber` en estado inválido, y un documento mal escrito en un
 * formulario es un `422` con su mensaje. `tryFromInput()` **devuelve `null`**:
 * es la puerta para preguntar, la de la búsqueda del backoffice (RB-5,
 * `REQ-BOF-040`), donde teclear medio documento no es un error sino una
 * búsqueda que se degrada a `LIKE` (SP-6). Las dos pasan por el mismo
 * constructor, así que no pueden opinar distinto sobre qué es un documento.
 */
final readonly class DocumentNumber
{
    /**
     * Lo que se ignora al leer la entrada (RB-1): espacios, puntos y guiones.
     *
     * Con `u` para que «espacio» incluya el no separable y «guion» cualquier
     * guion tipográfico (`\p{Pd}`): un documento copiado de un PDF o de un
     * mensaje corregido por el teléfono trae `–` o un espacio duro, y
     * rechazarlo por eso sería rechazar un dato correcto por cómo se pegó.
     */
    private const SEPARATORS = '/[\s.\p{Pd}]+/u';

    public DocumentKind $kind;

    /**
     * @param  string  $value  la forma canónica: letra y dígitos, sin separadores
     *
     * @throws InvalidDocumentNumberException si no lo es
     */
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw InvalidDocumentNumberException::empty();
        }

        if (preg_match('/^([A-Z])(\d*)$/', $value, $matches) !== 1) {
            throw InvalidDocumentNumberException::unexpectedCharacters();
        }

        $kind = DocumentKind::fromPrefix($matches[1])
            ?? throw InvalidDocumentNumberException::unknownPrefix($matches[1]);

        $digits = strlen($matches[2]);

        if ($digits < $kind->minDigits() || $digits > $kind->maxDigits()) {
            throw InvalidDocumentNumberException::digitCount($kind, $digits);
        }

        $this->kind = $kind;
    }

    /**
     * El documento tal como lo escribió una persona.
     *
     * Recorta, quita los separadores de RB-1 y pasa la letra a mayúscula.
     * **Sin letra es cédula venezolana** (`V`), nunca RIF: el prefijo del RIF
     * es obligatorio (RB-2, SP-3), porque un `J` que falta convierte una
     * empresa en una persona y el tipo es parte de lo que se guarda.
     *
     * @throws InvalidDocumentNumberException
     */
    public static function fromInput(string $raw): self
    {
        $compact = strtoupper((string) preg_replace(self::SEPARATORS, '', $raw));

        if ($compact === '') {
            throw InvalidDocumentNumberException::empty();
        }

        if (ctype_digit($compact[0])) {
            $compact = DocumentKind::Cedula->prefixes()[0].$compact;
        }

        return new self($compact);
    }

    /**
     * El documento, o `null` si lo escrito no es uno (RB-5, SP-6).
     *
     * Delega en `fromInput()` en lugar de repetir sus reglas, como
     * `FinancingPlan::tryFrom()`: la búsqueda y el formulario no pueden acabar
     * opinando cosas distintas sobre qué es un documento. Admite cualquier
     * cosa porque es la puerta de lo que llega de una caja de búsqueda.
     */
    public static function tryFromInput(mixed $raw): ?self
    {
        if (! is_string($raw)) {
            return null;
        }

        try {
            return self::fromInput($raw);
        } catch (InvalidDocumentNumberException) {
            return null;
        }
    }

    /**
     * La letra: `V`, `E`, `J` o `G`.
     */
    public function prefix(): string
    {
        return $this->value[0];
    }

    /**
     * Los dígitos, como cadena y con sus ceros a la izquierda.
     */
    public function digits(): string
    {
        return substr($this->value, 1);
    }

    /**
     * Cómo se enseña (RB-3): `V-12.345.678`, `J-12345678-9`.
     *
     * La cédula agrupa de tres en tres **desde la derecha y sobre la cadena**,
     * así que `V0012345` se enseña `V-0.012.345` y no pierde sus ceros por el
     * camino. El RIF separa el último dígito como verificador cuando hay más
     * de ocho, que es la forma en que se escribe; no se comprueba que lo sea
     * (RB-8), y como esta forma nunca se vuelve a leer, un RIF de nueve dígitos
     * sin verificador se enseña igual sin que se guarde nada distinto.
     */
    public function formatted(): string
    {
        $digits = $this->digits();

        $body = match ($this->kind) {
            DocumentKind::Cedula => strrev(implode('.', str_split(strrev($digits), 3))),
            DocumentKind::Rif => strlen($digits) > 8
                ? substr($digits, 0, -1).'-'.substr($digits, -1)
                : $digits,
        };

        return $this->prefix().'-'.$body;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
