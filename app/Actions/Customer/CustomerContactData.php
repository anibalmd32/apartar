<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Support\DocumentNumber;
use App\Support\InvalidDocumentNumberException;
use App\Support\InvalidPhoneNumberException;
use App\Support\PhoneNumber;
use InvalidArgumentException;

final readonly class CustomerContactData
{
    public const MAX_NAME_LENGTH = 200;

    public const MAX_NAME_PART_LENGTH = 100;

    public const MAX_EMAIL_LENGTH = 255;

    public string $fullName;

    public ?string $firstName;

    public ?string $lastName;

    public ?string $email;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public ?DocumentNumber $documentNumber,
        string $fullName,
        public ?PhoneNumber $phone,
        ?string $email,
        ?string $firstName = null,
        ?string $lastName = null,
    ) {
        $this->fullName = self::normaliseName($fullName);
        $this->firstName = $firstName === null ? null : self::normaliseName($firstName);
        $this->lastName = self::optionalName($lastName);
        $this->email = self::normaliseEmail($email);
    }

    /**
     * @throws InvalidDocumentNumberException
     * @throws InvalidPhoneNumberException
     * @throws InvalidArgumentException
     */
    public static function fromInput(?string $documentNumber, string $fullName, ?string $phone, ?string $email): self
    {
        return new self(self::document($documentNumber), $fullName, self::phone($phone), $email);
    }

    /**
     * @throws InvalidDocumentNumberException
     * @throws InvalidPhoneNumberException
     * @throws InvalidArgumentException
     */
    public static function fromParts(?string $documentNumber, string $firstName, ?string $lastName, ?string $phone, ?string $email): self
    {
        return new self(
            self::document($documentNumber),
            trim($firstName.' '.($lastName ?? '')),
            self::phone($phone),
            $email,
            $firstName,
            $lastName,
        );
    }

    private static function document(?string $documentNumber): ?DocumentNumber
    {
        $documentNumber = $documentNumber === null ? '' : trim($documentNumber);

        return $documentNumber === '' ? null : DocumentNumber::fromInput($documentNumber);
    }

    private static function phone(?string $phone): ?PhoneNumber
    {
        $phone = $phone === null ? '' : trim($phone);

        return $phone === '' ? null : PhoneNumber::fromInput($phone);
    }

    private static function optionalName(?string $name): ?string
    {
        $name = $name === null ? '' : trim($name);

        return $name === '' ? null : self::normaliseName($name);
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
