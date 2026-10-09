<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Queries\Platform\UsageAggregates;
use App\Queries\Platform\UsageCounters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Mide el consumo de verdad y lo deja escrito en `usage_counters`
 * (`REQ-SUB-003` RB-1, RB-4).
 *
 * El contador materializado es **lo único que leen** `LimitEnforcer` para
 * decidir y las pantallas para pintar. Lo mantienen dos cosas a la vez:
 *
 *  - **cada mutación, en su propia transacción** (RB-3): las métricas exactas
 *    con `LimitEnforcer::adjust()`, que suma o resta con la fila bloqueada;
 *    la derivada —`storage_mb`— con `refresh()` en cuanto el archivo existe;
 *  - **este recálculo**, que cada noche mide todo desde cero y corrige lo que
 *    no cuadre (RB-4), y cada hora los catálogos, que caducan solos.
 *
 * ## Por qué las métricas exactas se incrementan y no se miden
 *
 * Medir dentro de la transacción de una mutación **no ve lo que confirmó otra**
 * en `REPEATABLE READ`: la instantánea se toma en la primera lectura sin
 * bloqueo, que en una Action ya ocurrió al cargar el modelo, y dos
 * invitaciones rivales escribirían las dos «2» en vez de «3». El incremento
 * con la fila bloqueada no tiene ese problema —lee lo último confirmado,
 * porque es una lectura con bloqueo—, y lo que deja suelto es lo que este
 * recálculo cuadra.
 *
 * ## Por qué este recálculo sí puede medir
 *
 * Porque abre **su propia transacción** y lo primero que hace en ella es
 * bloquear los contadores del lote. Esa lectura con bloqueo espera a quien
 * tuviera la fila y no abre la instantánea; la abre la primera agregación, que
 * por tanto ve todo lo que confirmaron los que esperaban delante. Y como los
 * contadores siguen bloqueados hasta el `COMMIT`, ninguna mutación puede
 * moverlos entre la medida y la escritura: no se pierde ningún incremento.
 *
 * Por eso **se llama fuera de cualquier transacción**: dentro de otra, la
 * instantánea ya estaría tomada y el valor podría nacer viejo.
 */
final class UsageCalculator
{
    /** Organizaciones por lote del recálculo masivo (SP-7). */
    public const BATCH = 200;

    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageAggregates $aggregates,
        private readonly UsageCounters $counters,
    ) {}

    /**
     * Recalcula desde cero una organización, o una sola de sus métricas, y
     * registra lo que no cuadraba (SP-1).
     */
    public function recalculate(Organization $organization, ?UsageMetric $metric = null): void
    {
        $this->recalculateMany([(int) $organization->getKey()], $metric);
    }

    /**
     * Vuelve a medir una métrica que acaba de moverse por algo que el
     * contador no podía seguir con un incremento, **sin** tratarlo como deriva.
     *
     * Es el movimiento de RB-3 para `storage_mb` (RB-5): un comprobante subido,
     * una imagen recomprimida, una imagen borrada. Se llama **después del
     * `COMMIT`** de lo que cambió el archivo, por lo que dice el docblock de la
     * clase.
     */
    public function refresh(Organization $organization, UsageMetric $metric): void
    {
        $this->recalculateMany([(int) $organization->getKey()], $metric, logsDrift: false);
    }

    /**
     * Lo que consume hoy la organización activa, medido en sus tablas y sin
     * tocar el contador.
     *
     * Es el valor con el que nace cada contador (`REQ-SUB-002` RB-5): en el
     * alta, el `owner` ya existe, y un `users` a cero lo contradiría desde el
     * primer día.
     */
    public function measure(UsageMetric $metric): int
    {
        $organization = $this->context->organizationId();

        return $this->aggregates->measure($metric, [$organization])[$organization];
    }

    /**
     * Deja exacto el contador de una métrica de la organización activa,
     * **dentro de la transacción de quien llama** y con el contador bloqueado
     * hasta su `COMMIT`.
     *
     * Es para `catalogs`, la que baja sola con el reloj
     * (`UsageMetric::shrinksWithTime()`): un catálogo caducado a las 10:00
     * sigue en el contador hasta la pasada horaria, y republicarlo a las 10:05
     * sumaría uno sobre una cifra que ya lo contaba —y con el cupo agotado,
     * `T-067` respondería `422` a quien no tiene ningún catálogo vivo—. Así
     * que publicar y despublicar **empiezan** por aquí, y después se mueven
     * con `adjust()` como las demás.
     *
     * **Puede medir dentro de la transacción** —lo que el docblock de la clase
     * desaconseja— porque mide **con una lectura con bloqueo compartido**, que
     * en InnoDB lee lo último confirmado y no la instantánea. Y por eso **tiene
     * que ir lo primero** de la transacción, antes de bloquear ninguna fila de
     * lo que se cuenta: con el contador cogido antes, dos publicaciones se
     * serializan en él y ninguna espera una fila que la otra ya tenga.
     *
     * @throws LogicException fuera de una transacción
     */
    public function recountLocked(UsageMetric $metric): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(sprintf(
                'recountLocked(%s) se llamó fuera de una transacción: el contador se recuenta bloqueado dentro '
                .'de la transacción que lo va a mover, o la cifra puede cambiar antes de usarla.',
                $metric->value,
            ));
        }

        $organization = $this->context->organizationId();
        $counter = $this->counters->lock([$organization], $metric)->get(UsageCounters::key($organization, $metric));
        $now = CarbonImmutable::now();
        $measured = max(0, $this->aggregates->measure($metric, [$organization], $now, locking: true)[$organization]);

        if ($counter === null) {
            $this->counters->create($organization, $metric, $measured, $now);
        } elseif ($counter->value !== $measured) {
            $this->counters->correct($counter, $measured, $now);
        }

        return $measured;
    }

    /**
     * Recalcula un lote de organizaciones: **una consulta agregada por
     * métrica para todo el lote** (SP-7), los contadores bloqueados mientras
     * tanto, y una escritura por contador que no cuadraba.
     *
     * Lo medido se acota a cero (RB-8): ninguna agregación lo da negativo,
     * pero el contador es lo que hace cumplir el plan, y un valor imposible
     * no se escribe aunque hoy no pueda salir.
     *
     * @param  list<int>  $organizationIds
     * @param  bool  $logsDrift  `false` cuando la diferencia es el movimiento esperado
     * @return list<UsageDrift> los contadores corregidos que eran deriva
     */
    public function recalculateMany(array $organizationIds, ?UsageMetric $metric = null, bool $logsDrift = true): array
    {
        $metrics = $metric === null ? UsageMetric::cases() : [$metric];

        $drifts = DB::transaction(function () use ($organizationIds, $metrics, $metric, $logsDrift): array {
            // Primero el bloqueo, después la medida: ver el docblock. Y la
            // hora, **después** del bloqueo: la espera puede durar lo que
            // `innodb_lock_wait_timeout`, y un catálogo que caducara durante
            // ella seguiría contándose con una hora de antes.
            $stored = $this->counters->lock($organizationIds, $metric);
            $now = CarbonImmutable::now();

            $drifts = [];
            $untouched = [];

            foreach ($metrics as $each) {
                foreach ($this->aggregates->measure($each, $organizationIds, $now) as $organization => $measured) {
                    $measured = max(0, $measured);
                    $counter = $stored->get(UsageCounters::key($organization, $each));

                    if ($counter === null) {
                        $this->counters->create($organization, $each, $measured, $now);

                        continue;
                    }

                    if ($counter->value === $measured) {
                        $untouched[] = (int) $counter->getKey();

                        continue;
                    }

                    $this->counters->correct($counter, $measured, $now);

                    $drift = new UsageDrift($organization, $each, $counter->value, $measured);

                    if ($logsDrift && $drift->isUnexpected()) {
                        $drifts[] = $drift;
                    }
                }
            }

            $this->counters->stamp($untouched, $now);

            return $drifts;
        }, attempts: 3);

        // Fuera de la transacción: un reintento por interbloqueo no puede
        // dejar la misma deriva escrita dos veces en el registro.
        foreach ($drifts as $drift) {
            Log::warning('usage_counter.drift', $drift->toArray());
        }

        return $drifts;
    }
}
