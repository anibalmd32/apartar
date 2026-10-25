<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

final readonly class ExpirationOutcome
{
    public function __construct(
        public int $expired = 0,
        public int $held = 0,
    ) {}

    public function withExpired(): self
    {
        return new self($this->expired + 1, $this->held);
    }

    public function withHeld(): self
    {
        return new self($this->expired, $this->held + 1);
    }

    public function plus(self $other): self
    {
        return new self($this->expired + $other->expired, $this->held + $other->held);
    }

    /**
     * @return array{expired: int, held: int}
     */
    public function toArray(): array
    {
        return ['expired' => $this->expired, 'held' => $this->held];
    }
}
