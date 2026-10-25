<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Closure;

final class TenantContext
{
    private ?Organization $organization = null;

    /**
     * @throws MissingTenantContextException
     */
    public function organization(): Organization
    {
        if ($this->organization === null) {
            throw MissingTenantContextException::whileReading();
        }

        return $this->organization;
    }

    /**
     * @throws MissingTenantContextException
     */
    public function organizationId(): int
    {
        return (int) $this->organization()->getKey();
    }

    public function hasOrganization(): bool
    {
        return $this->organization !== null;
    }

    /**
     * @throws TenantAlreadySetException
     */
    public function setOrganization(Organization $organization): void
    {
        if ($this->organization !== null) {
            if ($this->organization->is($organization)) {
                return;
            }

            throw TenantAlreadySetException::cannotSwitch(
                (int) $this->organization->getKey(),
                (int) $organization->getKey(),
            );
        }

        $this->organization = $organization;
    }

    public function forget(): void
    {
        $this->organization = null;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;

        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
