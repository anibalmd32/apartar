<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Exchange\CurrentRate;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Tenancy\OrganizationRole;
use App\Http\Controllers\Backoffice\ReceiptInboxController;
use App\Models\Catalog;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DashboardMetricsQuery
{
    public const CACHE_SECONDS = 60;

    /**
     * @var list<string>
     */
    private const NOT_YET = [];

    public function __construct(
        private readonly CustomerDirectoryQuery $directory,
        private readonly CurrentRate $rate,
        private readonly LimitEnforcer $limits,
        private readonly DashboardWarningsQuery $warnings,
        private readonly SubscriberStatsQuery $subscribers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(Organization $organization): array
    {
        $key = 'dashboard:metrics:'.$organization->getKey();

        try {
            $cached = Cache::get($key);
        } catch (Throwable $exception) {
            report($exception);
            $cached = null;
        }

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $metrics = $this->fresh($organization);

        if (! in_array(null, array_diff_key($metrics, array_flip(self::NOT_YET)), true)) {
            try {
                Cache::put($key, $metrics, self::CACHE_SECONDS);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $metrics['warnings'] ??= [];

        return $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    private function fresh(Organization $organization): array
    {
        $timezone = new DateTimeZone($organization->timezone);
        $appZone = new DateTimeZone((string) config('app.timezone'));
        $now = CarbonImmutable::now($timezone);

        return [
            'pendingReceipts' => $this->metric(fn (): int => Receipt::query()->pendingReview()->count()),
            'overdueReceiptsCount' => $this->metric(fn (): int => Receipt::query()->pendingReview()
                ->where('created_at', '<', CarbonImmutable::now()->subHours(ReceiptInboxController::OVERDUE_AFTER_HOURS))
                ->count()),
            'collectedThisMonthCents' => $this->metric(fn (): int => (int) Payment::query()
                ->where('settled_at', '>=', $now->startOfMonth()->setTimezone($appZone))
                ->where('settled_at', '<', $now->startOfMonth()->addMonth()->setTimezone($appZone))
                ->sum('amount_cents_usd')),
            'dueThisWeek' => $this->metric(function (): array {
                $query = (new CollectionFilters(CollectionPreset::DueWithin7Days))->installments()->reorder();

                return [
                    'count' => (clone $query)->count(),
                    'pendingCents' => (int) (clone $query)->sum(DB::raw('installments.amount_cents - installments.paid_cents')),
                ];
            }),
            'customersOverdue' => $this->metric(fn (): int => $this->directory->search(new CustomerDirectoryFilters(overdue: true))->reorder()->count()),
            'longOverdueLayaways' => $this->metric(fn (): int => (new CollectionFilters(CollectionPreset::LongOverdue))->installments()->reorder()->distinct()->count('installments.layaway_id')),
            'completedNotDelivered' => $this->metric(fn (): int => (new CollectionFilters(CollectionPreset::CompletedNotDelivered))->layaways()->reorder()->count()),
            'unreadMessages' => $this->metric(fn (): int => Conversation::query()->where('admin_unread_count', '>', 0)->count()),
            'subscribers' => $this->metric(function (): array {
                $stats = $this->subscribers->forOrganization();

                return ['total' => $stats['total'], 'last7Days' => $stats['last7Days']];
            }),
            'rate' => $this->metric(fn (): array => $this->rate->view()->toArray()),
            'plan' => $this->metric(fn (): array => $this->limits->state()->toArray()),
            'warnings' => $this->metric(fn (): array => $this->warnings->forOrganization($organization)),
            'setup' => $this->metric(fn (): array => [
                'emailVerified' => User::query()->where('role', OrganizationRole::Owner)->whereNull('email_verified_at')->doesntExist(),
                'paymentAccount' => PaymentAccount::query()->where('is_active', true)->exists(),
                'product' => Product::query()->exists(),
                'publishedCatalog' => Catalog::query()->whereNotNull('published_at')->exists(),
            ]),
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T|null
     */
    private function metric(Closure $compute): mixed
    {
        try {
            return $compute();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
