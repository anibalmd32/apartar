<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Subscription\UsageCalculator;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;

/**
 * Cuadra los contadores de consumo con lo que hay en las tablas.
 *
 * Desde `T-066`, `LimitEnforcer` decide y pinta con el **valor
 * materializado** (`REQ-SUB-003` RB-1), y las factories no lo mueven: crear
 * tres usuarios con `User::factory()` deja `users` donde estaba. Un test que
 * siembra con factories y después pregunta por el plan tiene que cuadrar
 * antes, con el mismo recálculo que corre cada noche —no con un `UPDATE` a
 * mano, que sería una segunda definición de cada métrica—.
 */
trait RecalculatesUsage
{
    /**
     * @param  Organization|null  $organization  la activa si no se dice
     */
    protected function recalculateUsage(?Organization $organization = null): void
    {
        $organization ??= app(TenantContext::class)->organization();

        app(UsageCalculator::class)->recalculate($organization);
    }
}
