<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Las filas de `usage_counters` de **varias** organizaciones a la vez, para el
 * recálculo por lotes de `UsageCalculator` (`REQ-SUB-003` RB-4, SP-7).
 *
 * Está aquí y no en el dominio por lo mismo que `UsageAggregates`: leer y
 * corregir los contadores de doscientas organizaciones en una consulta exige
 * quitar el filtro de tenant, y `app/Queries/Platform` es una de las rutas de
 * la lista cerrada de `REQ-QAX-008` RB-4b. Cada consulta se acota por los ids
 * que recibe.
 */
final class UsageCounters
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Los contadores del lote, **bloqueados** para el resto de la transacción.
     *
     * Es una lectura con bloqueo, así que no abre la instantánea de la
     * transacción: la abre la primera lectura sin bloqueo que venga después
     * —las agregaciones—, y eso es lo que hace que lo medido incluya todo lo
     * que confirmaron quienes tenían la fila antes.
     *
     * @param  list<int>  $organizationIds
     * @return Collection<string, UsageCounter> por `"{organization_id}:{metric}"`
     */
    public function lock(array $organizationIds, ?UsageMetric $metric = null): Collection
    {
        return UsageCounter::query()->withoutTenantScope()
            ->whereIn('organization_id', $organizationIds)
            ->when($metric !== null, fn ($query) => $query->where('metric', $metric))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (UsageCounter $counter): string => self::key($counter->organization_id, $counter->metric));
    }

    /**
     * Crea el contador que faltaba, ya con su valor.
     *
     * Solo puede faltar en una base a medio sembrar (`REQ-SUB-002` RB-5), así
     * que es raro y se paga una consulta por fila. Se crea **bajo el tenant de
     * su organización** para que la fila pase por la misma comprobación que
     * cualquier otra del tenant, en vez de escribir `organization_id` a mano.
     */
    public function create(int $organizationId, UsageMetric $metric, int $value, CarbonImmutable $at): void
    {
        $organization = Organization::query()->withTrashed()->findOrFail($organizationId);

        $this->context->runFor($organization, fn () => UsageCounter::query()->create([
            'metric' => $metric,
            'value' => $value,
            'computed_at' => $at,
        ]));
    }

    /**
     * Escribe el valor medido en un contador que no lo tenía.
     */
    public function correct(UsageCounter $counter, int $value, CarbonImmutable $at): void
    {
        UsageCounter::query()->withoutTenantScope()
            ->whereKey($counter->getKey())
            ->update(['value' => $value, 'computed_at' => $at]);
    }

    /**
     * Sella como medidos ahora los contadores que ya estaban bien, **en una
     * sola escritura** para todo el lote.
     *
     * @param  list<int>  $counterIds
     */
    public function stamp(array $counterIds, CarbonImmutable $at): void
    {
        if ($counterIds === []) {
            return;
        }

        UsageCounter::query()->withoutTenantScope()
            ->whereIn('id', $counterIds)
            ->update(['computed_at' => $at]);
    }

    public static function key(int $organizationId, UsageMetric $metric): string
    {
        return $organizationId.':'.$metric->value;
    }
}
