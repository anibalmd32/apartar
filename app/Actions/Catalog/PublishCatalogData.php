<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use Carbon\CarbonImmutable;

final readonly class PublishCatalogData
{
    public function __construct(
        public bool $isListed,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
