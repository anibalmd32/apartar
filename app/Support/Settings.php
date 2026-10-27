<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

final class Settings
{
    public const CACHE_PREFIX = 'settings:';

    public const TTL_SECONDS = 86_400;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CacheRepository $cache,
    ) {}

    public function get(SettingKey $key): mixed
    {
        return $this->all()[$key->value] ?? $key->defaultValue();
    }

    public function graceDays(): int
    {
        $days = $this->get(SettingKey::GracePeriodDays);

        return is_numeric($days) ? (int) $days : (int) SettingKey::GracePeriodDays->defaultValue();
    }

    public function longOverdueDays(): int
    {
        $days = $this->get(SettingKey::OverdueAlertDays);

        return is_numeric($days) ? (int) $days : (int) SettingKey::OverdueAlertDays->defaultValue();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->forOrganization($this->context->organization());
    }

    /**
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $organizationId = (int) $organization->getKey();

        if (array_key_exists($organizationId, $this->memo)) {
            return $this->memo[$organizationId];
        }

        $cached = $this->remember($organizationId, fn (): array => $this->readFromDatabase($organization));

        return $this->memo[$organizationId] = $cached;
    }

    public function forget(?Organization $organization = null): void
    {
        $organization ??= $this->context->organization();
        $organizationId = (int) $organization->getKey();

        unset($this->memo[$organizationId]);

        $this->withoutFailing(fn () => $this->cache->forget(self::cacheKeyFor($organizationId)));
    }

    public static function cacheKeyFor(int $organizationId): string
    {
        return self::CACHE_PREFIX.$organizationId;
    }

    /**
     * @param  \Closure(): array<string, mixed>  $resolve
     * @return array<string, mixed>
     */
    private function remember(int $organizationId, \Closure $resolve): array
    {
        $cacheKey = self::cacheKeyFor($organizationId);

        $cached = $this->withoutFailing(fn (): mixed => $this->cache->get($cacheKey));

        if (is_array($cached)) {
            return $cached;
        }

        $resolved = $resolve();

        $this->withoutFailing(fn () => $this->cache->put($cacheKey, $resolved, self::TTL_SECONDS));

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFromDatabase(Organization $organization): array
    {
        $rows = $this->context->runFor(
            $organization,
            fn (): array => OrganizationSetting::query()->get()->all(),
        );

        $values = SettingKey::defaults();

        foreach ($rows as $row) {
            $key = SettingKey::tryFromKey((string) $row->getRawOriginal('key'));

            if ($key === null) {
                continue;
            }

            $values[$key->value] = $row->value;
        }

        return $values;
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $operation
     * @return TReturn|null
     */
    private function withoutFailing(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            return null;
        }
    }
}
