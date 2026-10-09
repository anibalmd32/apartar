<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Único punto de decisión sobre si una organización puede crear algo más o
 * usar una función de plan ([05 §5], `REQ-SUB-004` RB-1).
 *
 * **Ninguna `Action` comprueba límites por su cuenta.** La regla parece de
 * estilo y no lo es: un techo comparado en seis sitios son seis definiciones
 * de «lleno», y la que se olvida de contar las invitaciones pendientes es la
 * que deja enviar cincuenta en plan Free (`REQ-ORG-004` RB-2).
 *
 * ## El bloqueo, que es lo que hace que esto sirva
 *
 * `assertCanCreate()` lee el contador **bloqueado y dentro de la transacción**
 * de quien va a crear. Sin el bloqueo, dos invitaciones simultáneas con un
 * solo cupo leen ambas «queda uno», ambas concluyen que pueden, y la
 * organización acaba con un usuario de más: el estado que ningún plan admite y
 * del que no se sale solo. La fila de `usage_counters` es el punto de
 * serialización —una por organización y métrica, con `UNIQUE(organization_id,
 * metric)`—, y por eso se bloquea esa y no las filas que se están contando: es
 * la misma para las dos peticiones, cualesquiera que sean las filas que cada
 * una vaya a crear.
 *
 * De ahí que exigir una transacción abierta no sea una comodidad: comprobar en
 * una y escribir en otra deja exactamente el hueco que el bloqueo venía a
 * cerrar, y un `lockForUpdate()` en *autocommit* no bloquea nada más allá de
 * su propia consulta. Llamar sin transacción **lanza**.
 *
 * ## Lo que decide es el contador, y nada más
 *
 * `REQ-SUB-003` RB-1 materializa el consumo en `usage_counters` para que
 * comprobar un límite no cueste seis agregados, y desde `T-066` **el valor
 * materializado es el camino único**: para decidir (`assertCanCreate()`, con
 * la fila bloqueada) y para pintar (`state()`, `usageOf()`). Lo mantienen
 * las mutaciones —con `adjust()`, en su misma transacción (RB-3)— y
 * `UsageCalculator`, que mide de verdad cada noche y corrige la deriva (RB-4).
 *
 * Hasta `T-066` el enforcer volvía a contar `users` y `payment_accounts` en
 * sus tablas porque nadie más mantenía la cifra. Ya no: la medida vive en
 * `UsageCalculator` y en una sola definición (`UsageAggregates`), y contar
 * aquí además sería una segunda definición de «lleno» —y una que, dentro de la
 * transacción de una Action, **no ve lo que acaba de confirmar la rival** en
 * `REPEATABLE READ`—. Lo que serializa es el bloqueo, no el conteo.
 *
 * ## De dónde sale la organización
 *
 * De ningún sitio: de `TenantContext`, a través del global scope, como
 * cualquier otra consulta del tenant (`REQ-ORG-002` RB-1). Por eso esta clase
 * no recibe la organización ni la pregunta —`Subscription::query()`,
 * `User::query()` y `UsageCounter::query()` ya filtran—, y por eso llamarla sin
 * tenant fijado lanza `MissingTenantContextException` en la primera consulta en
 * vez de hacer cumplir el plan de otro.
 */
final class LimitEnforcer
{
    /**
     * Exige que quepan `$amount` unidades más de esa métrica.
     *
     * Debe llamarse **dentro de la transacción** que va a crear, y antes de
     * crear nada.
     *
     * @throws LogicException si no hay transacción abierta
     * @throws CreationSuspendedException si la suscripción no admite crear (RB-8)
     * @throws PlanLimitReachedException con metric, used, limit y minimumPlan (RB-5)
     */
    public function assertCanCreate(UsageMetric $metric, int $amount = 1): void
    {
        $this->assertInsideTransaction('assertCanCreate', $metric);

        $subscription = $this->subscription();

        // RB-8 · con la suscripción suspendida no se crea nada, tenga el
        // contador lo que tenga. Va antes que el bloqueo: no hay por qué
        // serializar a nadie para decirle que no.
        if (! $subscription->status->allowsCreation()) {
            throw CreationSuspendedException::whileCreating($metric);
        }

        $plan = $subscription->plan;
        $limit = $plan->limitFor($metric);

        // SP-5 · `null` es ilimitado y no bloquea nada. Tampoco bloquea el
        // contador: sin techo contra el que comparar, serializar dos
        // creaciones sería pagar un bloqueo por una pregunta ya respondida.
        if ($limit === null) {
            return;
        }

        $used = $this->lockedUsage($metric);

        if ($used + $amount > $limit) {
            throw PlanLimitReachedException::of(
                metric: $metric,
                used: $used,
                limit: $limit,
                amount: $amount,
                current: $plan,
                minimum: $this->cheapestPlanAdmitting($metric, $used + $amount),
            );
        }
    }

    /**
     * Exige que el plan incluya esa función.
     *
     * @throws PlanFeatureUnavailableException con feature y minimumPlan
     */
    public function assertHasFeature(PlanFeature $feature): void
    {
        $plan = $this->subscription()->plan;

        if ($plan->hasFeature($feature)) {
            return;
        }

        throw PlanFeatureUnavailableException::of(
            feature: $feature,
            current: $plan,
            minimum: $this->cheapestPlanWith($feature),
        );
    }

    /**
     * Unidades que quedan de una métrica, o `null` si es ilimitada.
     *
     * Lectura **sin bloqueo**: es para pintar y para decidir si se ofrece un
     * botón, no para autorizar. Entre esta lectura y la escritura cabe otra
     * petición, y por eso `assertCanCreate()` vuelve a mirar con el bloqueo
     * puesto.
     */
    public function remaining(UsageMetric $metric): ?int
    {
        return $this->usageOf($metric)->remaining();
    }

    public function hasFeature(PlanFeature $feature): bool
    {
        return $this->plan()->hasFeature($feature);
    }

    /**
     * El plan vigente de la organización activa, para quien tiene que
     * preguntarle más de una cosa.
     *
     * Lo necesita la vista pública del catálogo (`REQ-CAT-021` RB-9), que pinta
     * a la vez si se aplica la marca del negocio y si va el sello «Hecho con
     * Apartar»: son las dos caras de la misma función (`Plan::showsPlatformBadge()`),
     * y leerlas con dos `hasFeature()` serían dos consultas de la suscripción
     * para una sola respuesta.
     *
     * Lectura, no autorización: para negar algo están `assertHasFeature()` y
     * `assertCanCreate()`.
     */
    public function plan(): Plan
    {
        return $this->subscription()->plan;
    }

    /**
     * Consumo de las seis métricas y funciones activas, para las page props
     * (`REQ-SUB-003` RB-6).
     */
    public function state(): PlanState
    {
        $subscription = $this->subscription();
        $usage = [];

        foreach (UsageMetric::cases() as $metric) {
            $usage[$metric->value] = $this->usageOf($metric, $subscription);
        }

        return PlanState::of($subscription, $subscription->plan, $usage);
    }

    /**
     * Estado de una métrica: lo consumido frente al techo del plan.
     *
     * Lo consumido es el valor materializado, sin bloqueo: es lo mismo que
     * decide `assertCanCreate()`, y por eso la pantalla nunca dice «queda uno»
     * a quien después recibe el `422`.
     */
    public function usageOf(UsageMetric $metric, ?Subscription $subscription = null): UsageState
    {
        $subscription ??= $this->subscription();

        return UsageState::of(
            $metric,
            $this->materialisedUsage($metric),
            $subscription->plan->limitFor($metric),
        );
    }

    /**
     * Mueve el contador de una métrica **incremental** en `$delta` unidades,
     * y devuelve el valor que queda escrito.
     *
     * Es el movimiento de `REQ-SUB-003` RB-3 para las cinco métricas exactas:
     * lo llama cada Action que crea, borra o cambia de estado algo que cuenta,
     * con la diferencia que su escritura produjo —`+1` al invitar, `-1` al
     * archivar, `0` al editar un catálogo que ya estaba publicado—. Quien
     * corrige la deriva que se le escape es `UsageCalculator`; esto es la
     * cuenta del día a día.
     *
     * **La diferencia se calcula con la fila de lo que cambia bloqueada**, no
     * con el modelo que llegó de la petición: dos desactivaciones simultáneas
     * del mismo miembro leerían las dos «activo», restarían las dos, y el
     * contador quedaría una unidad por debajo para siempre.
     *
     * Con la fila **bloqueada y dentro de la transacción** de la mutación, por
     * lo mismo que `assertCanCreate()`: dos movimientos simultáneos leerían el
     * mismo valor y uno de los dos se perdería. Fuera de una transacción lanza.
     *
     * **Nunca deja el contador negativo** (`REQ-SUB-003` RB-8, `REQ-LAY-005`
     * SP-7). Liberar con el contador en cero no es un error del que liberó
     * —el cupo se liberó—, es una deriva de quien no lo subió, así que queda
     * en cero, se registra la anomalía con cuánto faltaba, y la operación
     * sigue: un apartado no puede dejar de completarse porque un contador esté
     * descuadrado.
     *
     * **No pregunta por la suscripción.** Mover lo que ya se consumió no es
     * crear: una organización suspendida sigue completando y cancelando
     * apartados (`REQ-SUB-007` RB-3), y su contador tiene que seguirlos.
     *
     * @throws LogicException si no hay transacción abierta, o la métrica es derivada
     */
    public function adjust(UsageMetric $metric, int $delta): int
    {
        $this->assertInsideTransaction('adjust', $metric);

        // `storage_mb` se agrega, no se incrementa (RB-5): lo mueven
        // conversiones y purgas asíncronas y un incremento se desincronizaría.
        if ($metric->isDerived()) {
            throw new LogicException(sprintf(
                'adjust(%s): esa métrica es derivada y se recalcula por agregación (REQ-SUB-003 RB-5), no por incremento.',
                $metric->value,
            ));
        }

        $counter = $this->counterFor($metric, locked: true);
        $wanted = $counter->value + $delta;
        $value = max(0, $wanted);

        if ($wanted < 0) {
            Log::warning('usage_counter.below_zero', [
                'metric' => $metric->value,
                'organization_id' => $counter->organization_id,
                'previous' => $counter->value,
                'delta' => $delta,
            ]);
        }

        if ($value !== $counter->value) {
            $counter->forceFill(['value' => $value, 'computed_at' => now()])->save();
        }

        return $value;
    }

    /**
     * Coge la fila del contador para el resto de la transacción, sin moverla.
     *
     * Para fijar el **orden de bloqueo** de una métrica cuando la mutación
     * tiene que bloquear también otras filas. Con `users` es lo primero que
     * hacen todas las que lo mueven (invitar, revocar, desactivar, reactivar,
     * borrar la propia cuenta): desactivar coge además las filas de los
     * `owner` (`LastOwnerGuard`) y su hueco en el índice, que es donde invitar
     * inserta, y con el orden al revés las dos se esperaban en cruz. Contador
     * primero, filas después, en todas: se serializan en el contador y nadie
     * espera lo que otro ya tiene.
     *
     * @throws LogicException si no hay transacción abierta
     */
    public function lockCounter(UsageMetric $metric): void
    {
        $this->assertInsideTransaction('lockCounter', $metric);

        $this->counterFor($metric, locked: true);
    }

    // -------------------------------------------------------------------
    // El contador
    // -------------------------------------------------------------------

    /**
     * Valor materializado, con la fila del contador bloqueada para el resto
     * de la transacción.
     */
    private function lockedUsage(UsageMetric $metric): int
    {
        return $this->counterFor($metric, locked: true)->value;
    }

    /**
     * Valor materializado, sin bloquear.
     */
    private function materialisedUsage(UsageMetric $metric): int
    {
        return (int) (UsageCounter::query()->where('metric', $metric)->value('value') ?? 0);
    }

    /**
     * Fila del contador de esa métrica, creándola si faltara.
     *
     * Las seis nacen en cero con la suscripción (`REQ-SUB-002` RB-5), así que
     * faltar solo puede faltar en una base a medio sembrar. Se crea en vez de
     * lanzar porque una fila de contador ausente y una en cero significan lo
     * mismo, y porque **la fila tiene que existir para poder bloquearla**: un
     * `lockForUpdate()` que no encuentra nada no serializa a nadie, y el fallo
     * sería silencioso justo donde más caro sale.
     */
    private function counterFor(UsageMetric $metric, bool $locked = false): UsageCounter
    {
        $query = UsageCounter::query()->where('metric', $metric);

        if ($locked) {
            $query->lockForUpdate();
        }

        $counter = $query->first();

        if ($counter instanceof UsageCounter) {
            return $counter;
        }

        // Una fila recién insertada ya está bloqueada en exclusiva por esta
        // transacción, así que crear **es** bloquear y no hace falta releer.
        return UsageCounter::query()->create([
            'metric' => $metric,
            'value' => 0,
            'computed_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------
    // El plan
    // -------------------------------------------------------------------

    /**
     * Suscripción de la organización activa, con su plan cargado.
     *
     * Una organización siempre tiene exactamente una (`REQ-SUB-002` RB-1), así
     * que no existir no es un caso de negocio: es una base a medio sembrar, y
     * `firstOrFail()` lo dice donde ocurre en vez de dejar que reviente tres
     * llamadas más allá.
     */
    private function subscription(): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->with('plan')->firstOrFail();

        return $subscription;
    }

    /**
     * Plan más barato del escaparate cuyo techo admite ese consumo (RB-5).
     *
     * Se recorre por `position`, que es el orden en que se comparan los planes
     * en `/precios`, y **nunca se compara contra el código de un plan**
     * (`REQ-SUB-001` RB-1): se pregunta por el techo de la métrica. El día que
     * la matriz cambie, el plan que se ofrece cambia con ella.
     */
    private function cheapestPlanAdmitting(UsageMetric $metric, int $needed): ?Plan
    {
        return Plan::query()->public()->get()->first(
            fn (Plan $plan): bool => $plan->limitFor($metric) === null || $plan->limitFor($metric) >= $needed,
        );
    }

    /**
     * Plan más barato del escaparate que incluye esa función (RB-5).
     */
    private function cheapestPlanWith(PlanFeature $feature): ?Plan
    {
        return Plan::query()->public()->get()->first(
            fn (Plan $plan): bool => $plan->hasFeature($feature),
        );
    }

    /**
     * @throws LogicException si se comprueba un límite fuera de una transacción
     */
    private function assertInsideTransaction(string $operation, UsageMetric $metric): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        throw new LogicException(sprintf(
            '%s(%s) se llamó fuera de una transacción. El contador se lee BLOQUEADO '
            .'dentro de la transacción que lo mueve ([05 §5]): comprobar o mover en una transacción y escribir '
            .'en otra deja pasar dos operaciones simultáneas con un solo cupo. Envuelve la acción '
            .'en DB::transaction().',
            $operation,
            $metric->value,
        ));
    }
}
