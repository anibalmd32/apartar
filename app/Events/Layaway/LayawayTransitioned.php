<?php

declare(strict_types=1);

namespace App\Events\Layaway;

use App\Domain\Layaway\LayawayStatus;
use Carbon\CarbonImmutable;

interface LayawayTransitioned
{
    public function layawayId(): int;

    public function organizationId(): int;

    public function from(): LayawayStatus;

    public function to(): LayawayStatus;

    public function occurredAt(): CarbonImmutable;
}
