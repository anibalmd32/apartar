<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageDrift;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * El recálculo del consumo de toda la plataforma (`REQ-SUB-003` RB-4, SP-1,
 * SP-7, `T-066`): a diario a las 02:00, todas las métricas; y cada hora, solo
 * `catalogs`, que bajan solos al caducar (`UsageMetric::shrinksWithTime()`).
 *
 * **Por lotes de organizaciones, no una a una** (SP-7): `chunkById` de 200, y
 * por cada lote `UsageCalculator::recalculateMany()` hace una consulta agregada
 * por métrica para las 200. No usa `RunsForEveryOrganization`, que fija el
 * tenant de cada organización en su turno y llevaría a seis consultas por
 * organización —60.000 para 10.000 negocios—: las consultas agregadas cruzan
 * organizaciones desde `app/Queries/Platform`, acotadas a los ids del lote, y
 * ningún tenant queda fijado ([90] RB-1b, «por lote»).
 *
 * Recorre **todas** las organizaciones, también suspendidas y cerradas: es
 * integridad del contador, no una operación de negocio, y un contador
 * equivocado en una suspendida es el que decide el día que pague.
 *
 * Una pasada **del mismo alcance** a la vez, compartiendo candado con el
 * comando. No por seguridad —dos pasadas simultáneas se serializan solas en
 * las filas de los contadores, y la segunda mediría lo mismo— sino por no
 * hacer dos veces el mismo trabajo. La del catálogo y la completa no se
 * excluyen: la de las 02:00 no puede saltarse porque a esa hora estuviera
 * corriendo la de los catálogos.
 */
class RecalculateUsage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Una pasada completa cabe de sobra; si el proceso muere, el candado caduca. */
    private const LOCK_SECONDS = 3600;

    public function __construct(
        public readonly ?int $organizationId = null,
        public readonly ?UsageMetric $metric = null,
    ) {
        // [90] RB-1: va en `telemetry`, la última de la prioridad. Que la
        // cifra se cuadre unos minutos más tarde no le cuesta nada a nadie;
        // que se retrasen una notificación o una exportación, sí.
        $this->onQueue('telemetry');
    }

    /**
     * El candado de este alcance, que comparten el job y el comando.
     *
     * Con la organización dentro: una pasada del soporte sobre un solo negocio
     * no puede hacer que la de las 02:00 se salte a todos los demás.
     */
    public static function lockFor(?UsageMetric $metric, ?int $organizationId = null): string
    {
        return 'subscriptions:recalculate-usage:'.($metric->value ?? 'all').':'.($organizationId ?? 'all').':running';
    }

    /**
     * @return array{organizations: int, drifts: list<UsageDrift>}|null `null` si había otra pasada en curso
     */
    public function handle(UsageCalculator $usage): ?array
    {
        $lock = Cache::lock(self::lockFor($this->metric, $this->organizationId), self::LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('usage.recalculation_skipped', ['reason' => 'otra pasada en curso', 'metric' => $this->metric?->value]);

            return null;
        }

        try {
            return $this->run($usage);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{organizations: int, drifts: list<UsageDrift>}
     */
    private function run(UsageCalculator $usage): array
    {
        $organizations = 0;
        $drifts = [];

        // `withTrashed()`: una organización cerrada sigue teniendo contadores,
        // y el docblock promete recorrerlas todas.
        Organization::query()
            ->withTrashed()
            ->select('id')
            ->when($this->organizationId !== null, fn ($query) => $query->whereKey($this->organizationId))
            ->chunkById(UsageCalculator::BATCH, function (Collection $batch) use ($usage, &$organizations, &$drifts): void {
                $ids = $batch->map(fn (Organization $organization): int => (int) $organization->getKey())->values()->all();

                $organizations += count($ids);
                array_push($drifts, ...$usage->recalculateMany($ids, $this->metric));
            });

        Log::info('usage.recalculated', [
            'metric' => $this->metric->value ?? 'all',
            'organizations' => $organizations,
            'drifts' => count($drifts),
        ]);

        return ['organizations' => $organizations, 'drifts' => $drifts];
    }
}
