<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Subscription\IssueInvoiceAction;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Queries\Platform\RenewableSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite las facturas de renovación **siete días antes** del fin del período
 * (`REQ-SUB-006` RB-8, RB-9, `T-069`), con vencimiento el día que acaba: hay
 * margen para pagar sin llegar a `past_due`.
 *
 * Diario e **idempotente**: la factura de un período se emite una vez, porque
 * se decide con la fila de la suscripción bloqueada y mirando si ya hay una
 * (no anulada) que empiece donde acaba el período actual. Una pasada que no
 * corrió un día no deja a nadie sin factura —la consulta incluye los períodos
 * ya acabados—, y una que corre dos veces no emite dos.
 *
 * Qué se factura: el plan de la suscripción, **o el programado** si la bajada
 * vence al acabar el período (`REQ-SUB-005` RB-3) —renovar Business a quien
 * baja a Pro sería cobrarle lo que ya no tendrá—. **Free nunca** (RB-9): ni el
 * plan sin precio ni la bajada a Free generan factura.
 *
 * Una organización que falla no tumba la pasada: se informa y se sigue.
 */
class IssueRenewalInvoices implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        // [90] RB-1: el cobro no es urgente al minuto; va con lo ordinario.
        $this->onQueue('default');
    }

    /**
     * @return int cuántas facturas emitió
     */
    public function handle(RenewableSubscriptions $renewable, TenantContext $context, IssueInvoiceAction $invoices): int
    {
        $now = CarbonImmutable::now();
        $issued = 0;

        foreach ($renewable->organizationIds($now) as $organizationId) {
            $organization = Organization::query()->find($organizationId);

            if (! $organization instanceof Organization) {
                continue;
            }

            try {
                $issued += $context->runFor($organization, fn (): int => $this->renew($organization, $invoices));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Log::info('subscriptions.renewal_invoices_issued', ['issued' => $issued]);

        return $issued;
    }

    /**
     * La factura del período siguiente de esta organización, si le toca.
     */
    private function renew(Organization $organization, IssueInvoiceAction $invoices): int
    {
        return DB::transaction(function () use ($organization, $invoices): int {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->with(['plan', 'scheduledPlan'])->lockForUpdate()->firstOrFail();
            $end = $subscription->current_period_end?->toImmutable();

            if ($end === null || $subscription->status === SubscriptionStatus::Cancelled || ! self::isDue($end, $organization->timezone)) {
                return 0;
            }

            $plan = $this->planToBill($subscription, $end);

            if ($plan->price_cents <= 0) {
                return 0;
            }

            $already = SubscriptionInvoice::query()
                ->where('period_start', $end)
                ->where('status', '!=', InvoiceStatus::Void)
                ->exists();

            if ($already) {
                return 0;
            }

            try {
                $invoices->handle(
                    $subscription,
                    $plan,
                    InvoiceKind::Renewal,
                    $end,
                    $plan->periodEndFrom($end, self::anchorDayOf($subscription)),
                    $end->setTimezone($organization->timezone)->startOfDay(),
                );
            } catch (OrganizationDocumentRequiredException) {
                // Sin RIF no hay primera factura; ya se le pidió al subir de
                // plan, así que esto es un dato a mano en la base, no un caso.
                Log::warning('subscriptions.renewal_without_document', ['organization_id' => $organization->getKey()]);

                return 0;
            }

            return 1;
        });
    }

    /**
     * RB-8 · «siete días antes» se cuenta en **días del negocio**, no en
     * horas: la pasada corre a las 06:00 y un período que acaba a las 14:32
     * del día 10 tiene que facturarse el día 3, no el 4.
     */
    private static function isDue(CarbonImmutable $end, string $timezone): bool
    {
        $limit = CarbonImmutable::now($timezone)->addDays(RenewableSubscriptions::DAYS_AHEAD)->toDateString();

        return $end->setTimezone($timezone)->toDateString() <= $limit;
    }

    /**
     * El día del mes en que caen los períodos de esta suscripción: el mayor
     * de los dos extremos del actual, porque el que el mes acortó es el otro
     * (del 31 de enero al 28 de febrero, el ancla es 31). Ver
     * `Plan::periodEndFrom()`.
     */
    private static function anchorDayOf(Subscription $subscription): int
    {
        return max($subscription->current_period_start->day, (int) $subscription->current_period_end?->day);
    }

    /**
     * El plan del período siguiente: el programado si la bajada vence al
     * acabar este, y si no, el actual.
     */
    private function planToBill(Subscription $subscription, CarbonImmutable $end): Plan
    {
        $scheduled = $subscription->scheduledPlan;

        if ($scheduled instanceof Plan && $subscription->scheduled_plan_at !== null && $subscription->scheduled_plan_at->lessThanOrEqualTo($end)) {
            return $scheduled;
        }

        return $subscription->plan;
    }
}
