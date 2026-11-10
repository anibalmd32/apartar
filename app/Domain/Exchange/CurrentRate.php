<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Closure;

final class CurrentRate
{
    private int|false|null $resolvedFor = false;

    /**
     * @var array<string, RateSnapshot|null>
     */
    private array $snapshots = [];

    private ?RateSource $preferred = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
    ) {}

    public function prefer(?RateSource $source): void
    {
        $this->preferred = $source;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function using(?RateSource $source, Closure $callback): mixed
    {
        $previous = $this->preferred;
        $this->preferred = $source ?? $previous;

        try {
            return $callback();
        } finally {
            $this->preferred = $previous;
        }
    }

    public function snapshot(?RateSource $source = null): ?RateSnapshot
    {
        $organization = $this->organization();
        $organizationId = $organization?->getKey() === null ? null : (int) $organization->getKey();

        if ($this->resolvedFor !== $organizationId) {
            $this->snapshots = [];
            $this->resolvedFor = $organizationId;
        }

        $source ??= $this->preferred;
        $key = $source === null ? '' : $source->value;

        if (! array_key_exists($key, $this->snapshots)) {
            $this->snapshots[$key] = $this->rates->current($organization, $source);
        }

        return $this->snapshots[$key];
    }

    public function view(?RateSource $source = null): RateView
    {
        return RateView::of($this->snapshot($source), $this->source($source));
    }

    private function source(?RateSource $source): RateSource
    {
        $source ??= $this->preferred;

        if ($source !== null) {
            return $source;
        }

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
