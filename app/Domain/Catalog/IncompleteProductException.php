<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use RuntimeException;

final class IncompleteProductException extends RuntimeException
{
    public const STATUS = 422;

    /**
     * @param  array<string, string>  $missing
     */
    private function __construct(
        public readonly Product $product,
        public readonly array $missing,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $missing
     */
    public static function of(Product $product, array $missing): self
    {
        return new self($product, $missing, self::messageFor($product, $missing));
    }

    public function missingSummary(): string
    {
        return self::listOf(self::labelsFor($this->missing));
    }

    /**
     * @param  array<string, string>  $missing
     */
    private static function messageFor(Product $product, array $missing): string
    {
        return sprintf(
            '«%s» todavía no se puede publicar. Pendiente: %s. Complétalo y vuelve a publicarlo.',
            $product->name,
            self::listOf(self::labelsFor($missing)),
        );
    }

    /**
     * @param  array<string, string>  $missing
     * @return list<string>
     */
    private static function labelsFor(array $missing): array
    {
        $labels = [];

        foreach (array_keys($missing) as $field) {
            $labels[] = match ($field) {
                'images' => 'al menos una foto',
                'category_id' => 'una categoría',
                'description' => 'la descripción',
                default => 'las condiciones de pago',
            };
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  list<string>  $items
     */
    public static function listOf(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }
}
