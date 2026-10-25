<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class SubscriberRowResource
{
    public const FIELDS = ['displayName', 'avatarUrl', 'subscribedAt', 'catalogName', 'isCustomer', 'customerUrl'];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function collectionOf(array $rows): array
    {
        return array_map(
            fn (array $row): array => array_intersect_key($row, array_flip(self::FIELDS)),
            $rows,
        );
    }
}
