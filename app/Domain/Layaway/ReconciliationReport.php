<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class ReconciliationReport
{
    public const CACHE_KEY = 'layaways:reconcile:last';

    private const LOGGED_ENTRIES = 50;

    public int $layaways = 0;

    public int $products = 0;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /**
     * @param  array<string, mixed>  $entry
     */
    public function add(array $entry): void
    {
        $this->entries[] = $entry;
    }

    public function merge(self $other): void
    {
        $this->layaways += $other->layaways;
        $this->products += $other->products;
        $this->entries = [...$this->entries, ...$other->entries];
    }

    public function discrepancies(): int
    {
        return count($this->entries);
    }

    public function balanceDiscrepancies(): int
    {
        return count(array_filter($this->entries, fn (array $entry): bool => $entry['type'] === 'layaway'));
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->entries, fn (array $entry): bool => $entry['outcome'] === $outcome));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'layaways' => $this->layaways,
            'products' => $this->products,
            'discrepancies' => $this->discrepancies(),
            'fixed' => $this->count('fixed'),
            'skipped' => $this->count('skipped'),
            'unresolved' => $this->count('unresolved'),
            'entries' => array_slice($this->entries, 0, self::LOGGED_ENTRIES),
        ];
    }

    public function remember(CarbonImmutable $ranAt): void
    {
        Cache::forever(self::CACHE_KEY, [
            'discrepancies' => $this->balanceDiscrepancies(),
            'stock' => $this->discrepancies() - $this->balanceDiscrepancies(),
            'unresolved' => $this->count('unresolved'),
            'lastRunAt' => $ranAt->toIso8601String(),
        ]);
    }

    /**
     * @return array{discrepancies: int, stock: int, unresolved: int, lastRunAt: string}|null
     */
    public static function last(): ?array
    {
        $last = Cache::get(self::CACHE_KEY);

        if (! is_array($last) || ! isset($last['discrepancies'], $last['stock'], $last['unresolved'], $last['lastRunAt'])) {
            return null;
        }

        return [
            'discrepancies' => (int) $last['discrepancies'],
            'stock' => (int) $last['stock'],
            'unresolved' => (int) $last['unresolved'],
            'lastRunAt' => (string) $last['lastRunAt'],
        ];
    }
}
