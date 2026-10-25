<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Models\Organization;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Throwable;

final readonly class FeedCursor
{
    public function __construct(
        public CarbonImmutable $enteredAt,
        public int $organizationId,
        public int $productId,
    ) {}

    public static function decode(?string $cursor): ?self
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded[0] ?? null) || ! is_int($decoded[1] ?? null) || ! is_int($decoded[2] ?? null)) {
            return null;
        }

        try {
            return new self(CarbonImmutable::parse($decoded[0]), $decoded[1], $decoded[2]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{product: Product, organization: Organization, enteredAt: CarbonImmutable}  $item
     */
    public static function encode(array $item): string
    {
        $json = (string) json_encode([
            $item['enteredAt']->format('Y-m-d H:i:s'),
            (int) $item['organization']->getKey(),
            (int) $item['product']->getKey(),
        ]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param  array{product: Product, organization: Organization, enteredAt: CarbonImmutable}  $item
     * @return array{string, int, int}
     */
    public static function keyOf(array $item): array
    {
        return [$item['enteredAt']->format('Y-m-d H:i:s'), (int) $item['organization']->getKey(), (int) $item['product']->getKey()];
    }

    public function constrain(Builder $query, int $organizationId): Builder
    {
        $at = $this->enteredAt->format('Y-m-d H:i:s');

        return match (true) {
            $organizationId < $this->organizationId => $query->where('entered_at', '<=', $at),
            $organizationId > $this->organizationId => $query->where('entered_at', '<', $at),
            default => $query->where(fn (Builder $tie): Builder => $tie
                ->where('entered_at', '<', $at)
                ->orWhere(fn (Builder $same): Builder => $same->where('entered_at', '=', $at)->where('product_id', '<', $this->productId))),
        };
    }
}
