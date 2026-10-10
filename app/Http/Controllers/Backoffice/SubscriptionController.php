<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Subscription\ChangePlanAction;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Subscription\PlanChangePreview;
use App\Domain\Subscription\PlanDowngradeNeedsConfirmationException;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ChangePlanRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /backoffice/suscripcion` y `POST /backoffice/suscripcion/cambiar`
 * (`REQ-SUB-001`, `REQ-SUB-003` RB-6, RB-7, `REQ-SUB-004` RB-7,
 * `REQ-SUB-005`, `T-067`, `T-068`): el plan contratado, lo consumido de cada
 * métrica, las funciones, y el cambio de plan con su previsualización. Las
 * facturas tienen su página (`SubscriptionInvoiceController`, `T-069`).
 *
 * Es el destino de cada «Ver planes» —el `422` del límite, `LimitBanner`, el
 * aviso de suspensión del layout—, y por eso **sobrevive a la suspensión**: es
 * donde se regulariza, y subir de plan es una de las vías (`REQ-SUB-005`
 * SP-8). El plan y su consumo no viajan como prop propia: ya los comparte
 * `HandleInertiaRequests` (`planState`) con cada página del backoffice.
 *
 * Lo que sí viaja aquí es **cada plan del escaparate con su previsualización
 * ya calculada** (`PlanChangePreview`): la pantalla enseña lo que excede antes
 * de pulsar (RB-5) sin pedir nada más, y la acción vuelve a calcularlo al
 * confirmar. Las facturas tienen su página (`SubscriptionInvoiceController`,
 * `T-069`).
 */
final class SubscriptionController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->with(['plan', 'scheduledPlan'])->firstOrFail();

        Gate::authorize('view', $subscription);

        return Inertia::render('backoffice/subscription/Show', [
            'pricingUrl' => route('pricing'),
            'canChangePlan' => Gate::allows('changePlan', $subscription),
            'invoicesUrl' => Gate::allows('viewInvoices', $subscription) ? route('subscription.invoices.index') : null,
            'timezone' => app(TenantContext::class)->organization()->timezone,
            'scheduledChange' => $this->scheduledChange($subscription),
            'plans' => Plan::query()->public()->get()
                ->map(fn (Plan $plan): array => $this->offer($subscription, $plan))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Sube, programa una bajada, la revierte o no hace nada: lo decide la
     * acción según la dirección (`ChangePlanAction`).
     */
    public function change(ChangePlanRequest $request, ChangePlanAction $change, TenantContext $context): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $before = Subscription::query()->firstOrFail();

        try {
            $after = $change->handle($context->organization(), $request->plan(), $actor, $request->confirmed());
        } catch (PlanDowngradeNeedsConfirmationException $exception) {
            throw ValidationException::withMessages(['confirmed' => $exception->getMessage()]);
        } catch (OrganizationDocumentRequiredException $exception) {
            // `REQ-SUB-006` RB-5, SP-4 · el RIF se pide antes de subir.
            throw ValidationException::withMessages(['plan' => $exception->getMessage()]);
        }

        return to_route('subscription.show')->with('status', $this->outcome($before, $after));
    }

    /**
     * La frase que confirma qué pasó, que no es siempre lo que se pidió.
     */
    private function outcome(Subscription $before, Subscription $after): string
    {
        if ($after->plan_id !== $before->plan_id) {
            return $after->status->allowsCreation()
                ? sprintf('Ya estás en %s: sus límites y funciones aplican desde ahora.', $after->plan->name)
                : sprintf('Ya estás en %s. Tu suscripción sigue suspendida hasta que se apruebe el pago.', $after->plan->name);
        }

        if ($after->scheduledPlan instanceof Plan && $after->scheduled_plan_id !== $before->scheduled_plan_id) {
            return sprintf(
                'Pasarás a %s el %s. Hasta entonces conservas todo lo de %s.',
                $after->scheduledPlan->name,
                $after->scheduled_plan_at?->setTimezone(app(TenantContext::class)->organization()->timezone)->format('d/m/Y'),
                $after->plan->name,
            );
        }

        if ($before->scheduled_plan_id !== null && $after->scheduled_plan_id === null) {
            return sprintf('Sigues en %s: el cambio programado quedó cancelado.', $after->plan->name);
        }

        return sprintf('Ya estás en %s.', $after->plan->name);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduledChange(Subscription $subscription): ?array
    {
        if (! $subscription->scheduledPlan instanceof Plan) {
            return null;
        }

        return [
            'planCode' => $subscription->scheduledPlan->code,
            'planName' => $subscription->scheduledPlan->name,
            'effectiveAt' => $subscription->scheduled_plan_at?->toIso8601String(),
            'isCancellation' => $subscription->cancelled_at !== null,
        ];
    }

    /**
     * Un plan del escaparate como opción de cambio, con sus techos, sus
     * funciones y lo que pasaría al elegirlo.
     *
     * @return array<string, mixed>
     */
    private function offer(Subscription $subscription, Plan $plan): array
    {
        $limits = [];

        foreach (UsageMetric::cases() as $metric) {
            $limits[$metric->value] = $plan->limitFor($metric);
        }

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'priceCents' => $plan->price_cents,
            'limits' => $limits,
            'productsPerCatalog' => $plan->maxProductsPerCatalog(),
            'features' => array_map(fn (PlanFeature $feature): string => $feature->value, $plan->enabledFeatures()),
            'isCurrent' => $plan->is($subscription->plan),
            'isScheduled' => $plan->getKey() === $subscription->scheduled_plan_id,
            'preview' => PlanChangePreview::of($subscription, $plan)->toArray(),
        ];
    }
}
