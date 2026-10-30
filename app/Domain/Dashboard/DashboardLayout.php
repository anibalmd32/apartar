<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Models\User;
use InvalidArgumentException;

final readonly class DashboardLayout
{
    public const MODES = ['stacked', 'split'];

    public const SECTIONS = ['stats', 'plan'];

    public const CARDS = ['pendingReceipts', 'collectedThisMonth', 'customersOverdue', 'longOverdue', 'completedNotDelivered', 'unreadMessages'];

    /**
     * @param  list<string>  $sections
     * @param  list<string>  $cards
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string $mode = 'stacked',
        public array $sections = self::SECTIONS,
        public array $cards = self::CARDS,
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(sprintf('El panel se muestra apilado o en dos columnas, y llegó «%s».', $mode));
        }

        self::assertPermutation($sections, self::SECTIONS, 'secciones');
        self::assertPermutation($cards, self::CARDS, 'tarjetas');
    }

    public static function of(?User $user): self
    {
        $stored = $user?->dashboard_layout;

        if (! is_array($stored)) {
            return new self;
        }

        try {
            return new self(
                is_string($stored['mode'] ?? null) ? $stored['mode'] : 'stacked',
                self::merge($stored['sections'] ?? null, self::SECTIONS),
                self::merge($stored['cards'] ?? null, self::CARDS),
            );
        } catch (InvalidArgumentException) {
            return new self;
        }
    }

    /**
     * @return array{mode: string, sections: list<string>, cards: list<string>}
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'sections' => $this->sections, 'cards' => $this->cards];
    }

    /**
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function merge(mixed $stored, array $known): array
    {
        $kept = is_array($stored) ? array_values(array_filter($stored, fn (mixed $id): bool => is_string($id) && in_array($id, $known, true))) : [];
        $kept = array_values(array_unique($kept));

        return [...$kept, ...array_values(array_diff($known, $kept))];
    }

    /**
     * @param  list<string>  $given
     * @param  list<string>  $known
     *
     * @throws InvalidArgumentException
     */
    private static function assertPermutation(array $given, array $known, string $what): void
    {
        $sortedGiven = $given;
        $sortedKnown = $known;
        sort($sortedGiven);
        sort($sortedKnown);

        if ($sortedGiven !== $sortedKnown) {
            throw new InvalidArgumentException(sprintf('El orden de las %s tiene que incluir cada una una sola vez: %s.', $what, implode(', ', $known)));
        }
    }
}
