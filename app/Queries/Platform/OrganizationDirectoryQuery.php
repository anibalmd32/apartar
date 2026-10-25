<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OrganizationDirectoryQuery
{
    public const PER_PAGE = 25;

    public const CACHE_SECONDS = 300;

    public const AT_LIMIT_RATIO = 0.9;

    public const INACTIVE_DAYS = 30;

    private const NEVER = '1970-01-01 00:00:00';

    private const LAST_ACTIVITY = "GREATEST(COALESCE(last_layaway.at, '".self::NEVER."'), COALESCE(last_payment.at, '".self::NEVER."'))";

    public function __construct(
        private readonly PlatformMoney $money,
        private readonly SupportGrants $grants,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    public function list(OrganizationDirectoryFilters $filters): array
    {
        try {
            return Cache::remember($filters->cacheKey(), self::CACHE_SECONDS, fn (): array => $this->fresh($filters));
        } catch (Throwable) {
            return $this->fresh($filters);
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    public function fresh(OrganizationDirectoryFilters $filters): array
    {
        $page = $this->base($filters)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('organizations.id')
            ->offset(($filters->page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $hasMore = $page->count() > self::PER_PAGE;
        $page = $page->take(self::PER_PAGE);

        return ['rows' => $this->rows($page), 'hasMore' => $hasMore];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function one(int $organizationId): ?array
    {
        $row = $this->base(new OrganizationDirectoryFilters(includeClosed: true))->where('organizations.id', $organizationId)->first();

        return $row === null ? null : ($this->rows(collect([$row]))[0] ?? null);
    }

    private function base(OrganizationDirectoryFilters $filters): Builder
    {
        $now = CarbonImmutable::now();
        $lastLayaway = DB::table('layaways')->selectRaw('organization_id, MAX(created_at) AS at')->groupBy('organization_id');
        $lastPayment = DB::table('payments')->selectRaw('organization_id, MAX(settled_at) AS at')->groupBy('organization_id');

        $query = DB::table('organizations')
            ->leftJoin('subscriptions', 'subscriptions.organization_id', '=', 'organizations.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoinSub($lastLayaway, 'last_layaway', 'last_layaway.organization_id', '=', 'organizations.id')
            ->leftJoinSub($lastPayment, 'last_payment', 'last_payment.organization_id', '=', 'organizations.id')
            ->whereNull('organizations.deleted_at')
            ->select([
                'organizations.id', 'organizations.name', 'organizations.slug', 'organizations.status', 'organizations.created_at',
                'plans.code AS plan_code', 'subscriptions.status AS subscription_status',
            ])
            ->selectRaw('NULLIF('.self::LAST_ACTIVITY.", '".self::NEVER."') AS last_activity_at");

        if (! $filters->includeClosed) {
            $query->where('organizations.status', '!=', OrganizationStatus::Closed->value);
        }

        if ($filters->plan !== null) {
            $query->where('plans.code', $filters->plan);
        }

        if ($filters->status !== null) {
            $query->where('subscriptions.status', $filters->status->value);
        }

        if ($filters->search !== null) {
            $like = '%'.addcslashes($filters->search, '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner->where('organizations.name', 'like', $like)->orWhere('organizations.slug', 'like', $like));
        }

        if ($filters->inactive) {
            $cutoff = $now->subDays(self::INACTIVE_DAYS);
            $query->whereRaw(self::LAST_ACTIVITY.' < ?', [$cutoff]);
        }

        if ($filters->overdue) {
            $query->whereExists(fn (Builder $exists) => $exists->from('subscription_invoices')
                ->whereColumn('subscription_invoices.organization_id', 'organizations.id')
                ->where('subscription_invoices.status', InvoiceStatus::Overdue->value));
        }

        if ($filters->atLimit) {
            $query->whereExists(fn (Builder $exists) => $exists->from('usage_counters')
                ->whereColumn('usage_counters.organization_id', 'organizations.id')
                ->whereRaw(self::atLimitCondition()));
        }

        return $query;
    }

    private static function atLimitCondition(): string
    {
        $cases = [];

        foreach (UsageMetric::cases() as $metric) {
            $column = $metric->planColumn();
            $cases[] = sprintf(
                "(usage_counters.metric = '%s' AND plans.%s IS NOT NULL AND plans.%s > 0 AND usage_counters.value >= plans.%s * %s)",
                $metric->value, $column, $column, $column, self::AT_LIMIT_RATIO,
            );
        }

        return '('.implode(' OR ', $cases).')';
    }

    /**
     * @param  Collection<int, \stdClass>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $page): array
    {
        $ids = $page->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $usage = $this->safely(fn () => DB::table('usage_counters')->whereIn('organization_id', $ids)->get(['organization_id', 'metric', 'value'])
            ->groupBy('organization_id')
            ->map(fn (Collection $rows): array => $rows->pluck('value', 'metric')->map(fn (mixed $value): int => (int) $value)->all()));
        $limits = $this->safely(fn () => DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.organization_id', $ids)->get()->keyBy('organization_id'));
        $customers = $this->countBy('customers', $ids);
        $subscribers = $this->safely(fn () => DB::table('catalog_subscriptions')->join('catalogs', 'catalogs.id', '=', 'catalog_subscriptions.catalog_id')
            ->whereIn('catalogs.organization_id', $ids)->groupBy('catalogs.organization_id')
            ->selectRaw('catalogs.organization_id AS organization_id, COUNT(*) AS total')->pluck('total', 'organization_id'));
        $volume = $this->safely(fn () => DB::table('layaways')->whereIn('organization_id', $ids)
            ->whereIn('status', array_map(fn (LayawayStatus $status): string => $status->value, LayawayStatus::holdingStock()))
            ->groupBy('organization_id')
            ->selectRaw('organization_id, SUM(total_cents) AS committed, SUM(remaining_cents) AS outstanding')
            ->get()->keyBy('organization_id'));
        $collected = $this->safely(fn () => DB::table('payments')->whereIn('organization_id', $ids)
            ->where('settled_at', '>=', CarbonImmutable::now()->subDays(30))
            ->groupBy('organization_id')->selectRaw('organization_id, SUM(amount_cents_usd) AS total')->pluck('total', 'organization_id'));
        $overdue = $this->safely(fn () => DB::table('subscription_invoices')->whereIn('organization_id', $ids)
            ->where('status', InvoiceStatus::Overdue->value)->groupBy('organization_id')
            ->selectRaw('organization_id, COUNT(*) AS total')->pluck('total', 'organization_id'));
        $supported = $this->safely(fn () => $this->grants->organizationsWithActiveGrant($ids)) ?? [];
        $inactiveSince = CarbonImmutable::now()->subDays(self::INACTIVE_DAYS);

        return $page->map(function (object $row) use ($usage, $limits, $customers, $subscribers, $volume, $collected, $overdue, $supported, $inactiveSince): array {
            $id = (int) $row->id;
            $metrics = $usage?->get($id) ?? [];
            $plan = $limits?->get($id);
            $lastActivity = $row->last_activity_at === null ? null : CarbonImmutable::parse((string) $row->last_activity_at);

            return [
                'id' => $id,
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'status' => (string) $row->status,
                'planCode' => $row->plan_code === null ? null : (string) $row->plan_code,
                'subscriptionStatus' => $row->subscription_status === null ? null : (string) $row->subscription_status,
                'createdAt' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                'counts' => $usage === null ? null : [
                    'users' => $metrics[UsageMetric::Users->value] ?? 0,
                    'products' => $metrics[UsageMetric::Products->value] ?? 0,
                    'publishedCatalogs' => $metrics[UsageMetric::Catalogs->value] ?? 0,
                    'customers' => $customers === null ? null : (int) ($customers[$id] ?? 0),
                    'activeLayaways' => $metrics[UsageMetric::ActiveLayaways->value] ?? 0,
                    'subscribers' => $subscribers === null ? null : (int) ($subscribers[$id] ?? 0),
                ],
                'volume' => $volume === null || $collected === null ? null : [
                    'committed' => $this->money->view((int) ($volume->get($id)->committed ?? 0)),
                    'collectedLast30Days' => $this->money->view((int) ($collected[$id] ?? 0)),
                    'outstanding' => $this->money->view((int) ($volume->get($id)->outstanding ?? 0)),
                ],
                'lastActivityAt' => $lastActivity?->toIso8601String(),
                'risks' => [
                    'atLimit' => $this->atLimit($metrics, $plan),
                    'inactive' => $lastActivity === null || $lastActivity->lessThan($inactiveSince),
                    'overdueInvoices' => $overdue === null ? false : (int) ($overdue[$id] ?? 0) > 0,
                ],
                'hasActiveSupportAccess' => in_array($id, $supported, true),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, int>  $metrics
     */
    private function atLimit(array $metrics, ?object $plan): bool
    {
        if ($plan === null) {
            return false;
        }

        foreach (UsageMetric::cases() as $metric) {
            $limit = $plan->{$metric->planColumn()} ?? null;

            if (is_numeric($limit) && (int) $limit > 0 && ($metrics[$metric->value] ?? 0) >= (int) $limit * self::AT_LIMIT_RATIO) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int|string, mixed>|null
     */
    private function countBy(string $table, array $ids): ?Collection
    {
        return $this->safely(fn () => DB::table($table)->whereIn('organization_id', $ids)->groupBy('organization_id')
            ->selectRaw('organization_id, COUNT(*) AS total')->pluck('total', 'organization_id'));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $query
     * @return T|null
     */
    private function safely(\Closure $query): mixed
    {
        try {
            return $query();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
