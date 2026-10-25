<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

final class ExplodingCacheStore implements Store
{
    public const MESSAGE = 'Connection refused: el almacén de caché está caído.';

    public int $attempts = 0;

    public function get($key): mixed
    {
        return $this->explode();
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        return $this->explode();
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->explode();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        return $this->explode();
    }

    public function increment($key, $value = 1): bool
    {
        return $this->explode();
    }

    public function decrement($key, $value = 1): bool
    {
        return $this->explode();
    }

    public function forever($key, $value): bool
    {
        return $this->explode();
    }

    public function forget($key): bool
    {
        return $this->explode();
    }

    public function flush(): bool
    {
        return $this->explode();
    }

    public function getPrefix(): string
    {
        return '';
    }

    private function explode(): never
    {
        $this->attempts++;

        throw new RuntimeException(self::MESSAGE);
    }
}
