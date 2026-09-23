<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
 * ## Por qué vuelve a contar en vez de fiarse del contador
 *
 * `REQ-SUB-003` RB-1 materializa el consumo en `usage_counters` para que
 * comprobar un límite no cueste seis agregados, y ese contador se sigue
 * escribiendo aquí: `synchronise()` lo deja con lo medido dentro de la
 * transacción de cada mutación (RB-3), y la comprobación con bloqueo también.
 * Pero tanto para **decidir** como para **pintar**, el enforcer vuelve a
 * contar la métrica de su propia tabla.
 *
 * El motivo es de hoy y no de siempre: quien mantiene el valor materializado
 * frente a la deriva es el recálculo diario de `UsageCalculator`, que llega con
 * `T-066`, y las llamadas de las otras once acciones de `REQ-SUB-004` RB-3, que
 * llegan con `T-067`. Hasta entonces el contador solo lo mueve esta tarea, así
 * que arrastra lo que nadie ha cuadrado —el registro de una organización, sin
 * ir más lejos, deja los seis en cero con el `owner` ya creado—. Hacer cumplir
 * un techo contra una cifra que nadie mantiene es hacer cumplir una ficción, y
 * pintarla al lado de la lista que la contradice es peor: nadie vuelve a
 * creerse ese número.
 *
 * Contar una vez, por un índice, cuesta lo que cuesta, y **no puede
 * equivocarse**. Lo que serializa es el bloqueo, no el conteo. Cuando `T-066`
 * mantenga los contadores, el valor materializado pasa a ser el camino único y
 * la medida se queda donde tiene que estar: en `UsageCalculator`.
 *
 * ## Qué métricas sabe medir
 *
 * Las de las tablas que existen: `users` y `payment_accounts`. Las otras
 * cuatro —catálogos, productos, apartados y almacenamiento— no tienen tabla
 * todavía, así que `measure()` devuelve `null` para ellas y el enforcer cae en
 * el valor materializado, que es el comportamiento definitivo una vez `T-066`
 * lo mantenga. Añadirlas es añadir su brazo al `match`.
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
        $this->assertInsideTransaction($metric);

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
     * Lo consumido se **mide** cuando se puede medir, y solo cae en el valor
     * materializado cuando no. La diferencia importa hoy y desaparecerá con
     * `T-066`: una organización recién registrada tiene su `owner` dentro y
     * los seis contadores a cero, así que leer el contador pintaría «0 de 3
     * usuarios» en la pantalla de Equipo con tres nombres debajo. Una cifra
     * que se contradice con lo que se ve al lado no es una cifra optimista,
     * es una que nadie vuelve a creerse.
     */
    public function usageOf(UsageMetric $metric, ?Subscription $subscription = null): UsageState
    {
        $subscription ??= $this->subscription();

        return UsageState::of(
            $metric,
            $this->measure($metric) ?? $this->materialisedUsage($metric),
            $subscription->plan->limitFor($metric),
        );
    }

    /**
     * Mide la métrica de verdad y deja el contador con ese valor.
     *
     * La llaman las acciones **al terminar de mutar y dentro de su misma
     * transacción** (`REQ-SUB-003` RB-3): invitar y reactivar suben `users`,
     * revocar y desactivar lo bajan. Que el ajuste ocurra ahí y no en un job
     * diferido es lo que hace que la siguiente comprobación —o la siguiente
     * pantalla— vea la cifra correcta y no la de hace un minuto.
     *
     * Devuelve el valor que queda escrito.
     */
    public function synchronise(UsageMetric $metric): int
    {
        $measured = $this->measure($metric);

        if ($measured === null) {
            return $this->materialisedUsage($metric);
        }

        $counter = $this->counterFor($metric);

        $counter->forceFill(['value' => $measured, 'computed_at' => now()])->save();

        return $measured;
    }

    // -------------------------------------------------------------------
    // El contador
    // -------------------------------------------------------------------

    /**
     * Consumo real, con la fila del contador bloqueada para el resto de la
     * transacción.
     *
     * El orden es el que importa: **primero el bloqueo, después la medida**.
     * Al revés, dos peticiones contarían a la vez antes de que ninguna
     * bloqueara, y las dos leerían el mismo «queda uno».
     */
    private function lockedUsage(UsageMetric $metric): int
    {
        $counter = $this->counterFor($metric, locked: true);

        $measured = $this->measure($metric);

        if ($measured === null) {
            return $counter->value;
        }

        if ($measured !== $counter->value) {
            $counter->forceFill(['value' => $measured, 'computed_at' => now()])->save();
        }

        return $measured;
    }

    /**
     * Valor materializado, sin bloquear ni medir.
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

    /**
     * Consumo real de una métrica, o `null` si su tabla todavía no existe.
     *
     * Las definiciones son las de `REQ-SUB-003` RB-2 **al pie de la letra**:
     *
     *  - `users` cuenta los activos (`disabled_at` nulo) **e incluye las
     *    invitaciones pendientes**, que son filas de `users` sin contraseña y
     *    sin desactivar. No hace falta sumarlas aparte: una invitación
     *    pendiente ya es una fila activa, y esa es justamente la razón de que
     *    consuma cupo desde que se envía (`REQ-ORG-004` RB-2);
     *  - `payment_accounts` cuenta las activas.
     *
     * El `match` es exhaustivo y sin `default`: una métrica nueva tiene que
     * decidir de qué lado cae en vez de caer por descuido en el `null`, que es
     * el lado que no hace cumplir nada.
     */
    private function measure(UsageMetric $metric): ?int
    {
        return match ($metric) {
            UsageMetric::Users => User::query()->whereNull('disabled_at')->count(),

            UsageMetric::PaymentAccounts => PaymentAccount::query()->where('is_active', true)->count(),

            // Sus tablas llegan con sus hitos; `T-066` añade estos cuatro
            // brazos junto al recálculo diario.
            UsageMetric::Catalogs,
            UsageMetric::Products,
            UsageMetric::ActiveLayaways,
            UsageMetric::StorageMb => null,
        };
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
    private function assertInsideTransaction(UsageMetric $metric): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        throw new LogicException(sprintf(
            'assertCanCreate(%s) se llamó fuera de una transacción. El contador se lee BLOQUEADO '
            .'dentro de la transacción que crea ([05 §5]): comprobar en una transacción y escribir '
            .'en otra deja pasar dos creaciones simultáneas con un solo cupo. Envuelve la acción '
            .'en DB::transaction().',
            $metric->value,
        ));
    }
}
