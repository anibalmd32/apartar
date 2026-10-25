<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

final readonly class OrganizationBrand
{
    public const LOGO_DISK = 'public';

    private function __construct(
        public string $name,
        public string $slug,
        public ?string $logoUrl,
        public ?string $brandColor,
        public bool $brandingEnabled,
        public ?string $tagline = null,
    ) {}

    public static function of(Organization $organization, bool $brandingEnabled): self
    {
        return new self(
            name: $organization->name,
            slug: $organization->slug,
            logoUrl: self::logoUrlOf($organization),
            brandColor: $organization->brand_color,
            brandingEnabled: $brandingEnabled,
            tagline: $organization->tagline,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'logoUrl' => $this->logoUrl,
            'brandColor' => $this->brandColor,
            'brandingEnabled' => $this->brandingEnabled,
            'tagline' => $this->tagline,
        ];
    }

    private static function logoUrlOf(Organization $organization): ?string
    {
        $path = $organization->logo_path;

        return $path === null || $path === '' ? null : Storage::disk(self::LOGO_DISK)->url($path);
    }
}
