<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use Illuminate\Support\Str;

final readonly class RegisterOrganizationData
{
    public function __construct(
        public string $organizationName,
        public string $organizationSlug,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPassword,
    ) {}

    public function preferredSlug(): string
    {
        return $this->organizationSlug !== ''
            ? Str::slug($this->organizationSlug)
            : Str::slug($this->organizationName);
    }

    public function normalisedEmail(): string
    {
        return Str::lower(trim($this->ownerEmail));
    }
}
