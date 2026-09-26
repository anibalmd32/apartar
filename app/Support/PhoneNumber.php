<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Un teléfono venezolano en E.164 (`REQ-IDN-007` RB-4).
 *
 * Es obligatorio en toda ficha (`REQ-IDN-001` RB-2) porque es el canal real
 * entre el negocio y su cliente, y el backoffice busca por él
 * (`REQ-BOF-040` RB-1). `04121234567`, `4121234567` y `+584121234567` son el
 * mismo número y se guardan igual, `+584121234567`: tres grafías de un teléfono
 * no pueden ser tres teléfonos.
 *
 * ## Dos puertas, como `DocumentNumber`
 *
 * El constructor y `fromInput()` lanzan (RB-6); `tryFromInput()` devuelve
 * `null` para la búsqueda, donde lo que no es un teléfono se degrada a `LIKE`
 * (SP-6) en vez de romper.
 *
 * ## Qué es un código válido: la forma, no el censo
 *
 * **Desde `T-045`, la aceptación valida la forma** (decidido bajo
 * `REQ-IDN-007`, supersede RB-4 en lo que tiene de «válidos» y SP-7): `+58`,
 * diez dígitos, y el código empezando por `2` —fijo— o por `4` —móvil—. El
 * censo de prefijos es el plan de numeración de CONATEL, que cambia sin
 * avisarnos y en la dirección que nos rompe —los rangos se asignan, no se
 * retiran—, y como puerta fallaba hacia el lado caro: rechazar un `0422` real
 * deja a un cliente sin poder apartar y no deja rastro en ningún log; aceptar
 * un rango sin asignar guarda una errata que se nota al llamar.
 *
 * Los censos **se quedan**, pero como etiqueta: `isMobile()` decide si se
 * ofrece WhatsApp o SMS, e `isRecognised()` deja que el formulario avise sin
 * bloquear («no reconocemos ese prefijo, ¿es correcto?», `T-045b`). Viven solo
 * aquí: la máscara del frontend no los conoce, porque una máscara no rechaza
 * (ver `identity-format.ts`), y `ValidPaymentIdentifier` lee `MOBILE_PREFIXES`
 * de esta clase en vez de llevar su copia.
 */
final readonly class PhoneNumber
{
    public const COUNTRY_CODE = '58';

    /**
     * Operadoras móviles, sin el cero nacional: las cinco de RB-4 más `415` y
     * `422`, que entraron con `T-045` (Digitel opera también el `0422`).
     *
     * Ya no deciden qué se acepta, sino qué es un móvil: `isMobile()`.
     *
     * @var non-empty-list<string>
     */
    public const MOBILE_PREFIXES = ['412', '414', '415', '416', '422', '424', '426'];

    /**
     * Primer dígito de un código nacional válido: `2` los fijos, `4` los
     * móviles. Es la forma que se valida.
     *
     * @var non-empty-list<string>
     */
    private const ACCEPTED_LEADING_DIGITS = ['2', '4'];

    /**
     * Códigos geográficos del plan de numeración, sin el cero nacional.
     *
     * Solo responden `isRecognised()`: un fijo con un código que no está aquí
     * se acepta igual si empieza por `2`. `260` y `270` son las zonas
     * binacionales de la frontera con Colombia.
     *
     * @var list<string>
     */
    private const LANDLINE_AREA_CODES = [
        '212',
        '234', '235', '237', '238', '239',
        '241', '242', '243', '244', '245', '246', '247', '248', '249',
        '251', '252', '253', '254', '255', '256', '257', '258', '259',
        '260', '261', '262', '263', '264', '265', '266', '267', '268', '269',
        '270', '271', '272', '273', '274', '275', '276', '277', '278',
        '281', '282', '283', '285', '286', '287', '288',
        '291', '292', '293', '294', '295',
    ];

    /**
     * Lo que se ignora al leer la entrada: espacios, puntos, guiones y
     * paréntesis, que es como se escribe un teléfono —`(0414) 123-45.67`—.
     */
    private const SEPARATORS = '/[\s.()\p{Pd}]+/u';

    /** Dígitos nacionales: código de tres y abonado de siete. */
    private const NATIONAL_DIGITS = 10;

    /**
     * @param  string  $value  E.164: `+58` y diez dígitos
     *
     * @throws InvalidPhoneNumberException
     */
    public function __construct(public string $value)
    {
        if (preg_match('/^\+'.self::COUNTRY_CODE.'(\d{'.self::NATIONAL_DIGITS.'})$/', $value, $matches) !== 1) {
            throw InvalidPhoneNumberException::notE164($value);
        }

        // La forma, no el censo: ver el bloque de la clase.
        if (! in_array($matches[1][0], self::ACCEPTED_LEADING_DIGITS, true)) {
            throw InvalidPhoneNumberException::unknownAreaCode(substr($matches[1], 0, 3));
        }
    }

    /**
     * El teléfono tal como lo escribió una persona.
     *
     * Admite el nacional con su cero (`04121234567`), sin él (`4121234567`) y
     * el internacional (`+584121234567`, también `584121234567`), con
     * cualquier separador de `SEPARATORS`. Un `+` que no va seguido de `58` es
     * SP-5.
     *
     * @throws InvalidPhoneNumberException
     */
    public static function fromInput(string $raw): self
    {
        $compact = (string) preg_replace(self::SEPARATORS, '', $raw);

        if ($compact === '') {
            throw InvalidPhoneNumberException::empty();
        }

        $international = str_starts_with($compact, '+');
        $digits = $international ? substr($compact, 1) : $compact;

        if ($digits === '' || ! ctype_digit($digits)) {
            throw InvalidPhoneNumberException::unexpectedCharacters();
        }

        if ($international && ! str_starts_with($digits, self::COUNTRY_CODE)) {
            throw InvalidPhoneNumberException::foreignCountry();
        }

        $national = $digits;

        if ($international || (strlen($digits) === self::NATIONAL_DIGITS + 2 && str_starts_with($digits, self::COUNTRY_CODE))) {
            $national = substr($digits, strlen(self::COUNTRY_CODE));
        }

        // El cero nacional, también detrás del `+58`: hay quien escribe
        // `+58 0414…`, y es el mismo número. Con cero delante y otra
        // longitud es un dígito de más o de menos, no un código `004…`.
        if (str_starts_with($national, '0')) {
            if (strlen($national) !== self::NATIONAL_DIGITS + 1) {
                throw InvalidPhoneNumberException::wrongLength();
            }

            $national = substr($national, 1);
        }

        if (strlen($national) !== self::NATIONAL_DIGITS) {
            throw InvalidPhoneNumberException::wrongLength();
        }

        return new self('+'.self::COUNTRY_CODE.$national);
    }

    /**
     * El teléfono, o `null` si lo escrito no es uno (SP-6).
     */
    public static function tryFromInput(mixed $raw): ?self
    {
        if (! is_string($raw)) {
            return null;
        }

        try {
            return self::fromInput($raw);
        } catch (InvalidPhoneNumberException) {
            return null;
        }
    }

    /**
     * El código de tres dígitos, sin el cero: `414`, `212`.
     */
    public function areaCode(): string
    {
        return substr($this->value, 1 + strlen(self::COUNTRY_CODE), 3);
    }

    /**
     * Si es de una operadora móvil (RB-4), que es lo que admite WhatsApp y
     * pago móvil.
     */
    public function isMobile(): bool
    {
        return in_array($this->areaCode(), self::MOBILE_PREFIXES, true);
    }

    /**
     * Si el código está en el censo —una operadora móvil o una zona del
     * país—. **No decide si se acepta**: eso lo decide la forma. Es la pregunta
     * del aviso que no bloquea de `T-045b`.
     */
    public function isRecognised(): bool
    {
        return $this->isMobile() || in_array($this->areaCode(), self::LANDLINE_AREA_CODES, true);
    }

    /**
     * Cómo se enseña: la grafía nacional, `0414-1234567`, que es como lo
     * escribe y lo lee quien lo marca.
     */
    public function formatted(): string
    {
        $national = substr($this->value, 1 + strlen(self::COUNTRY_CODE));

        return '0'.substr($national, 0, 3).'-'.substr($national, 3);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
