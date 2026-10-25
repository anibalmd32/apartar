<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PlatformMetricsQuery
{
    public const CACHE_KEY = 'platform:metrics';

    public const CACHE_SECONDS = 300;

    public function __construct(private readonly PlatformMoney $money) {}

    public const PERIODS = [30, 90, 365];

    public const MONTHS = 12;

    /**
     * @return array<string, mixed>
     */
    public function compute(int $days = 30): array
    {
        try {
            return Cache::remember(self::CACHE_KEY.':'.$days, self::CACHE_SECONDS, fn (): array => $this->fresh($days));
        } catch (Throwable) {
            return $this->fresh($days);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function fresh(int $days = 30): array
    {
        $since = CarbonImmutable::now()->subDays($days);

        return [
            'organizations' => $this->isolated(fn (): array => $this->organizations($since)),
            'byPlan' => $this->isolated(fn (): array => DB::table('subscriptions')
                ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->join('organizations', 'organizations.id', '=', 'subscriptions.organization_id')
                ->where('organizations.status', '!=', OrganizationStatus::Closed->value)
                ->whereNull('organizations.deleted_at')
                ->groupBy('plans.code')
                ->selectRaw('plans.code AS code, COUNT(*) AS total')
                ->pluck('total', 'code')
                ->map(fn (mixed $total): int => (int) $total)
                ->all()),
            'mrr' => $this->isolated(fn (): array => $this->money->view((int) DB::table('subscriptions')
                ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->whereIn('subscriptions.status', self::mrrStatuses())
                ->sum('plans.price_cents'))),
            'invoices' => $this->isolated(fn (): array => [
                'pendingReview' => DB::table('subscription_receipts')->where('status', ReceiptStatus::PendingReview->value)->count(),
                'overdue' => DB::table('subscription_invoices')->where('status', InvoiceStatus::Overdue->value)->count(),
                'pendingReviewOld' => DB::table('subscription_receipts')
                    ->where('status', ReceiptStatus::PendingReview->value)
                    ->where('created_at', '<', CarbonImmutable::now()->subHours(48))
                    ->count(),
            ]),
            'suspendedLongOverdue' => $this->isolated(fn (): int => DB::table('organizations')
                ->where('status', OrganizationStatus::Suspended->value)
                ->whereNull('deleted_at')
                ->whereExists(fn ($invoices) => $invoices->selectRaw('1')
                    ->from('subscription_invoices')
                    ->whereColumn('subscription_invoices.organization_id', 'organizations.id')
                    ->whereIn('subscription_invoices.status', [InvoiceStatus::Pending->value, InvoiceStatus::UnderReview->value, InvoiceStatus::Overdue->value])
                    ->where('subscription_invoices.due_date', '<', CarbonImmutable::now()->subDays(7)->toDateString()))
                ->count()),
            'monthly' => $this->isolated(fn (): array => $this->monthly()),
            'periodDays' => $days,
            'network' => $this->isolated(fn (): array => [
                'accounts' => DB::table('accounts')->count(),
                'subscriptions' => DB::table('catalog_subscriptions')->count(),
                'multiBusinessAccounts' => $this->multiBusinessAccounts(),
            ]),
            'gmv' => $this->isolated(fn (): array => [
                'committedLast30Days' => $this->money->view((int) DB::table('layaways')->where('created_at', '>=', $since)->sum('total_cents')),
                'collectedLast30Days' => $this->money->view((int) DB::table('payments')->where('settled_at', '>=', $since)->sum('amount_cents_usd')),
            ]),
            'health' => $this->isolated(fn (): array => [
                'failedJobs' => DB::table('failed_jobs')->count(),
                'rateAgeHours' => $this->rateAgeHours(),
                'queueBacklog' => DB::table('jobs')->count(),
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    public static function mrrStatuses(): array
    {
        return [SubscriptionStatus::Active->value, SubscriptionStatus::Grace->value, SubscriptionStatus::PastDue->value];
    }

    /**
     * @return array{total: int, active: int, suspended: int, newLast30Days: int}
     */
    private function organizations(CarbonImmutable $since): array
    {
        $row = DB::table('organizations')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(status = ?) AS active', [OrganizationStatus::Active->value])
            ->selectRaw('SUM(status = ?) AS suspended', [OrganizationStatus::Suspended->value])
            ->selectRaw('SUM(status != ? AND created_at >= ?) AS fresh', [OrganizationStatus::Closed->value, $since])
            ->selectRaw('SUM(status = ?) AS closed', [OrganizationStatus::Closed->value])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0) - (int) ($row->closed ?? 0),
            'active' => (int) ($row->active ?? 0),
            'suspended' => (int) ($row->suspended ?? 0),
            'newLast30Days' => (int) ($row->fresh ?? 0),
        ];
    }

    /**
     * @return list<array{month: string, cents: int}>
     */
    private function monthly(): array
    {
        $first = CarbonImmutable::now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $totals = DB::table('subscription_invoices')
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->where('period_start', '>=', $first)
            ->selectRaw("DATE_FORMAT(period_start, '%Y-%m') AS ym, SUM(amount_cents) AS total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $months = [];

        for ($month = $first, $i = 0; $i < self::MONTHS; $month = $month->addMonth(), $i++) {
            $months[] = ['month' => $month->format('Y-m'), 'cents' => (int) ($totals[$month->format('Y-m')] ?? 0)];
        }

        return $months;
    }

    private function multiBusinessAccounts(): int
    {
        $accounts = DB::table('catalog_subscriptions')
            ->join('catalogs', 'catalogs.id', '=', 'catalog_subscriptions.catalog_id')
            ->groupBy('catalog_subscriptions.account_id')
            ->havingRaw('COUNT(DISTINCT catalogs.organization_id) > 1')
            ->select('catalog_subscriptions.account_id');

        return DB::query()->fromSub($accounts, 'multi')->count();
    }

    private function rateAgeHours(): ?int
    {
        $latest = DB::table('exchange_rates')->max('published_at');

        return $latest === null ? null : (int) floor(CarbonImmutable::parse((string) $latest)->diffInHours(CarbonImmutable::now(), true));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $block
     * @return T|null
     */
    private function isolated(Closure $block): mixed
    {
        try {
            return $block();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
