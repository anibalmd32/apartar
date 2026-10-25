<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

final class ExchangeRateService
{
    public const CACHE_PREFIX = 'rate:';

    public const TTL_SECONDS = 2_100;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly ManualRateProvider $manual,
    ) {}

    public function current(?Organization $organization = null): ?RateSnapshot
    {
        if ($organization === null) {
            return $this->forSource($this->platformSource());
        }

        $source = $this->sourceFor($organization);

        if ($source === RateSource::Manual) {
            return $this->manualFor($organization);
        }

        return $this->forSource($source);
    }

    public function forSource(RateSource $source): ?RateSnapshot
    {
        $cached = $this->withoutFailing(fn (): mixed => $this->cache->get(self::cacheKeyFor($source)));

        if (is_array($cached)) {
            $snapshot = $this->snapshotFromCache($cached);

            if ($snapshot !== null) {
                return $snapshot;
            }
        }

        $snapshot = $this->latestPersisted($source);

        if ($snapshot !== null) {
            $this->remember($snapshot);
        }

        return $snapshot;
    }

    public function sourceFor(Organization $organization): RateSource
    {
        $configured = $this->settings->forOrganization($organization)[SettingKey::ExchangeRateSource->value] ?? null;

        return is_string($configured)
            ? RateSource::tryFrom($configured) ?? $this->platformSource()
            : $this->platformSource();
    }

    public function remember(RateSnapshot $snapshot): void
    {
        $this->withoutFailing(fn () => $this->cache->put(
            self::cacheKeyFor($snapshot->source),
            [
                'rate' => $snapshot->rate,
                'source' => $snapshot->source->value,
                'published_at' => $snapshot->publishedAt->toIso8601String(),
                'fetched_at' => $snapshot->fetchedAt->toIso8601String(),
            ],
            self::TTL_SECONDS,
        ));
    }

    public static function cacheKeyFor(RateSource $source): string
    {
        return self::CACHE_PREFIX.$source->value;
    }

    public function platformSource(): RateSource
    {
        $default = SettingKey::ExchangeRateSource->defaultValue();

        return is_string($default) ? RateSource::tryFrom($default) ?? RateSource::Bcv : RateSource::Bcv;
    }

    private function manualFor(Organization $organization): ?RateSnapshot
    {
        try {
            return $this->context->runFor(
                $organization,
                fn (): RateSnapshot => $this->manual->fetch(RateSource::Manual),
            );
        } catch (ExchangeRateUnavailableException) {
            return null;
        }
    }

    private function latestPersisted(RateSource $source): ?RateSnapshot
    {
        $row = ExchangeRate::query()
            ->where('source', $source->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return new RateSnapshot(
            rate: $row->rate,
            source: $row->source,
            publishedAt: $row->published_at,
            fetchedAt: $row->fetched_at,
        );
    }

    /**
     * @param  array<array-key, mixed>  $cached
     */
    private function snapshotFromCache(array $cached): ?RateSnapshot
    {
        $rate = $cached['rate'] ?? null;
        $source = $cached['source'] ?? null;
        $publishedAt = $cached['published_at'] ?? null;
        $fetchedAt = $cached['fetched_at'] ?? null;

        if (! is_string($rate) || ! is_string($source) || ! is_string($publishedAt) || ! is_string($fetchedAt)) {
            return null;
        }

        $parsed = RateSource::tryFrom($source);

        if ($parsed === null) {
            return null;
        }

        try {
            return new RateSnapshot(
                rate: $rate,
                source: $parsed,
                publishedAt: CarbonImmutable::parse($publishedAt),
                fetchedAt: CarbonImmutable::parse($fetchedAt),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $operation
     * @return TReturn|null
     */
    private function withoutFailing(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            return null;
        }
    }
}
