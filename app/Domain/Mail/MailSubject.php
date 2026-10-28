<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final class MailSubject
{
    public const MAX_LENGTH = 255;

    public static function reply(string $subject): string
    {
        return self::prefixed('Re: ', $subject);
    }

    public static function forward(string $subject): string
    {
        return self::prefixed('Rv: ', $subject);
    }

    private static function prefixed(string $prefix, string $subject): string
    {
        $subject = trim($subject);

        if ($subject === '') {
            return trim($prefix).' (sin asunto)';
        }

        if (str_starts_with(mb_strtolower($subject), mb_strtolower($prefix))) {
            return mb_substr($subject, 0, self::MAX_LENGTH);
        }

        return mb_substr($prefix.$subject, 0, self::MAX_LENGTH);
    }
}
