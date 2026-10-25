<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Subscription\ChangePlanAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\DowngradeOutcome;
use App\Domain\Subscription\InvoiceStatus;
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
use App\Models\SubscriptionInvoice;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
            'closeUrl' => Gate::allows('close', app(TenantContext::class)->organization()) ? route('organization.close') : null,
            'timezone' => app(TenantContext::class)->organization()->timezone,
            'needsDocument' => app(TenantContext::class)->organization()->document === null,
            'scheduledChange' => $this->scheduledChange($subscription),
            'period' => $this->period($subscription),
            'nextInvoice' => Gate::allows('viewInvoices', $subscription) ? $this->nextInvoice() : null,
            'pendingUnpublish' => $this->pendingUnpublish($subscription),
            'plans' => Plan::query()->public()->get()
                ->map(fn (Plan $plan): array => $this->offer($subscription, $plan))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array{start: string, end: string, price: string}|null
     */
    private function period(Subscription $subscription): ?array
    {
        if ($subscription->current_period_end === null) {
            return null;
        }

        return [
            'start' => $subscription->current_period_start->toIso8601String(),
            'end' => $subscription->current_period_end->toIso8601String(),
            'price' => MoneyFormatter::format(new Money($subscription->plan->price_cents)),
        ];
    }

    /**
     * @return array{number: string, amount: string, dueDate: string, payUrl: string}|null
     */
    private function nextInvoice(): ?array
    {
        $invoice = SubscriptionInvoice::query()
            ->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::UnderReview])
            ->oldest('due_date')
            ->oldest('id')
            ->first();

        if (! $invoice instanceof SubscriptionInvoice) {
            return null;
        }

        return [
            'number' => $invoice->number(),
            'amount' => MoneyFormatter::format($invoice->outstanding()),
            'dueDate' => $invoice->due_date->toDateString(),
            'payUrl' => route('subscription.invoices.index', ['pagar' => $invoice->getKey()]),
        ];
    }

    public function change(ChangePlanRequest $request, ChangePlanAction $change, TenantContext $context): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $before = Subscription::query()->firstOrFail();

        $document = $request->document();

        if ($document !== null && $context->organization()->document === null) {
            $context->organization()->forceFill(['document' => $document])->save();
        }

        try {
            $after = $change->handle($context->organization(), $request->plan(), $actor, $request->confirmed());
        } catch (PlanDowngradeNeedsConfirmationException $exception) {
            throw ValidationException::withMessages(['confirmed' => $exception->getMessage()]);
        } catch (OrganizationDocumentRequiredException $exception) {
            throw ValidationException::withMessages(['plan' => $exception->getMessage()]);
        }

        $invoice = $after->plan_id !== $before->plan_id && $after->plan->price_cents > 0
            ? SubscriptionInvoice::query()->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::UnderReview])->latest('id')->first()
            : null;

        return ($invoice instanceof SubscriptionInvoice
            ? to_route('subscription.invoices.index', ['pagar' => $invoice->getKey()])
            : to_route('subscription.show'))->with('status', $this->outcome($before, $after));
    }

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
    private function pendingUnpublish(Subscription $subscription): ?array
    {
        $applied = $subscription->downgrade_applied_at?->toImmutable();
        $limit = $subscription->plan->limitFor(UsageMetric::Catalogs);

        if ($applied === null || $limit === null) {
            return null;
        }

        $used = (int) UsageCounter::query()->where('metric', UsageMetric::Catalogs)->value('value');

        return $used > $limit ? [
            'excess' => $used - $limit,
            'limit' => $limit,
            'unpublishAt' => DowngradeOutcome::unpublishDateFor($applied)->toIso8601String(),
        ] : null;
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
            'preview' => $preview = PlanChangePreview::of($subscription, $plan)->toArray(),
            'invoicePreview' => $preview['direction'] === 'upgrade' && $plan->price_cents > 0 ? $this->invoicePreview($plan) : null,
        ];
    }

    /**
     * @return array{periodStart: string, periodEnd: string, amount: string, amountVes: string|null}
     */
    private function invoicePreview(Plan $plan): array
    {
        $now = CarbonImmutable::now();
        $price = new Money($plan->price_cents);
        $rate = app(ExchangeRateService::class)->current(null);

        return [
            'periodStart' => $now->toIso8601String(),
            'periodEnd' => $plan->periodEndFrom($now)->toIso8601String(),
            'amount' => MoneyFormatter::format($price),
            'amountVes' => $rate === null ? null : MoneyFormatter::format($price->convertTo(Currency::VES, $rate)),
        ];
    }
}
