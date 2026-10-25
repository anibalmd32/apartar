<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class MissingTenantContextException extends RuntimeException
{
    /**
     * @param  class-string  $model
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
     * @param  class-string  $model
     */
    public static function whileCreating(string $model): self
    {
        return new self(sprintf(
            'Creación de %s sin organización fijada. El tenant lo rellena el trait, nunca el código de '
            .'aplicación: fíjalo antes con TenantContext::setOrganization() o con runFor().',
            $model,
        ));
    }

    public static function whileReading(): self
    {
        return new self(
            'No hay organización fijada en el TenantContext. En una petición la fija su middleware de '
            .'resolución; en un job, el propio job desde el tenant que viaja en su payload.',
        );
    }
}
