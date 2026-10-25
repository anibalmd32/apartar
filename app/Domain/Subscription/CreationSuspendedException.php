<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

final class CreationSuspendedException extends RuntimeException
{
    public const STATUS = 403;

    public const MESSAGE = 'Tu suscripción está suspendida por falta de pago: puedes cobrar y aprobar '
        .'comprobantes, pero no crear cosas nuevas. Regulariza el pago desde Suscripción.';

    public static function whileCreating(UsageMetric $metric): self
    {
        return new self(sprintf('%s (intentando consumir %s).', self::MESSAGE, $metric->label()));
    }
}
