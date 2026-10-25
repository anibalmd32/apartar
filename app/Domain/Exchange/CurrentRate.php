<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;

final class CurrentRate
{
    private ?int $resolvedFor = null;

    private bool $resolved = false;

    private ?RateSnapshot $snapshot = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
    ) {}

    public function snapshot(): ?RateSnapshot
    {
        $organization = $this->organization();
        $organizationId = $organization?->getKey() === null ? null : (int) $organization->getKey();

        if ($this->resolved && $this->resolvedFor === $organizationId) {
            return $this->snapshot;
        }

        $this->snapshot = $this->rates->current($organization);
        $this->resolvedFor = $organizationId;
        $this->resolved = true;

        return $this->snapshot;
    }

    public function view(): RateView
    {
        return RateView::of($this->snapshot(), $this->source());
    }

    private function source(): RateSource
    {
        $organization = $this->organization();

        return $organization === null
            ? RateSource::Bcv
            : $this->rates->sourceFor($organization);
    }

    private function organization(): ?Organization
    {
        return $this->context->hasOrganization() ? $this->context->organization() : null;
    }
}
