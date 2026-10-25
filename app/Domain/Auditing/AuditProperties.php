<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

use JsonException;

final readonly class AuditProperties
{
    private const MAX_STRING = 500;

    private const MAX_ITEMS = 20;

    private const MAX_BYTES = 8192;

    private const REDACTED_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'hash',
        'apikey',
        'api_key',
        'privatekey',
        'private_key',
        'credential',
        'accountid',
        'account_id',
    ];

    public const REDACTED = '[redactado]';

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changed
     */
    private function __construct(
        public array $changed,
        public bool $degraded,
    ) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function between(array $before, array $after): self
    {
        $changed = [];

        foreach (array_keys($before + $after) as $key) {
            $key = (string) $key;

            $wasRaw = $before[$key] ?? null;
            $isRaw = $after[$key] ?? null;

            $unchanged = self::isRedacted($key)
                ? $wasRaw === $isRaw
                : self::clean($key, $wasRaw) === self::clean($key, $isRaw);

            if ($unchanged) {
                continue;
            }

            $changed[$key] = [
                'before' => self::clean($key, $wasRaw),
                'after' => self::clean($key, $isRaw),
            ];
        }

        return self::bounded($changed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $properties = ['changed' => $this->changed];

        if ($this->degraded) {
            $properties['truncated'] = true;
        }

        return $properties;
    }

    public function isEmpty(): bool
    {
        return $this->changed === [];
    }

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changed
     */
    private static function bounded(array $changed): self
    {
        if (self::fits($changed)) {
            return new self($changed, degraded: false);
        }

        $summary = [];

        foreach (array_keys($changed) as $key) {
            $summary[(string) $key] = ['before' => '…', 'after' => '…'];
        }

        return self::fits($summary)
            ? new self($summary, degraded: true)
            : new self([], degraded: true);
    }

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changed
     */
    private static function fits(array $changed): bool
    {
        try {
            return strlen(json_encode($changed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) <= self::MAX_BYTES;
        } catch (JsonException) {
            return false;
        }
    }

    private static function clean(string $key, mixed $value): mixed
    {
        if (self::isRedacted($key)) {
            return $value === null ? null : self::REDACTED;
        }

        return self::shorten($value);
    }

    private static function shorten(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING
                ? mb_substr($value, 0, self::MAX_STRING).'…'
                : $value;
        }

        if (is_array($value)) {
            $items = array_slice($value, 0, self::MAX_ITEMS, preserve_keys: true);
            $shortened = [];

            foreach ($items as $itemKey => $item) {
                $shortened[$itemKey] = self::clean((string) $itemKey, $item);
            }

            if (count($value) > self::MAX_ITEMS) {
                $shortened[] = '…';
            }

            return $shortened;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return self::shorten((string) $value);
        }

        return '['.get_debug_type($value).']';
    }

    private static function isRedacted(string $key): bool
    {
        $normalised = mb_strtolower($key);

        foreach (self::REDACTED_FRAGMENTS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
