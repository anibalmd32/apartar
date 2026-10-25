<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public const COLUMN = 'organization_id';

    /**
     * @param  Builder<Model>  $builder
     *
     * @throws MissingTenantContextException
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasOrganization()) {
            throw MissingTenantContextException::whileQuerying($model::class);
        }

        $builder->where(
            $model->qualifyColumn(self::COLUMN),
            $context->organizationId(),
        );
    }
}
