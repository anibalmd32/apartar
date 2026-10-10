<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceipts;
use App\Models\SubscriptionReceipt;

/**
 * El bloqueo de duplicados de los comprobantes de suscripción, **en toda la
 * plataforma** (`REQ-SUB-006` RB-2, SP-1, revisión de `T-069`).
 *
 * `REQ-PAY-003` RB-4 acota el duplicado a la organización porque cada negocio
 * cobra en sus cuentas: el mismo pago móvil en dos negocios son dos pagos. Aquí
 * **el que cobra es siempre la plataforma**, así que el mismo comprobante
 * subido por dos negocios —del mismo dueño, por ejemplo— es un solo pago, y
 * aprobarlo dos veces pagaría dos suscripciones con una transferencia. La
 * regla es la misma (`DuplicateReceipts`: el archivo y la pareja banco +
 * referencia); lo que cambia es la tabla que mira, y por eso sale del scope
 * del tenant aquí, uno de los sitios donde `tenancy:audit` lo admite.
 *
 * Los únicos de la tabla siguen siendo por organización ([05 §4]: una tabla
 * con `organization_id` no lleva únicos sin ella), así que dos subidas
 * simultáneas del mismo archivo en dos negocios distintos pueden pasar las
 * dos; las ve la plataforma al revisar, y es un caso de fraude deliberado,
 * no un doble clic.
 */
final class SubscriptionReceiptDuplicates
{
    public function criterionFor(string $fileHash, ?string $bank, ?string $reference): ?DuplicateCriterion
    {
        return DuplicateReceipts::criterionFor(SubscriptionReceipt::query()->withoutTenantScope(), $fileHash, $bank, $reference);
    }
}
