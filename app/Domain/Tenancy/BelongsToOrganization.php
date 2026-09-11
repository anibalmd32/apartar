<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aísla un modelo por organización: global scope al leer, relleno al crear.
 *
 * Lo usan todos los modelos del tenant. Quitar el scope exige
 * `withoutTenantScope()` explícito, permitido solo en `app/Queries/Platform`,
 * `app/Actions/Platform` y comandos de mantenimiento (`REQ-ORG-002` RB-1).
 *
 * El relleno al crear (RB-2) no es una comodidad, es lo que hace **imposible**
 * equivocarse: si el código de aplicación pudiera asignar `organization_id`,
 * tarde o temprano alguien lo asignaría mal y el error sería silencioso,
 * porque la fila quedaría guardada en la organización equivocada sin que nada
 * lanzase. Asignarlo a mano a otra organización lanza
 * `CrossTenantRelationException`; asignarlo a la activa es redundante pero
 * inofensivo.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    /**
     * Registra el aislamiento en el modelo.
     *
     * Eloquent llama a este método una vez por clase, al arrancarla.
     */
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
     * Organización a la que pertenece la fila.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, TenantScope::COLUMN);
    }

    /**
     * Consulta sin el filtro por organización.
     *
     * Permitido **solo** en consultas de plataforma y comandos de
     * mantenimiento (RB-1, SP-4). Que sea explícito y rastreable por búsqueda
     * es justamente el punto: el gate de `REQ-QAX-008` comprueba dónde
     * aparece.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
