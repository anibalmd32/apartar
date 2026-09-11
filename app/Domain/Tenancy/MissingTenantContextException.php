<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * Se intentó tocar una tabla del tenant sin organización fijada.
 *
 * Es la excepción que convierte una fuga en un fallo. `REQ-ORG-002` SP-3 lo
 * dice sin rodeos: **es preferible un job caído a una fuga**. El global scope
 * no puede "no filtrar" cuando no sabe por qué filtrar, porque el resultado
 * sería devolver los datos de todas las organizaciones y que nadie se entere.
 */
final class MissingTenantContextException extends RuntimeException
{
    /**
     * Una consulta sobre un modelo del tenant sin organización fijada.
     *
     * @param  class-string  $model  modelo que se estaba consultando
     */
    public static function whileQuerying(string $model): self
    {
        return new self(sprintf(
            'Consulta sobre %s sin organización fijada. Fija el tenant con TenantContext::setOrganization() '
            .'o pide explícitamente withoutTenantScope() si es una consulta de plataforma.',
            $model,
        ));
    }

    /**
     * Una creación sobre un modelo del tenant sin organización fijada.
     *
     * @param  class-string  $model  modelo que se estaba creando
     */
    public static function whileCreating(string $model): self
    {
        return new self(sprintf(
            'Creación de %s sin organización fijada. El tenant lo rellena el trait, nunca el código de '
            .'aplicación: fíjalo antes con TenantContext::setOrganization() o con runFor().',
            $model,
        ));
    }

    /**
     * Se pidió la organización activa y no había ninguna.
     */
    public static function whileReading(): self
    {
        return new self(
            'No hay organización fijada en el TenantContext. En una petición la fija su middleware de '
            .'resolución; en un job, el propio job desde el tenant que viaja en su payload.',
        );
    }
}
