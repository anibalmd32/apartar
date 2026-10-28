<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\IncompleteProductException;

final readonly class BulkPublishResult
{
    public const LISTED_FAILURES = 5;

    /**
     * @param  list<string>  $published
     * @param  list<array{name: string, reason: string}>  $failed
     */
    public function __construct(
        public array $published,
        public array $failed,
        public int $skipped,
    ) {}

    public function summary(): string
    {
        return implode(' ', array_filter([
            $this->publishedSentence(),
            $this->failedSentence(),
            $this->skippedSentence(),
        ]));
    }

    private function publishedSentence(): string
    {
        return match (count($this->published)) {
            0 => 'No se publicó ningún producto.',
            1 => 'Publicaste 1 producto.',
            default => sprintf('Publicaste %d productos.', count($this->published)),
        };
    }

    private function failedSentence(): ?string
    {
        $count = count($this->failed);

        if ($count === 0) {
            return null;
        }

        $items = array_map(
            fn (array $failure): string => sprintf('«%s» (%s)', $failure['name'], $failure['reason']),
            array_slice($this->failed, 0, self::LISTED_FAILURES),
        );

        if ($count > self::LISTED_FAILURES) {
            $items[] = sprintf('%d más', $count - self::LISTED_FAILURES);
        }

        return sprintf(
            '%s: %s.',
            $count === 1 ? '1 no se pudo publicar' : sprintf('%d no se pudieron publicar', $count),
            IncompleteProductException::listOf($items),
        );
    }

    private function skippedSentence(): ?string
    {
        return match ($this->skipped) {
            0 => null,
            1 => '1 ya no estaba en borrador y se quedó como estaba.',
            default => sprintf('%d ya no estaban en borrador y se quedaron como estaban.', $this->skipped),
        };
    }
}
