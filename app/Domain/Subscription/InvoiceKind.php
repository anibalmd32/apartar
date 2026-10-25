<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum InvoiceKind: string
{
    case PlanChange = 'plan_change';
    case Renewal = 'renewal';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::PlanChange => 'Cambio de plan',
            self::Renewal => 'Renovación',
            self::Manual => 'Emitida por la plataforma',
        };
    }
}
