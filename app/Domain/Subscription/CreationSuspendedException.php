<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

/**
 * La suscripción está suspendida: no se crea nada, haya cupo o no
 * (`REQ-SUB-004` RB-8, `REQ-SUB-007`).
 *
 * Es una comprobación **anterior** a la del contador y por eso tiene su propia
 * excepción: decirle a quien está suspendido que «alcanzó el límite de su
 * plan» sería mentirle sobre qué tiene que arreglar, y lo mandaría a comparar
 * planes en vez de a pagar lo que debe.
 *
 * `EnsureOrganizationIsActive` ya devuelve `403` en las rutas del backoffice
 * que no declaran `during-suspension`, así que en la web esto casi nunca se
 * lanza. Casi: un job, un comando o una acción invocada desde otra superficie
 * no pasan por ese middleware, y `LimitEnforcer` es el único punto de decisión
 * (RB-1) precisamente para que la regla valga en todos los caminos y no solo
 * en el que tiene un middleware delante.
 */
final class CreationSuspendedException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (`REQ-ORG-008` SP-3).
     */
    public const STATUS = 403;

    public const MESSAGE = 'Tu suscripción está suspendida por falta de pago: puedes cobrar y aprobar '
        .'comprobantes, pero no crear cosas nuevas. Regulariza el pago desde Suscripción.';

    public static function whileCreating(UsageMetric $metric): self
    {
        return new self(sprintf('%s (intentando consumir %s).', self::MESSAGE, $metric->label()));
    }
}
