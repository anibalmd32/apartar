<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\ProductSpecification;
use Illuminate\Support\Collection;

final class SpecificationGroups
{
    /**
     * @param  Collection<int, ProductSpecification>  $specifications
     * @return list<array{group: string|null, items: list<array{label: string, value: string}>}>
     */
    public static function of(Collection $specifications): array
    {
        /** @var array<string, array{group: string|null, items: list<array{label: string, value: string}>}> $groups */
        $groups = [];

        foreach (self::sorted($specifications) as $specification) {
            if (trim($specification->label) === '') {
                continue;
            }

            $key = self::keyFor($specification->group);

            $groups[$key] ??= ['group' => $specification->group, 'items' => []];

            $groups[$key]['items'][] = [
                'label' => $specification->label,
                'value' => $specification->value,
            ];
        }

        return self::withGeneralFirst($groups);
    }

    public static function keyFor(?string $group): string
    {
        return $group === null ? '' : mb_strtolower(trim($group));
    }

    /**
     * @param  Collection<int, ProductSpecification>  $specifications
     * @return list<ProductSpecification>
     */
    private static function sorted(Collection $specifications): array
    {
        return $specifications
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{group: string|null, items: list<array{label: string, value: string}>}>  $groups
     * @return list<array{group: string|null, items: list<array{label: string, value: string}>}>
     */
    private static function withGeneralFirst(array $groups): array
    {
        $general = $groups[''] ?? null;

        unset($groups['']);

        $ordered = array_values($groups);

        if ($general !== null) {
            array_unshift($ordered, $general);
        }

        return $ordered;
    }
}
