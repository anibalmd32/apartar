<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Por qué se emitió una factura (`REQ-SUB-006`, `T-069`).
 *
 * Se guarda porque cada una tiene su propio vencimiento: la de un cambio de
 * plan vence el mismo día —el plan ya se está usando (`REQ-SUB-005` RB-2)—,
 * la de una renovación al fin del período que paga (RB-8), y la manual la
 * fija quien la emite (`REQ-PLT-004` RB-5, `T-086`).
 */
enum InvoiceKind: string
{
    case PlanChange = 'plan_change';
    case Renewal = 'renewal';
    case Manual = 'manual';

    /**
     * Cómo se nombra en el historial de facturas.
     */
    public function label(): string
    {
        return match ($this) {
            self::PlanChange => 'Cambio de plan',
            self::Renewal => 'Renovación',
            self::Manual => 'Emitida por la plataforma',
        };
    }
}
