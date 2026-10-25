<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductSpecification;
use Illuminate\Validation\ValidationException;

final class SyncSpecificationsAction
{
    public const MAX_ROWS = 50;

    /**
     * @param  list<SpecificationInput>  $rows
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'specifications' => sprintf(
                    'Una ficha técnica admite hasta %d características y llegaron %d.',
                    self::MAX_ROWS,
                    count($rows),
                ),
            ]);
        }

        $productId = (int) $product->getKey();

        ProductSpecification::query()->where('product_id', $productId)->delete();

        if ($rows === []) {
            return;
        }

        $position = 1;

        foreach ($rows as $row) {
            ProductSpecification::query()->create($row->toAttributes($productId, $position));

            $position++;
        }
    }
}
