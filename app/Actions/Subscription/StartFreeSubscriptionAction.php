<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Subscription\MissingFreePlanException;
use App\Domain\Subscription\OrganizationAlreadySubscribedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta a una organización en el plan gratuito (`REQ-SUB-002`).
 *
 * La invoca `RegisterOrganizationAction` (`T-010`) dentro de la transacción
 * del registro, de modo que una organización nace con su suscripción o no nace
 * (SP-3).
 *
 * Tres decisiones que están en el código a propósito:
 *
 *  1. **Sin plan Free, esto falla.** No crea la organización «ya le
 *     asignaremos plan»: una organización sin suscripción no tiene límites que
 *     comprobar y el problema aparecería días después, lejos de su causa
 *     (SP-1).
 *  2. **La suscripción nace `active`, sin factura y sin fecha de fin** (RB-2).
 *     Escribir un `current_period_end` en Free reintroduciría el acantilado
 *     que el diseño evita: la capa gratuita **es** la prueba, y nadie debe
 *     perder acceso a su inventario porque se le acabó un trial (RB-3).
 *  3. **Los seis contadores nacen en cero** (RB-5), y nacen todos. Un contador
 *     ausente y uno en cero no son lo mismo para quien luego lee: el primero
 *     obliga a que cada lectura contemple el hueco.
 */
final class StartFreeSubscriptionAction
{
    /**
     * Código del plan de la capa gratuita.
     *
     * Que aquí aparezca el texto `free` no contradice a `REQ-SUB-001` RB-1:
     * la regla prohíbe **decidir un límite** comparando contra el código del
     * plan, no resolver por su identificador estable el plan con el que empieza
     * todo el mundo. De este plan, esta acción no mira ni un solo límite.
     */
    public const FREE_PLAN_CODE = 'free';

    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * El plan de la capa gratuita, resuelto por su identificador estable, o
     * `null` si la base no lo tiene.
     *
     * Es el único sitio que lo nombra (`PlansAreDataTest`). Lo usa también
     * `T-068`: Free es el suelo —ir a Free es siempre bajar, y bajar a Free es
     * cancelar el plan de pago (`REQ-SUB-005` RB-4)—, y eso se pregunta
     * comparando **la fila**, no el código.
     */
    public static function freePlan(): ?Plan
    {
        return Plan::query()->where('code', self::FREE_PLAN_CODE)->first();
    }

    /**
     * @throws MissingFreePlanException si la base no tiene el plan Free
     * @throws OrganizationAlreadySubscribedException si la organización ya tiene suscripción
     */
    public function handle(Organization $organization): Subscription
    {
        $plan = self::freePlan();

        if (! $plan instanceof Plan) {
            throw MissingFreePlanException::withCode(self::FREE_PLAN_CODE);
        }

        return $this->context->runFor($organization, function () use ($organization, $plan): Subscription {
            if (Subscription::query()->exists()) {
                throw OrganizationAlreadySubscribedException::for((int) $organization->getKey());
            }

            return DB::transaction(function () use ($plan): Subscription {
                $startedAt = now();

                $subscription = Subscription::query()->create([
                    'plan_id' => $plan->getKey(),
                    'status' => SubscriptionStatus::Active,
                    'started_at' => $startedAt,
                    'current_period_start' => $startedAt,
                    'current_period_end' => null,
                    'grace_ends_at' => null,
                    'cancelled_at' => null,
                ]);

                $this->initialiseCounters($startedAt);

                return $subscription;
            });
        });
    }

    /**
     * Deja las seis métricas de `REQ-SUB-003` RB-2 con lo que la organización
     * consume en este instante.
     *
     * Se escriben ahora y no cuando alguien cree el primer producto porque el
     * consumo se **lee** antes de que se escriba nada: el backoffice muestra
     * «0 de 30 productos» desde la primera pantalla.
     *
     * **Medido, no a cero** (`REQ-SUB-002` RB-5, corregido en `T-066`): en el
     * registro el `owner` ya existe cuando llega la suscripción, y un `users`
     * a cero lo contradiría desde el primer minuto —y le regalaría al negocio
     * una invitación de más, porque desde `T-066` el contador es lo único que
     * decide—. Para una organización recién creada es cero en todo lo demás.
     * Medir aquí, dentro de la transacción del alta, es seguro: nadie más
     * puede escribir todavía en una organización que no se ha confirmado.
     */
    private function initialiseCounters(DateTimeInterface $computedAt): void
    {
        foreach (UsageMetric::cases() as $metric) {
            UsageCounter::query()->create([
                'metric' => $metric,
                'value' => $this->usage->measure($metric),
                'computed_at' => $computedAt,
            ]);
        }
    }
}
