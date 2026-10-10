<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Export\ExportStatus;
use App\Models\ExportJob;
use Carbon\CarbonImmutable;

/**
 * Las organizaciones con exportaciones vencidas por purgar (`REQ-BOF-043`
 * RB-7, `T-070`).
 *
 * De plataforma —recorre todas— y por eso aquí, donde `tenancy:audit` admite
 * `withoutTenantScope()`. Solo dice a quién mirar: la purga ocurre dentro de
 * cada organización (`PurgeExpiredExports`).
 */
final class ExpiredExports
{
    /**
     * @return list<int>
     */
    public function organizationIds(CarbonImmutable $now): array
    {
        return ExportJob::query()
            ->withoutTenantScope()
            ->where('status', ExportStatus::Completed)
            ->where('expires_at', '<=', $now)
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
