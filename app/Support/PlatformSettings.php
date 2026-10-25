<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Platform\PlatformSettingKey;
use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Throwable;

final class PlatformSettings
{
    public const CACHE_KEY = 'platform_settings';

    public const TTL_SECONDS = 300;

    public static function get(PlatformSettingKey $key): int
    {
        $value = self::all()[$key->value] ?? null;

        return $key->accepts($value) ? (int) $value : $key->default();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function set(PlatformSettingKey $key, int $value, ?PlatformUser $actor = null): void
    {
        if (! $key->accepts($value)) {
            [$min, $max] = $key->range();

            throw new \InvalidArgumentException(sprintf('«%s» va de %d a %d.', $key->value, $min, $max));
        }

        PlatformSetting::query()->updateOrCreate(['key' => $key->value], ['value' => $value, 'updated_by' => $actor?->getKey()]);

        self::forget();
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        if (Facade::getFacadeApplication() === null) {
            return [];
        }

        try {
            return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, self::load(...));
        } catch (Throwable) {
            try {
                return self::load();
            } catch (Throwable $exception) {
                self::report($exception);

                return [];
            }
        }
    }

    private static function report(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        return PlatformSetting::query()->pluck('value', 'key')->all();
    }
}
