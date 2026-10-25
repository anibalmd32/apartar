<?php

declare(strict_types=1);

namespace App\Support;

final readonly class FullTextTerm
{
    public const MAX_LENGTH = 100;

    private const MIN_INDEXED_LENGTH = 3;

    private const MAX_INDEXED_LENGTH = 84;

    /**
     * @var list<string>
     */
    public const COLUMNS = ['products.name', 'products.description', 'products.sku'];

    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in',
        'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who',
        'will', 'with', 'und', 'www',
        'el', 'los', 'las', 'lo', 'un', 'una', 'unos', 'unas', 'y', 'e', 'o', 'u', 'del', 'al', 'con', 'para',
        'por', 'que', 'se', 'su', 'sus',
    ];

    /**
     * @param  non-empty-list<string>  $words
     */
    private function __construct(
        public string $text,
        public array $words,
    ) {}

    public static function sanitize(mixed $raw): ?self
    {
        if (! is_string($raw)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($raw, 'UTF-8')));
        $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8'));

        $words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter(
            $words === false ? [] : $words,
            fn (string $word): bool => ! in_array(mb_strtolower($word, 'UTF-8'), self::STOPWORDS, true),
        ));

        return $words === [] ? null : new self($text, $words);
    }

    public function booleanQuery(): ?string
    {
        foreach ($this->words as $word) {
            $length = mb_strlen($word, 'UTF-8');

            if ($length < self::MIN_INDEXED_LENGTH || $length > self::MAX_INDEXED_LENGTH) {
                return null;
            }
        }

        return implode(' ', array_map(fn (string $word): string => '+'.$word.'*', $this->words));
    }

    /**
     * @return non-empty-list<string>
     */
    public function likePatterns(): array
    {
        return array_map(fn (string $word): string => '%'.addcslashes($word, '%_\\').'%', $this->words);
    }
}
