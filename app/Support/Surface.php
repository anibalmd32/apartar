<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Route;

final class Surface
{
    public const BACKOFFICE_PREFIX = 'backoffice';

    public const PLATFORM_PREFIX = 'plataforma';

    public static function backofficeDomain(): ?string
    {
        return self::domain('app.backoffice_domain');
    }

    public static function platformDomain(): ?string
    {
        return self::domain('app.platform_domain');
    }

    public static function backofficeRoutes(Closure $routes): void
    {
        self::register(self::backofficeDomain(), self::BACKOFFICE_PREFIX, $routes);
    }

    public static function platformRoutes(Closure $routes): void
    {
        self::register(self::platformDomain(), self::PLATFORM_PREFIX, $routes);
    }

    public static function backofficePath(): string
    {
        return self::backofficeDomain() === null ? '/'.self::BACKOFFICE_PREFIX : '';
    }

    public static function platformPath(): string
    {
        return self::platformDomain() === null ? '/'.self::PLATFORM_PREFIX : '';
    }

    public static function backofficeUrl(string $path = '/'): string
    {
        return self::url(self::backofficeDomain(), self::BACKOFFICE_PREFIX, $path);
    }

    public static function platformUrl(string $path = '/'): string
    {
        return self::url(self::platformDomain(), self::PLATFORM_PREFIX, $path);
    }

    /**
     * @return array{backoffice: string, platform: string, site: string}
     */
    public static function forClient(): array
    {
        return ['backoffice' => self::backofficePath(), 'platform' => self::platformPath(), 'site' => self::siteUrl()];
    }

    public static function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private static function register(?string $domain, string $prefix, Closure $routes): void
    {
        $domain === null ? Route::prefix($prefix)->group($routes) : Route::domain($domain)->group($routes);
    }

    private static function url(?string $domain, string $prefix, string $path): string
    {
        $path = '/'.ltrim($path, '/');

        if ($domain === null) {
            return url('/'.$prefix.($path === '/' ? '' : $path));
        }

        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.$domain.(is_int($port) ? ':'.$port : '').($path === '/' ? '' : $path);
    }

    private static function domain(string $key): ?string
    {
        $domain = config($key);

        return is_string($domain) && trim($domain) !== '' ? trim($domain) : null;
    }
}
