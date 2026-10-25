<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

final readonly class OverdueSummary
{
    public function __construct(
        public int $installmentsMarkedOverdue = 0,
        public int $installmentsReturned = 0,
        public int $layawaysBecameOverdue = 0,
        public int $layawaysCaughtUp = 0,
    ) {}

    public function withInstallmentMarkedOverdue(): self
    {
        return new self($this->installmentsMarkedOverdue + 1, $this->installmentsReturned, $this->layawaysBecameOverdue, $this->layawaysCaughtUp);
    }

    public function withInstallmentReturned(): self
    {
        return new self($this->installmentsMarkedOverdue, $this->installmentsReturned + 1, $this->layawaysBecameOverdue, $this->layawaysCaughtUp);
    }

    public function withLayawayBecameOverdue(): self
    {
        return new self($this->installmentsMarkedOverdue, $this->installmentsReturned, $this->layawaysBecameOverdue + 1, $this->layawaysCaughtUp);
    }

    public function withLayawayCaughtUp(): self
    {
        return new self($this->installmentsMarkedOverdue, $this->installmentsReturned, $this->layawaysBecameOverdue, $this->layawaysCaughtUp + 1);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->installmentsMarkedOverdue + $other->installmentsMarkedOverdue,
            $this->installmentsReturned + $other->installmentsReturned,
            $this->layawaysBecameOverdue + $other->layawaysBecameOverdue,
            $this->layawaysCaughtUp + $other->layawaysCaughtUp,
        );
    }

    /**
     * @return array{installments_marked_overdue: int, installments_returned: int, layaways_became_overdue: int, layaways_caught_up: int}
     */
    public function toArray(): array
    {
        return [
            'installments_marked_overdue' => $this->installmentsMarkedOverdue,
            'installments_returned' => $this->installmentsReturned,
            'layaways_became_overdue' => $this->layawaysBecameOverdue,
            'layaways_caught_up' => $this->layawaysCaughtUp,
        ];
    }
}
