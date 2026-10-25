<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;
use App\Support\CatalogShareLink;
use RuntimeException;

final class CatalogExpiredException extends RuntimeException
{
    public const STATUS = 410;

    private function __construct(
        public readonly string $catalogName,
        public readonly string $businessName,
        public readonly ?string $storefrontUrl = null,
        public readonly ?string $endedOn = null,
    ) {
        parent::__construct(sprintf(
            'La campaña «%s» de %s ya terminó.',
            $catalogName,
            $businessName,
        ));
    }

    public static function of(Catalog $catalog): self
    {
        $organization = $catalog->organization;

        return new self(
            $catalog->name,
            $organization->name,
            $organization->storefront_enabled ? CatalogShareLink::storefrontOf($organization->slug) : null,
            $catalog->expires_at?->setTimezone($organization->timezone ?? 'America/Caracas')->toDateString(),
        );
    }

    /**
     * @return array{catalogName: string, businessName: string, storefrontUrl: string|null, endedOn: string|null}
     */
    public function toArray(): array
    {
        return [
            'catalogName' => $this->catalogName,
            'businessName' => $this->businessName,
            'storefrontUrl' => $this->storefrontUrl,
            'endedOn' => $this->endedOn,
        ];
    }
}
