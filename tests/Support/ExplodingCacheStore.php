<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * Un almacén de caché que **siempre falla** (`REQ-ORG-006` SP-7).
 *
 * Es la forma de escribir «Redis caído» en un test sin apagar Redis. Se
 * registra como driver con `Cache::extend()` y se pone por defecto, de modo
 * que lo que se ejercita es el camino real —la fachada, el repositorio, el
 * `Settings`— y no una versión de mentira del código bajo prueba.
 *
 * Lanza en **todas** las operaciones y no solo en `get()`: la mitad que se
 * olvida siempre es la escritura, y una lectura que sobrevive al fallo pero
 * revienta al intentar cachear lo leído deja el backoffice igual de caído.
 */
final class ExplodingCacheStore implements Store
{
    public const MESSAGE = 'Connection refused: el almacén de caché está caído.';

    /**
     * Cuántas veces se le ha pedido algo, para poder afirmar que el camino
     * pasó por aquí en vez de esquivar la caché por otro motivo.
     */
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
