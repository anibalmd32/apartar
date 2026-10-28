<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TeamMemberships;
use App\Domain\Account\PortalContext;
use App\Domain\Exchange\CurrentRate;
use App\Domain\Money\CurrencyDisplay;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DocumentPrefix;
use App\Models\MobilePrefix;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Queries\PastDueNotice;
use App\Queries\Platform\PlatformAlerts;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
    ) {}

    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() instanceof User ? ['name' => $request->user()->name, 'email' => $request->user()->email] : null,
                'portal' => fn (): ?array => $this->portalSession(),
            ],
            'teamMember' => $this->teamMember($request),
            'platformOperator' => fn (): ?array => $this->platformOperator($request),
            'currency' => $this->currency($request),
            'status' => $request->session()->get('status'),
            'planState' => fn (): ?array => $this->planState($request),
            'pastDue' => fn (): ?array => $this->pastDue($request),
            'planLimit' => $request->session()->get('planLimit'),
            'planFeature' => $request->session()->get('planFeature'),
            'planBlocked' => $request->session()->get('planBlocked'),
            'catalogs' => fn (): array => self::catalogs(),
        ]);
    }

    public const CATALOGS_CACHE_KEY = 'catalogs:banks-prefixes-documents';

    /**
     * @return array{banks: list<array<string, mixed>>, mobilePrefixes: list<array<string, mixed>>, documentPrefixes: list<array<string, mixed>>}
     */
    public static function catalogs(): array
    {
        return Cache::remember(self::CATALOGS_CACHE_KEY, 3600, fn (): array => [
            'banks' => Bank::query()->orderBy('position')->get()->map(fn (Bank $bank): array => $bank->toCatalogEntry())->values()->all(),
            'mobilePrefixes' => MobilePrefix::query()->orderBy('position')->get()->map(fn (MobilePrefix $prefix): array => $prefix->toCatalogEntry())->values()->all(),
            'documentPrefixes' => DocumentPrefix::query()->orderBy('position')->get()->map(fn (DocumentPrefix $prefix): array => $prefix->toCatalogEntry())->values()->all(),
        ]);
    }

    /**
     * @return array{amount: string|null, deadline: string|null, invoicesUrl: string|null}|null
     */
    private function pastDue(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->context->hasOrganization() || (int) $user->organization_id !== $this->context->organizationId()) {
            return null;
        }

        return app(PastDueNotice::class)->of($this->context->organization(), $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function planState(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->context->hasOrganization()) {
            return null;
        }

        if ((int) $user->organization_id !== $this->context->organizationId()) {
            return null;
        }

        if (! Subscription::query()->exists()) {
            return null;
        }

        return app(LimitEnforcer::class)->state()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function currency(Request $request): array
    {
        return [
            'display' => $this->currencyDisplay($request)->value,
            'rate' => app(CurrentRate::class)->view()->toArray(),
            'timezone' => $this->timezone(),
        ];
    }

    private function currencyDisplay(Request $request): CurrencyDisplay
    {
        $chosen = CurrencyDisplay::tryFromValue($request->cookie(CurrencyDisplay::COOKIE));

        if ($chosen instanceof CurrencyDisplay) {
            return $chosen;
        }

        if (! $this->context->hasOrganization()) {
            return CurrencyDisplay::platformDefault();
        }

        return CurrencyDisplay::tryFromValue($this->settings->get(SettingKey::CurrencyDisplayDefault))
            ?? CurrencyDisplay::platformDefault();
    }

    private function timezone(): string
    {
        return $this->context->hasOrganization()
            ? $this->context->organization()->timezone
            : (string) config('app.timezone');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function teamMember(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $organization = $user->organization;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'capabilities' => array_map(
                fn (OrganizationCapability $capability): string => $capability->value,
                $user->role->capabilities(),
            ),
            'mustChangePassword' => $user->must_change_password,
            'pendingReceipts' => fn (): ?int => $this->pendingReceipts($user),
            'unreadChats' => fn (): ?int => $this->unreadChats($user),
            'supportAccess' => fn (): array => $this->supportAccess($user),
            'organizations' => fn (): array => $this->organizationsOf($user),
            'foundOrganizationUrl' => fn (): ?string => app(TeamMemberships::class)->mayFound($user->email) ? route('organizations.create') : null,
            'organization' => [
                'id' => (int) $organization->getKey(),
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status->value,
            ],
        ];
    }

    /**
     * @return list<array{operator: string, reason: string, expiresAt: string}>
     */
    private function supportAccess(User $user): array
    {
        if (! $this->context->hasOrganization() || $this->context->organizationId() !== (int) $user->organization_id) {
            return [];
        }

        return SupportAccessGrant::query()->with('operator:id,name')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->map(fn (SupportAccessGrant $grant): array => [
                'operator' => $grant->operator->name ?? 'Soporte de Apartar',
                'reason' => $grant->reason,
                'expiresAt' => $grant->expires_at->toIso8601String(),
            ])
            ->all();
    }

    private function unreadChats(User $user): ?int
    {
        if (! $this->context->hasOrganization() || $this->context->organizationId() !== (int) $user->organization_id) {
            return null;
        }

        if ($user->disabled_at !== null || ! $user->role->can(OrganizationCapability::Chat)) {
            return null;
        }

        return Conversation::query()->where('admin_unread_count', '>', 0)->count();
    }

    private function pendingReceipts(User $user): ?int
    {
        if (! $this->context->hasOrganization() || $this->context->organizationId() !== (int) $user->organization_id) {
            return null;
        }

        if ($user->disabled_at !== null || ! $user->role->can(OrganizationCapability::Receipts)) {
            return null;
        }

        return Receipt::query()->pendingReview()->count();
    }

    /**
     * @return array{mode: 'scoped'|'account', fullName: string, businessName: string|null, unreadChats: int}|null
     */
    private function portalSession(): ?array
    {
        $account = Auth::guard('account')->user();

        if ($account instanceof Account) {
            return ['mode' => 'account', 'fullName' => $account->display_name, 'businessName' => null, 'unreadChats' => $this->portalUnreadChats()];
        }

        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            return null;
        }

        return [
            'mode' => 'scoped',
            'fullName' => $customer->full_name,
            'businessName' => (string) Organization::query()->whereKey($customer->organization_id)->value('name'),
            'unreadChats' => $this->portalUnreadChats(),
        ];
    }

    private function portalUnreadChats(): int
    {
        $total = 0;

        foreach (app(PortalContext::class)->customers() as $customer) {
            $organization = Organization::query()->find($customer->organization_id);

            if ($organization instanceof Organization) {
                $total += $this->context->runFor($organization, fn (): int => Conversation::query()
                    ->where('customer_id', $customer->getKey())
                    ->where('customer_unread_count', '>', 0)
                    ->count());
            }
        }

        return $total;
    }

    /**
     * @return list<array{name: string, slug: string, role: string, current: bool, switchUrl: string}>
     */
    private function organizationsOf(User $user): array
    {
        $memberships = app(TeamMemberships::class)->of($user);

        if ($memberships->count() < 2) {
            return [];
        }

        return $memberships->map(fn (User $membership): array => [
            'name' => $membership->organization->name,
            'slug' => $membership->organization->slug,
            'role' => $membership->role->value,
            'current' => (int) $membership->getKey() === (int) $user->getKey(),
            'switchUrl' => route('organizations.switch', $membership->organization),
        ])->values()->all();
    }

    /**
     * @return array{name: string, email: string, pendingReceipts: int, activeSupportGrants: int, alerts: list<array{key: string, count: int, tone: string, href: string}>}|null
     */
    private function platformOperator(Request $request): ?array
    {
        $operator = $request->user('platform');

        if (! $operator instanceof PlatformUser) {
            return null;
        }

        return [
            'name' => $operator->name,
            'email' => $operator->email,
            'pendingReceipts' => DB::table('subscription_receipts')->where('status', ReceiptStatus::PendingReview->value)->count(),
            'activeSupportGrants' => DB::table('support_access_grants')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'alerts' => app(PlatformAlerts::class)->for((int) $operator->getKey()),
        ];
    }
}
