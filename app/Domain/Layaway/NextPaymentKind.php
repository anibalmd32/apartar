<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

/**
 * Qué es el pago sugerido (`NextPayment`): la inicial o una cuota. Los valores
 * son los que lee el frontend (`kind` en `types/portal.ts` y
 * `types/receipt.ts`).
 */
enum NextPaymentKind: string
{
    case DownPayment = 'down_payment';
    case Installment = 'installment';
}
