<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

final class OrganizationRowResource
{
    public const FIELDS = [
        'id', 'slug', 'name', 'status', 'planCode', 'subscriptionStatus', 'createdAt',
        'counts', 'volume', 'lastActivityAt', 'risks', 'hasActiveSupportAccess',
    ];

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function of(array $row): array
    {
        $shaped = [];

        foreach (self::FIELDS as $field) {
            $shaped[$field] = $row[$field] ?? null;
        }

        return $shaped;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function collectionOf(array $rows): array
    {
        return array_map(self::of(...), $rows);
    }
}
