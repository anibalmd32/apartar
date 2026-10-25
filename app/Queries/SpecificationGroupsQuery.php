<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Catalog\SpecificationGroups;
use App\Models\ProductSpecification;

final class SpecificationGroupsQuery
{
    /**
     * @return list<string>
     */
    public function all(): array
    {
        $groups = ProductSpecification::query()
            ->whereNotNull('group')
            ->orderBy('group')
            ->pluck('group')
            ->all();

        $unique = [];

        foreach ($groups as $group) {
            $group = trim((string) $group);

            if ($group === '') {
                continue;
            }

            $unique[SpecificationGroups::keyFor($group)] ??= $group;
        }

        return array_values($unique);
    }
}
