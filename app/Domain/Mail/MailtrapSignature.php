<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final class MailtrapSignature
{
    public const HEADER = 'Mailtrap-Signature';

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, ?string $signature, ?string $secret): bool
    {
        if ($secret === null || $secret === '' || $signature === null || $signature === '' || $body === '') {
            return false;
        }

        return hash_equals(self::sign($body, $secret), strtolower(trim($signature)));
    }
}
