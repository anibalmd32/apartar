<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final readonly class UpdateOrganizationData
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $document,
        public ?string $phone,
        public string $timezone,
        public ?string $brandColor,
        public bool $storefrontEnabled,
        public ?UploadedFile $logo = null,
        /**
         * @var array<'tagline'|'description'|'address', string|null>
         */
        public array $profile = [],
    ) {}

    public function normalisedBrandColor(): ?string
    {
        $color = $this->brandColor;

        if ($color === null || trim($color) === '') {
            return null;
        }

        $color = Str::lower(trim($color));

        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $matches) === 1) {
            return '#'.$matches[1].$matches[1].$matches[2].$matches[2].$matches[3].$matches[3];
        }

        return $color;
    }
}
