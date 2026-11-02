<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\User;

final readonly class SurplusDestination
{
    public const NOTE_MAX = 500;

    public function __construct(
        public SurplusDestinationType $type,
        public ?User $teamMember = null,
        public ?string $note = null,
    ) {}

    public function noteText(): ?string
    {
        if ($this->note === null) {
            return null;
        }

        $trimmed = trim($this->note);

        return $trimmed === '' ? null : $trimmed;
    }
}
