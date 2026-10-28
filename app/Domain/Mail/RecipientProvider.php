<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final class RecipientProvider
{
    public const OTHER = 'Otros';

    /**
     * @var array<string, list<string>>
     */
    private const PROVIDERS = [
        'Gmail' => ['gmail.com', 'googlemail.com'],
        'Outlook' => ['outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'hotmail.es', 'outlook.es', 'live.com.mx', 'hotmail.com.ve'],
        'Yahoo' => ['yahoo.com', 'yahoo.es', 'ymail.com', 'rocketmail.com', 'yahoo.com.ve', 'yahoo.com.mx'],
        'iCloud' => ['icloud.com', 'me.com', 'mac.com'],
        'Proton' => ['proton.me', 'protonmail.com', 'pm.me'],
        'Zoho' => ['zoho.com', 'zohomail.com'],
        'AOL' => ['aol.com'],
        'GMX' => ['gmx.com', 'gmx.es', 'gmx.net'],
        'CANTV' => ['cantv.net'],
    ];

    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(trim(substr($address, $at + 1), " \t\n\r\0\x0B>"));
    }

    public static function of(string $domain): string
    {
        $domain = strtolower(trim($domain));

        foreach (self::PROVIDERS as $provider => $domains) {
            if (in_array($domain, $domains, true)) {
                return $provider;
            }
        }

        return $domain === '' ? self::OTHER : $domain;
    }
}
