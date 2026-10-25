<?php

declare(strict_types=1);

namespace App\Support;

final readonly class DocumentNumber
{
    private const SEPARATORS = '/[\s.\p{Pd}]+/u';

    public DocumentKind $kind;

    /**
     * @throws InvalidDocumentNumberException
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

    public function prefix(): string
    {
        return $this->value[0];
    }

    public function digits(): string
    {
        return substr($this->value, 1);
    }

    public function masked(): string
    {
        $formatted = $this->formatted();
        [$prefix, $body] = explode('-', $formatted, 2);

        if ($this->kind === DocumentKind::Cedula && substr_count($body, '.') === 2) {
            $groups = explode('.', $body);

            return $prefix.'-'.$groups[0].'.***.'.$groups[2];
        }

        $keep = max(1, intdiv(strlen($body), 3));

        return $prefix.'-'.substr($body, 0, $keep).str_repeat('*', max(1, strlen($body) - 2 * $keep)).substr($body, -$keep);
    }

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
