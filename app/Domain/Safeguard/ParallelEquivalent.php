<?php

declare(strict_types=1);

namespace App\Domain\Safeguard;

use App\Domain\Money\Money;

final readonly class ParallelEquivalent
{
    public function __construct(
        public Money $credited,
        public ?Money $equivalent,
        public ?string $parallelRate,
    ) {}

    public static function missing(Money $credited): self
    {
        return new self($credited, null, null);
    }

    public function known(): bool
    {
        return $this->equivalent !== null;
    }

    public function gap(): ?Money
    {
        return $this->equivalent?->subtract($this->credited);
    }
}
