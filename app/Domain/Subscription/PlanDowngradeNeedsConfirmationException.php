<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

final class PlanDowngradeNeedsConfirmationException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct(public readonly PlanChangePreview $preview)
    {
        parent::__construct(sprintf(
            'Con el plan %s quedarías por encima de algún límite. Revisa lo que cambia y confírmalo para programar la bajada.',
            $preview->target->name,
        ));
    }
}
