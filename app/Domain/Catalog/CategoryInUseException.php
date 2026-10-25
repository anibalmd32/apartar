<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Category;
use RuntimeException;

final class CategoryInUseException extends RuntimeException
{
    public const STATUS = 422;

    private function __construct(
        public readonly Category $category,
        public readonly int $productsCount,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function cannotBeDeleted(Category $category, int $products): self
    {
        return new self($category, $products, 'category', sprintf(
            'La categoría «%s» tiene %s asociado%s, así que no se puede eliminar. Reasígnalos a otra categoría '
            .'y vuelve a intentarlo.',
            $category->name,
            $products === 1 ? '1 producto' : $products.' productos',
            $products === 1 ? '' : 's',
        ));
    }

    public static function slugIsLocked(Category $category, int $published): self
    {
        return new self($category, $published, 'slug', sprintf(
            'El enlace de «%s» ya no se puede cambiar: tiene %s publicado%s y los filtros que compartiste '
            .'por URL dejarían de funcionar. El nombre sí: vuelve a guardar dejando el enlace como estaba.',
            $category->name,
            $published === 1 ? '1 producto' : $published.' productos',
            $published === 1 ? '' : 's',
        ));
    }
}
