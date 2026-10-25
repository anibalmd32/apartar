<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class CrossTenantRelationException extends RuntimeException
{
    /**
     * @param  class-string  $model
     */
    public static function whileCreating(string $model, int $assigned, int $current): self
    {
        return new self(sprintf(
            'Se intentó crear %s con organization_id %d mientras el tenant activo es el %d. '
            .'El trait rellena la columna: no la asignes a mano.',
            $model,
            $assigned,
            $current,
        ));
    }

    /**
     * @param  class-string  $model
     * @param  class-string  $related
     */
    public static function between(string $model, string $related): self
    {
        return new self(sprintf(
            'No se puede relacionar %s con %s: pertenecen a organizaciones distintas.',
            $model,
            $related,
        ));
    }
}
