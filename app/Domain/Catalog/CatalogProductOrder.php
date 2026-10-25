<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;

final class CatalogProductOrder
{
    /**
     * @param  list<int>  $productIds
     */
    public function apply(Catalog $catalog, array $productIds): void
    {
        $current = $this->currentIds($catalog);

        $removed = array_values(array_diff($current, $productIds));

        if ($removed !== []) {
            $catalog->products()->detach($removed);
        }

        $position = 1;

        foreach ($productIds as $productId) {
            if (in_array($productId, $current, true)) {
                $catalog->products()->updateExistingPivot($productId, ['position' => $position]);
            } else {
                $catalog->products()->attach($productId, ['position' => $position]);
            }

            $position++;
        }
    }

    /**
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public function deduplicate(array $productIds): array
    {
        return array_values(array_unique($productIds));
    }

    /**
     * @return list<int>
     */
    private function currentIds(Catalog $catalog): array
    {
        return $catalog->products()
            ->pluck('products.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
