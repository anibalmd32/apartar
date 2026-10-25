<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->hasOrganization()) {
                throw MissingTenantContextException::whileCreating($model::class);
            }

            $current = $context->organizationId();
            $assigned = $model->getAttribute(TenantScope::COLUMN);

            if ($assigned !== null && (int) $assigned !== $current) {
                throw CrossTenantRelationException::whileCreating($model::class, (int) $assigned, $current);
            }

            $model->setAttribute(TenantScope::COLUMN, $current);
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, TenantScope::COLUMN);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
