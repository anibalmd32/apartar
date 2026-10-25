<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Category;
use App\Models\Tag;
use App\Support\TaxonomySlug;
use Illuminate\Database\UniqueConstraintViolationException;

final class TaxonomyByName
{
    private const ATTEMPTS = 3;

    public function __construct(private readonly CategoryOrder $order) {}

    /**
     * @throws UniqueConstraintViolationException
     */
    public function category(string $name): Category
    {
        $name = self::clean($name);

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $existing = Category::query()->where('name', $name)->first();

            if ($existing instanceof Category) {
                return $existing;
            }

            try {
                $category = Category::query()->create([
                    'name' => $name,
                    'slug' => TaxonomySlug::from(
                        $name,
                        fn (string $slug): bool => Category::query()->where('slug', $slug)->exists(),
                        120,
                    ),
                    'position' => $this->order->next(),
                ]);

                $this->order->apply();

                return $category->refresh();
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        return Category::query()->where('name', $name)->sole();
    }

    /**
     * @throws UniqueConstraintViolationException
     */
    public function tag(string $name): Tag
    {
        $name = self::clean($name);

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $existing = Tag::query()->where('name', $name)->first();

            if ($existing instanceof Tag) {
                return $existing;
            }

            try {
                return Tag::query()->create([
                    'name' => $name,
                    'slug' => TaxonomySlug::from(
                        $name,
                        fn (string $slug): bool => Tag::query()->where('slug', $slug)->exists(),
                        80,
                    ),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        return Tag::query()->where('name', $name)->sole();
    }

    private static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
