<?php

declare(strict_types=1);

namespace App\Queries;

use Carbon\CarbonImmutable;

final readonly class CustomerDirectoryFilters
{
    public function __construct(
        public ?string $search = null,
        public bool $overdue = false,
        public bool $dueSoon = false,
        public bool $withoutActive = false,
        public ?CarbonImmutable $nextDueFrom = null,
        public ?CarbonImmutable $nextDueTo = null,
        public ?int $catalogId = null,
    ) {}
}
