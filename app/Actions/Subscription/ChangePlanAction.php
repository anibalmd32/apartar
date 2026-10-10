<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\PlanChangeDirection;
use App\Domain\Subscription\PlanChangePreview;
use App\Domain\Subscription\PlanDowngradeNeedsConfirmationException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Cambia el plan de una organización (`REQ-SUB-005`, `T-068`).
 *
 * Las tres direcciones (`PlanChangeDirection`) no se parecen en nada, y esta
 * clase es sobre todo esa bifurcación:
 *
 *  - **Subir es inmediato** (RB-2): la suscripción apunta al plan nuevo y
 *    empieza un período, y como `LimitEnforcer` lee el plan de la suscripción
 *    en cada petición, los techos y las funciones nuevas aplican **en la
 *    siguiente** —sin reconfigurar nada, sin caché que invalidar—. Si había
 *    una bajada programada, se cancela (`REQ-SUB-008` SP-5).
 *  - **Bajar se programa** (RB-3): la suscripción guarda a qué plan y desde
 *    cuándo, pasa a `grace` y **no pierde nada** hasta el fin del período
 *    pagado. Quien la aplica es `T-072`. Si deja algo por encima del techo,
 *    exige que se haya confirmado viendo la previsualización (SP-2).
 *    **Cancelar es bajar a Free** (RB-4): nunca deja a la organización sin
 *    plan, y además sella `cancelled_at`.
 *  - **Al mismo plan no es nada** (SP-4)… salvo con una bajada programada,
 *    que entonces es **revertirla** (RB-7): la suscripción vuelve a `active`
 *    en su plan, sin coste ni rastro operativo.
 *
 * **Lo que serializa es la fila de la suscripción**, releída con bloqueo: dos
 * cambios simultáneos —subir en una pestaña y bajar en otra— se aplican uno
 * detrás de otro y el segundo decide sobre lo que dejó el primero. No toca
 * contadores: la previsualización los lee sin bloquear, porque solo describe.
 *
 * **Lo que dice que hay una bajada pendiente es `scheduled_plan_id`, no el
 * estado.** `grace` es solo cómo se ve en una suscripción al día; una con la
 * factura vencida o suspendida conserva ese estado y la bajada igual. Quien
 * aplique (`T-072`), cobre (`T-069`) o persiga el impago (`T-071`) lee la
 * columna.
 *
 * **La factura del plan nuevo es de `T-069`** (RB-2, `REQ-SUB-006`): su sitio
 * es `upgrade()`, en la misma transacción, y
 * `ChangePlanActionTest::test_the_upgrade_invoice_is_still_pending()` se pone
 * rojo el día que exista `IssueInvoiceAction`.
 */
final class ChangePlanAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  bool  $confirmed  si quien baja ya vio lo que queda por encima (SP-2)
     *
     * @throws AuthorizationException si el actor no gobierna la suscripción (RB-1, SP-1)
     * @throws InvalidArgumentException si el destino no está en el escaparate
     * @throws LogicException si se sube a un plan con precio sin facturación (`T-069`)
     * @throws PlanDowngradeNeedsConfirmationException si una bajada excede algún techo sin confirmar
     */
    public function handle(Organization $organization, Plan $target, User $actor, bool $confirmed = false): Subscription
    {
        return $this->context->runFor($organization, fn (): Subscription => DB::transaction(function () use ($target, $actor, $confirmed): Subscription {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->with(['plan', 'scheduledPlan'])->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('changePlan', $subscription);

            $direction = PlanChangeDirection::between($subscription->plan, $target);

            // Solo se cambia a un plan del escaparate (`REQ-SUB-001` RB-6): uno
            // retirado sigue valiendo para quien ya lo tiene, no para entrar.
            // La plataforma concede otros con su propia acción (`T-086`).
            if ($direction !== PlanChangeDirection::Same && ! $target->is_public) {
                throw new InvalidArgumentException(sprintf('El plan %s no está a la venta.', $target->code));
            }

            $before = $this->snapshot($subscription);

            $changed = match ($direction) {
                PlanChangeDirection::Upgrade => $this->upgrade($subscription, $target),
                PlanChangeDirection::Downgrade => $this->scheduleDowngrade($subscription, $target, $confirmed),
                PlanChangeDirection::Same => $this->revert($subscription),
            };

            // RB-8 · todo cambio se audita. Uno que no cambia nada (SP-4) no
            // es un cambio, y anotarlo llenaría el registro de clics.
            if ($changed) {
                $subscription->load(['plan', 'scheduledPlan']);

                $this->audit(
                    AuditEvent::SubscriptionPlanChanged,
                    subject: $subscription,
                    actor: $actor,
                    before: $before,
                    after: $this->snapshot($subscription),
                );
            }

            return $subscription;
        }));
    }

    /**
     * RB-2 · el plan nuevo aplica ya, con un período que empieza hoy.
     *
     * El estado no se toca salvo `grace`, que solo significaba «hay una bajada
     * pendiente» y esa bajada se acaba de cancelar. Una suscripción con una
     * factura vencida o suspendida **sigue** así (SP-3, SP-8): subir es una
     * vía de regularizar, pero la suspensión se levanta al aprobarse el pago
     * (`REQ-SUB-007` RB-5), que es de `T-069`.
     */
    private function upgrade(Subscription $subscription, Plan $target): bool
    {
        // Subir sin cobrar sería regalar el plan: hasta que `T-069` emita la
        // factura en esta misma transacción, un plan con precio no se aplica.
        // Hoy los tres valen cero (`REQ-SUB-001` RB-3b), así que no bloquea
        // nada; bloquea el día que alguien fije un precio con un `UPDATE`
        // antes de que exista la factura.
        if ($target->price_cents > 0) {
            throw new LogicException(sprintf(
                'El plan %s tiene precio y todavía no se emiten facturas (T-069): subir lo regalaría.',
                $target->code,
            ));
        }

        $now = CarbonImmutable::now();

        $subscription->forceFill([
            'plan_id' => $target->getKey(),
            'status' => $subscription->status === SubscriptionStatus::Grace ? SubscriptionStatus::Active : $subscription->status,
            'scheduled_plan_id' => null,
            'scheduled_plan_at' => null,
            'cancelled_at' => null,
        ]);

        // Con una deuda abierta el período **no se mueve**: su fin es el
        // vencimiento de lo que se debe (el aviso del panel lo enseña), y
        // empezar otro lo correría un mes. Lo cierra el pago (`T-069`).
        if (! $subscription->status->isDelinquent()) {
            $subscription->forceFill([
                'current_period_start' => $now,
                'current_period_end' => $target->periodEndFrom($now),
            ]);
        }

        $subscription->save();

        // T-069 · aquí se emite la factura del plan nuevo (RB-2), dentro de
        // esta transacción: o cambia el plan con su factura, o no cambia.

        return true;
    }

    /**
     * RB-3 · la bajada se guarda, no se aplica: el plan sigue siendo el que
     * se pagó hasta `PlanChangePreview::effectiveDateOf()`.
     *
     * Solo una suscripción `active` pasa a `grace`: una con la factura vencida
     * o suspendida conserva ese estado, que es el que manda (el impago), y la
     * bajada se ve igual por `scheduled_plan_id`.
     */
    private function scheduleDowngrade(Subscription $subscription, Plan $target, bool $confirmed): bool
    {
        if ($subscription->scheduled_plan_id === $target->getKey()) {
            return false;
        }

        $preview = PlanChangePreview::forDowngrade($subscription, $target);

        if ($preview->hasExcess() && ! $confirmed) {
            throw new PlanDowngradeNeedsConfirmationException($preview);
        }

        $subscription->forceFill([
            'status' => $subscription->status === SubscriptionStatus::Active ? SubscriptionStatus::Grace : $subscription->status,
            'scheduled_plan_id' => $target->getKey(),
            'scheduled_plan_at' => $preview->effectiveAt,
            // RB-4 · cancelar es bajar a Free; cambiar de destino a otro plan
            // pago deja de ser una baja.
            'cancelled_at' => $target->is(StartFreeSubscriptionAction::freePlan())
                ? ($subscription->cancelled_at ?? CarbonImmutable::now())
                : null,
        ])->save();

        return true;
    }

    /**
     * RB-7, SP-4, SP-5 · al mismo plan: revierte la bajada programada si la
     * hay, y si no, no hace nada.
     */
    private function revert(Subscription $subscription): bool
    {
        if (! $subscription->hasScheduledChange()) {
            return false;
        }

        $subscription->forceFill([
            'status' => $subscription->status === SubscriptionStatus::Grace ? SubscriptionStatus::Active : $subscription->status,
            'scheduled_plan_id' => null,
            'scheduled_plan_at' => null,
            'cancelled_at' => null,
        ])->save();

        return true;
    }

    /**
     * Lo que se anota antes y después (`REQ-BOF-005` RB-2).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Subscription $subscription): array
    {
        return [
            'plan' => $subscription->plan->code,
            'status' => $subscription->status->value,
            'scheduled_plan' => $subscription->scheduledPlan?->code,
            'scheduled_plan_at' => $subscription->scheduled_plan_at?->toIso8601String(),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'current_period_start' => $subscription->current_period_start->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
        ];
    }
}
