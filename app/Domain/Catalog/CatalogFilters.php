<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\FullTextTerm;
use Illuminate\Http\Request;

final readonly class CatalogFilters
{
    public const MAX_TAGS = 20;

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const MAX_SLUG_LENGTH = 120;

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public ?FullTextTerm $term = null,
        public ?string $category = null,
        public array $tags = [],
        public ?CatalogSort $sort = null,
        public bool $ignored = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $category = $request->query('category');
        $requested = $request->query('tags');

        $requested = is_string($requested) ? [$requested] : (is_array($requested) ? $requested : []);
        $tags = self::slugs($requested);

        return new self(
            term: FullTextTerm::sanitize($request->query('q')),
            category: self::isSlug($category) ? $category : null,
            tags: $tags,
            sort: CatalogSort::whitelist()->resolve($request->query('sort')),
            ignored: ($category !== null && $category !== '' && ! self::isSlug($category)) || count($tags) < count($requested),
        );
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $tags
     */
    public function restrictedTo(array $categories, array $tags): self
    {
        $category = $this->category !== null && in_array($this->category, $categories, true) ? $this->category : null;
        $kept = array_values(array_intersect($this->tags, $tags));

        return new self(
            term: $this->term,
            category: $category,
            tags: $kept,
            sort: $this->sort,
            ignored: $this->ignored || $category !== $this->category || count($kept) !== count($this->tags),
        );
    }

    public function effectiveSort(SearchMode $mode): CatalogSort
    {
        if ($this->sort !== null) {
            return $this->sort;
        }

        return $this->term !== null && $mode === SearchMode::FullText ? CatalogSort::Relevance : CatalogSort::Curated;
    }

    /**
     * @return array{q: string|null, category: string|null, tags: list<string>, sort: string|null, ignored: bool}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->term?->text,
            'category' => $this->category,
            'tags' => $this->tags,
            'sort' => $this->sort?->value,
            'ignored' => $this->ignored,
        ];
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isSlug(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= self::MAX_SLUG_LENGTH && preg_match(self::SLUG, $value) === 1;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function slugs(array $values): array
    {
        $slugs = array_values(array_unique(array_filter($values, self::isSlug(...))));

        return array_slice($slugs, 0, self::MAX_TAGS);
    }
}
