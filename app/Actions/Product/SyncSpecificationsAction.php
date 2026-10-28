<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductSpecification;
use Illuminate\Validation\ValidationException;

final class SyncSpecificationsAction
{
    public const MAX_ROWS = 30;

    public const MAX_LENGTH = 200;

    /**
     * @param  list<string>  $items
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $items): void
    {
        $items = self::normalized($items);

        if (count($items) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'specifications' => sprintf(
                    'Un producto admite hasta %d características y llegaron %d.',
                    self::MAX_ROWS,
                    count($items),
                ),
            ]);
        }

        $productId = (int) $product->getKey();

        ProductSpecification::query()->where('product_id', $productId)->delete();

        $position = 1;

        foreach ($items as $text) {
            ProductSpecification::query()->create([
                'product_id' => $productId,
                'text' => $text,
                'position' => $position,
            ]);

            $position++;
        }
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<string>
     */
    public static function normalized(array $items): array
    {
        $texts = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $item));

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
