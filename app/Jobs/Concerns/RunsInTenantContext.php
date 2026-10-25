<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait RunsInTenantContext
{
    abstract public function tenantOrganizationId(): int;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws ModelNotFoundException
     */
    protected function runInTenantContext(Closure $callback): mixed
    {
        $organization = app(TenantResolver::class)->forOrganizationId($this->tenantOrganizationId());

        $context = app(TenantContext::class);

        $context->forget();
        $context->setOrganization($organization);

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
