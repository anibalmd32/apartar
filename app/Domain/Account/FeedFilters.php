<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Support\FullTextTerm;
use Illuminate\Http\Request;

final readonly class FeedFilters
{
    public function __construct(
        public ?string $catalogSlug = null,
        public ?FullTextTerm $term = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $catalog = $request->query('catalogo');

        return new self(
            is_string($catalog) && $catalog !== '' ? mb_substr($catalog, 0, 120) : null,
            FullTextTerm::sanitize($request->query('q')),
        );
    }

    /**
     * @return array{catalog: string|null, q: string|null}
     */
    public function toArray(): array
    {
        return ['catalog' => $this->catalogSlug, 'q' => $this->term?->text];
    }
}
