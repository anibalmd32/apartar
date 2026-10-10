<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * Las organizaciones cuya suscripción acaba en los próximos días y a las que
 * puede tocarles factura de renovación (`REQ-SUB-006` RB-8, `T-069`).
 *
 * Es de plataforma —recorre todas las organizaciones— y por eso vive aquí, uno
 * de los pocos sitios donde `withoutTenantScope()` está permitido
 * (`REQ-QAX-008`, `tenancy:audit`). **Solo responde a quién mirar**: si de
 * verdad toca factura (plan con precio, sin factura ya emitida para ese
 * período, bajada programada a Free…) lo decide `IssueRenewalInvoices` dentro
 * de la organización y con su suscripción bloqueada.
 */
final class RenewableSubscriptions
{
    /** Con cuánta antelación se emite la renovación (RB-8). */
    public const DAYS_AHEAD = 7;

    /**
     * Ids de las organizaciones con el período acabando antes de
     * `$now + DAYS_AHEAD` (incluidos los ya acabados: una pasada que no
     * corrió un día no deja a nadie sin factura).
     *
     * @return list<int>
     */
    public function organizationIds(CarbonImmutable $now): array
    {
        return Subscription::query()
            ->withoutTenantScope()
            ->whereNotNull('current_period_end')
            // Un día de margen: el corte fino es por día del negocio, y lo
            // hace `IssueRenewalInvoices` en la zona de cada organización.
            ->where('current_period_end', '<=', $now->addDays(self::DAYS_AHEAD + 1))
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
