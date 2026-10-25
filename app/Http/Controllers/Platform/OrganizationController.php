<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Organization\SuspendOrganizationAction;
use App\Actions\Platform\GrantCourtesyPeriodAction;
use App\Actions\Platform\GrantSupportAccessAction;
use App\Actions\Platform\OverridePlanAction;
use App\Actions\Platform\RevokeSupportAccessAction;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\OrganizationRowResource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Queries\Platform\OrganizationDirectoryFilters;
use App\Queries\Platform\OrganizationDirectoryQuery;
use App\Queries\Platform\SubscriptionReceiptInbox;
use App\Queries\Platform\SupportGrants;
use App\Support\CatalogShareLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class OrganizationController extends Controller
{
    public function index(Request $request, OrganizationDirectoryQuery $directory): Response
    {
        $filters = OrganizationDirectoryFilters::fromRequest($request);
        $page = $directory->list($filters);

        return Inertia::render('platform/organizations/Index', [
            'rows' => OrganizationRowResource::collectionOf($page['rows']),
            'hasMore' => $page['hasMore'],
            'page' => $filters->page,
            'filters' => $filters->toArray(),
            'plans' => Plan::query()->orderBy('position')->pluck('name', 'code'),
            'total' => Organization::query()->where('status', '!=', OrganizationStatus::Closed->value)->count(),
        ]);
    }

    public function show(Request $request, Organization $organization, OrganizationDirectoryQuery $directory, SubscriptionReceiptInbox $inbox, SupportGrants $grants): Response
    {
        $row = $directory->one((int) $organization->getKey()) ?? abort(404);
        $operator = $this->operator($request);
        $mine = $grants->activeFor((int) $operator->getKey(), (int) $organization->getKey());

        return Inertia::render('platform/organizations/Show', [
            'organization' => OrganizationRowResource::of($row),
            'invoices' => $inbox->invoicesOf((int) $organization->getKey()),
            'grants' => array_map(fn (SupportAccessGrant $grant): array => [
                'id' => $grant->getKey(),
                'operator' => $grant->operator->name ?? '—',
                'reason' => $grant->reason,
                'grantedAt' => $grant->granted_at->toIso8601String(),
                'expiresAt' => $grant->expires_at->toIso8601String(),
                'revokedAt' => $grant->revoked_at?->toIso8601String(),
                'active' => $grant->isActive(),
            ], $grants->recentFor((int) $organization->getKey())),
            'myGrant' => $mine === null ? null : ['id' => $mine->getKey(), 'expiresAt' => $mine->expires_at->toIso8601String()],
            'plans' => Plan::query()->orderBy('position')->get(['code', 'name'])->map(fn (Plan $plan): array => ['code' => $plan->code, 'name' => $plan->name])->all(),
            ...$this->detail($organization),
        ]);
    }

    public function suspend(Request $request, Organization $organization, SuspendOrganizationAction $suspend): RedirectResponse
    {
        $done = $suspend->handle($organization, $this->operator($request));

        return back()->with('status', $done ? 'Organización suspendida: sus catálogos se despublicaron y su equipo lo ve.' : 'Ya estaba suspendida o cerrada.');
    }

    /**
     * @return array{limits: array<string, int|null>, profile: array<string, string|null>, autoSuspendOn: string|null, suspendable: bool}
     */
    private function detail(Organization $organization): array
    {
        return app(TenantContext::class)->runFor($organization, function () use ($organization): array {
            $subscription = Subscription::query()->with('plan')->first();
            $owner = User::query()->where('role', OrganizationRole::Owner->value)->orderBy('id')->first();
            $limits = [];

            foreach (UsageMetric::cases() as $metric) {
                $limits[$metric->value] = $subscription?->plan->limitFor($metric);
            }

            return [
                'limits' => $limits,
                'profile' => [
                    'ownerName' => $owner?->name,
                    'ownerEmail' => $owner?->email,
                    'phone' => $organization->phone,
                    'document' => $organization->document,
                    'storefrontUrl' => $organization->storefront_enabled ? CatalogShareLink::storefrontOf($organization->slug) : null,
                    'timezone' => $organization->timezone,
                ],
                'autoSuspendOn' => $subscription?->grace_ends_at?->setTimezone($organization->timezone)->toDateString(),
                'suspendable' => $organization->status === OrganizationStatus::Active && $subscription !== null && $subscription->status !== SubscriptionStatus::Cancelled,
            ];
        });
    }

    public function grantSupport(Request $request, Organization $organization, GrantSupportAccessAction $grant): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:'.GrantSupportAccessAction::REASON_MIN, 'max:'.GrantSupportAccessAction::REASON_MAX]]);

        $this->rethrow('reason', fn () => $grant->handle($this->operator($request), $organization, $data['reason']));

        return back()->with('status', 'Acceso de soporte abierto. El negocio ya lo ve.');
    }

    public function revokeSupport(Request $request, Organization $organization, int $grant, SupportGrants $grants, RevokeSupportAccessAction $revoke): RedirectResponse
    {
        $found = $grants->find($grant);
        abort_if($found === null || $found->organization_id !== $organization->getKey(), 404);

        $revoke->handle($found, $this->operator($request));

        return back()->with('status', 'Acceso de soporte cerrado.');
    }

    public function overridePlan(Request $request, Organization $organization, OverridePlanAction $override): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
            'reason' => ['required', 'string', 'min:'.OverridePlanAction::REASON_MIN, 'max:500'],
        ]);

        $plan = Plan::query()->where('code', $data['plan'])->firstOrFail();
        $this->rethrow('reason', fn () => $override->handle($organization, $plan, $this->operator($request), $data['reason']));

        return back()->with('status', 'Plan cambiado.');
    }

    public function courtesy(Request $request, Organization $organization, GrantCourtesyPeriodAction $courtesy): RedirectResponse
    {
        $data = $request->validate([
            'until' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'min:'.GrantCourtesyPeriodAction::REASON_MIN, 'max:500'],
        ]);

        $until = CarbonImmutable::parse($data['until'], $organization->timezone)->endOfDay();
        $this->rethrow('until', fn () => $courtesy->handle($organization, $this->operator($request), $until, $data['reason']));

        return back()->with('status', 'Cortesía concedida.');
    }

    /**
     * @param  \Closure(): mixed  $action
     */
    private function rethrow(string $field, \Closure $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function operator(Request $request): PlatformUser
    {
        $user = $request->user('platform');

        return $user instanceof PlatformUser ? $user : abort(401);
    }
}
