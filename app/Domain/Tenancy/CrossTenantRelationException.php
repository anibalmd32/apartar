<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * Se intentó ligar datos de dos organizaciones distintas.
 *
 * `REQ-ORG-002` RB-7 y SP-5: las relaciones entre tablas del tenant nunca
 * cruzan organizaciones. Asignar a un catálogo un producto de otro negocio no
 * es un error de permisos —el binding ya habría dado `404`— sino un dato
 * imposible, y guardarlo dejaría al sistema con una fila que ninguna consulta
 * filtrada por tenant vuelve a ver del todo.
 */
final class CrossTenantRelationException extends RuntimeException
{
    /**
     * Se intentó crear una fila asignada a una organización distinta de la
     * activa.
     *
     * @param  class-string  $model  modelo que se estaba creando
     * @param  int  $assigned  organización que traía la fila
     * @param  int  $current  organización activa
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
     * Dos modelos que deberían compartir organización no la comparten.
     *
     * @param  class-string  $model  modelo que se estaba relacionando
     * @param  class-string  $related  modelo con el que se intentó relacionar
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
