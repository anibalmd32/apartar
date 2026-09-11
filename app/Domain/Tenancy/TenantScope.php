<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope que filtra toda consulta de una tabla del tenant por
 * `organization_id` (`REQ-ORG-002` RB-1).
 *
 * Lo aplica `BelongsToOrganization`; quitarlo exige `withoutTenantScope()`
 * explícito, permitido solo en `app/Queries/Platform`, `app/Actions/Platform`
 * y comandos de mantenimiento.
 *
 * **Sin organización fijada lanza**, no devuelve todo (SP-3). Es la decisión
 * central del aislamiento: un scope que se desactiva solo cuando no sabe por
 * qué filtrar es exactamente un scope que no protege, y el fallo sería
 * silencioso porque la consulta devolvería filas válidas — solo que de todas
 * las organizaciones a la vez.
 */
final class TenantScope implements Scope
{
    /**
     * Columna que ata una fila a su organización.
     *
     * Vive aquí y no en el trait porque PHP no deja leer una constante a
     * través del nombre de un trait, y esta la tienen que nombrar exactamente
     * igual el propio trait, este scope y el `tenancy:audit` de `T-007`.
     */
    public const COLUMN = 'organization_id';

    /**
     * @param  Builder<Model>  $builder
     *
     * @throws MissingTenantContextException si no hay organización fijada
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
