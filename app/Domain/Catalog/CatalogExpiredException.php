<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Tenancy\OrganizationStatus;
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
        public readonly bool $withdrawn = false,
    ) {
        parent::__construct(sprintf(
            $withdrawn ? 'El catálogo «%s» de %s ya no está disponible.' : 'La campaña «%s» de %s ya terminó.',
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

    public static function withdrawn(Catalog $catalog): self
    {
        $organization = $catalog->organization;

        return new self(
            $catalog->name,
            $organization->name,
            $organization->storefront_enabled && $organization->status === OrganizationStatus::Active ? CatalogShareLink::storefrontOf($organization->slug) : null,
            withdrawn: true,
        );
    }

    /**
     * @return array{catalogName: string, businessName: string, storefrontUrl: string|null, endedOn: string|null, withdrawn: bool}
     */
    public function toArray(): array
    {
        return [
            'catalogName' => $this->catalogName,
            'businessName' => $this->businessName,
            'storefrontUrl' => $this->storefrontUrl,
            'endedOn' => $this->endedOn,
            'withdrawn' => $this->withdrawn,
        ];
    }
}
