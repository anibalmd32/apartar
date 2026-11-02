<?php

declare(strict_types=1);

namespace App\Support\Auditing;

final class SupportViewSentence
{
    /**
     * @var array<string, string>
     */
    private const SECTIONS = [
        'platform.support.layaways' => 'los apartados',
        'platform.support.customers' => 'los clientes',
    ];

    public static function for(?string $route): string
    {
        return sprintf('Consultó %s con acceso de soporte.', self::SECTIONS[$route ?? ''] ?? 'datos del negocio');
    }

    public static function readable(string $action, string $description): string
    {
        if ($action !== PlatformAudit::SUPPORT_VIEWED || preg_match('/^Consultó (\S+)\.$/u', $description, $matches) !== 1) {
            return $description;
        }

        return self::for($matches[1]);
    }
}
