<?php

declare(strict_types=1);

namespace App\Queries;

use Carbon\CarbonInterface;

final readonly class SubscriptionRow
{
    public function __construct(
        public int $catalogId,
        public string $catalogSlug,
        public string $catalogName,
        public string $businessName,
        public bool $active,
        public bool $muted,
        public int $productsCount,
        public int $newCount,
        public CarbonInterface $subscribedAt,
        public ?string $url,
    ) {}

    /**
     * @return array{catalogId: int, catalogSlug: string, catalogName: string, businessName: string, active: bool, muted: bool, productsCount: int, newCount: int, subscribedAt: string, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'catalogId' => $this->catalogId,
            'catalogSlug' => $this->catalogSlug,
            'catalogName' => $this->catalogName,
            'businessName' => $this->businessName,
            'active' => $this->active,
            'muted' => $this->muted,
            'productsCount' => $this->productsCount,
            'newCount' => $this->newCount,
            'subscribedAt' => $this->subscribedAt->toIso8601String(),
            'url' => $this->url,
        ];
    }
}
