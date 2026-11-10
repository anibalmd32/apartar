<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum PhotoSubject: string
{
    case Product = 'product';
    case Animal = 'animal';
    case Person = 'person';
    case Other = 'other';

    public static function fromAnswer(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(mb_strtolower(trim($value))) ?? self::Product) : self::Product;
    }

    public function needsReview(): bool
    {
        return $this !== self::Product;
    }

    public function label(): string
    {
        return match ($this) {
            self::Product => 'un producto',
            self::Animal => 'un animal',
            self::Person => 'una persona',
            self::Other => 'algo que no parece un producto',
        };
    }
}
