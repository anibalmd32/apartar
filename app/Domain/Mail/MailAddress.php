<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final readonly class MailAddress
{
    public function __construct(public string $email, public ?string $name = null) {}

    public static function parse(mixed $value): ?self
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(.*)<\s*([^<>\s]+@[^<>\s]+)\s*>\s*$/u', $value, $matches) === 1) {
            $name = trim($matches[1], " \t\"'");

            return new self(strtolower($matches[2]), $name === '' ? null : $name);
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : new self(strtolower($value));
    }

    /**
     * @return list<string>
     */
    public static function emails(mixed $values): array
    {
        $list = is_string($values) ? (preg_split('/[,;]/', $values) ?: []) : (is_array($values) ? $values : []);
        $emails = [];

        foreach ($list as $value) {
            $address = self::parse($value);

            if ($address !== null && ! in_array($address->email, $emails, true)) {
                $emails[] = $address->email;
            }
        }

        return $emails;
    }

    public function display(): string
    {
        return $this->name === null ? $this->email : sprintf('%s <%s>', $this->name, $this->email);
    }
}
