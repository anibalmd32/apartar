<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use Closure;

/**
 * @template TSort of BackedEnum
 */
final class TableState
{
    /**
     * @var array<string, string|list<string>|null>
     */
    private array $filters = [];

    /**
     * @var list<string>
     */
    private array $ignored = [];

    /**
     * @param  TSort|null  $sort
     */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?BackedEnum $sort,
        public readonly bool $descending,
    ) {}

    /**
     * @template TValue
     *
     * @param  Closure(mixed): (TValue|null)  $resolve
     * @param  (Closure(TValue): (string|list<string>))|null  $echo
     * @return TValue|null
     */
    public function filter(string $key, mixed $raw, Closure $resolve, ?Closure $echo = null): mixed
    {
        if ($raw === null || $raw === '' || $raw === []) {
            $this->filters[$key] = null;

            return null;
        }

        $value = $resolve($raw);

        if ($value === null) {
            $this->filters[$key] = null;
            $this->ignored[] = $key;

            return null;
        }

        $this->filters[$key] = $echo instanceof Closure ? $echo($value) : $this->asQueryValue($value);

        return $value;
    }

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }

    public function sortParameter(): ?string
    {
        if ($this->sort === null) {
            return null;
        }

        return ($this->descending ? '-' : '').$this->sort->value;
    }

    /**
     * @return array<string, string|list<string>|null>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return list<string>
     */
    public function ignored(): array
    {
        return $this->ignored;
    }

    private function asQueryValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
