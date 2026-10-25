<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Category;

final class CategoryOrder
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function apply(array $orderedIds = []): void
    {
        $current = $this->currentOrder();

        $requested = [];

        foreach ($orderedIds as $id) {
            if (in_array($id, $current, true) && ! in_array($id, $requested, true)) {
                $requested[] = $id;
            }
        }

        $rest = array_values(array_diff($current, $requested));

        $position = 1;

        foreach ([...$requested, ...$rest] as $id) {
            Category::query()->whereKey($id)->update(['position' => $position]);
            $position++;
        }
    }

    public function next(): int
    {
        return (int) Category::query()->max('position') + 1;
    }

    /**
     * @return list<int>
     */
    private function currentOrder(): array
    {
        return Category::query()
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
