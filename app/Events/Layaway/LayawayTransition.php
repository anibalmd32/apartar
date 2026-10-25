<?php

declare(strict_types=1);

namespace App\Events\Layaway;

use App\Domain\Layaway\LayawayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

abstract class LayawayTransition implements LayawayTransitioned, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $layawayId,
        public readonly int $organizationId,
        public readonly LayawayStatus $from,
        public readonly LayawayStatus $to,
        public readonly CarbonImmutable $occurredAt,
    ) {}

    public function layawayId(): int
    {
        return $this->layawayId;
    }

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function from(): LayawayStatus
    {
        return $this->from;
    }

    public function to(): LayawayStatus
    {
        return $this->to;
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }
}
