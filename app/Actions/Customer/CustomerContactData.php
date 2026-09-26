<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Support\DocumentNumber;
use App\Support\InvalidDocumentNumberException;
use App\Support\InvalidPhoneNumberException;
use App\Support\PhoneNumber;
use InvalidArgumentException;

/**
 * Quién dice ser el cliente, para este negocio (`REQ-IDN-001`).
 *
 * Los cuatro campos del contrato y nada más. **El consentimiento no está aquí,
 * y es deliberado**: no lo declara la persona, lo captura el servidor, y viaja
 * como `TermsConsent` en un parámetro aparte y obligatorio de
 * `ResolveCustomerAction` (razonado en esa clase y bajo `REQ-IDN-001` en
 * [10-identidad-y-autenticacion.md]).
 *
 * ## Lo que el tipo ya garantiza
 *
 *  - **El documento llega canónico** (`REQ-IDN-007` RB-3): es un
 *    `DocumentNumber`, y no existe uno sin normalizar. Por eso `12.345.678` y
 *    `V12345678` encuentran la misma ficha: la acción busca por `value`.
 *  - **El teléfono es obligatorio** (RB-2): el tipo no admite `null`, y un
 *    `PhoneNumber` no se construye con algo que no lo sea. No hay flujo que lo
 *    omita, que es lo que dice SP-2.
 *  - **El correo es opcional** (RB-3, SP-7): `null` es una ficha normal, no una
 *    degradada. Solo se valida su forma (SP-6).
 *
 * El nombre se guarda con los espacios colapsados: `María  González ` y
 * `María González` son la misma persona escrita con prisa, y compararlos tal
 * cual haría que la reutilización (RB-4) auditara un cambio que no existe.
 */
final readonly class CustomerContactData
{
    /** Lo que cabe en `customers.full_name`. */
    public const MAX_NAME_LENGTH = 200;

    /** Lo que cabe en `customers.email`. */
    public const MAX_EMAIL_LENGTH = 255;

    public string $fullName;

    public ?string $email;

    /**
     * @throws InvalidArgumentException si el nombre queda vacío o no cabe, o el correo no tiene forma de correo
     */
    public function __construct(
        public DocumentNumber $documentNumber,
        string $fullName,
        public PhoneNumber $phone,
        ?string $email,
    ) {
        $this->fullName = self::normaliseName($fullName);
        $this->email = self::normaliseEmail($email);
    }

    /**
     * El contacto tal como lo escribió una persona.
     *
     * Usa **las puertas que lanzan** de `T-040`: un documento o un teléfono mal
     * escritos no son un contacto a medias, son un `422` (SP-1, SP-2). Las que
     * devuelven `null` son del buscador del backoffice (`T-065`), donde medio
     * documento es una búsqueda y no un error.
     *
     * @throws InvalidDocumentNumberException
     * @throws InvalidPhoneNumberException
     * @throws InvalidArgumentException
     */
    public static function fromInput(string $documentNumber, string $fullName, string $phone, ?string $email): self
    {
        return new self(
            DocumentNumber::fromInput($documentNumber),
            $fullName,
            PhoneNumber::fromInput($phone),
            $email,
        );
    }

    private static function normaliseName(string $fullName): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $fullName));

        if ($name === '') {
            throw new InvalidArgumentException('Una ficha de cliente necesita un nombre.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('El nombre del cliente admite %d caracteres.', self::MAX_NAME_LENGTH));
        }

        return $name;
    }

    /**
     * Vacío es «sin correo», no un correo vacío: el formulario manda `''`
     * cuando el campo opcional se deja en blanco.
     */
    private static function normaliseEmail(?string $email): ?string
    {
        $email = $email === null ? '' : trim($email);

        if ($email === '') {
            return null;
        }

        if (mb_strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('«%s» no tiene forma de correo.', $email));
        }

        return $email;
    }
}
