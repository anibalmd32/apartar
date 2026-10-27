<?php

declare(strict_types=1);

namespace App\Support;

final readonly class PhoneNumber
{
    public const COUNTRY_CODE = '58';

    /**
     * @var non-empty-list<string>
     */
    public const MOBILE_PREFIXES = ['412', '414', '416', '422', '424', '426'];

    /**
     * @var non-empty-list<string>
     */
    private const ACCEPTED_LEADING_DIGITS = ['2', '4'];

    /**
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

    private const SEPARATORS = '/[\s.()\p{Pd}]+/u';

    private const NATIONAL_DIGITS = 10;

    /**
     * @throws InvalidPhoneNumberException
     */
    public function __construct(public string $value)
    {
        if (preg_match('/^\+'.self::COUNTRY_CODE.'(\d{'.self::NATIONAL_DIGITS.'})$/', $value, $matches) !== 1) {
            throw InvalidPhoneNumberException::notE164($value);
        }

        if (! in_array($matches[1][0], self::ACCEPTED_LEADING_DIGITS, true)) {
            throw InvalidPhoneNumberException::unknownAreaCode(substr($matches[1], 0, 3));
        }
    }

    /**
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

    public function areaCode(): string
    {
        return substr($this->value, 1 + strlen(self::COUNTRY_CODE), 3);
    }

    public function isMobile(): bool
    {
        return in_array($this->areaCode(), self::MOBILE_PREFIXES, true);
    }

    public function isRecognised(): bool
    {
        return in_array($this->areaCode(), self::recognisedAreaCodes(), true);
    }

    /**
     * @return list<string>
     */
    public static function recognisedAreaCodes(): array
    {
        return [...self::MOBILE_PREFIXES, ...self::LANDLINE_AREA_CODES];
    }

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
