<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\PlanLimitRejection;
use App\Models\Product;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DashboardWarningsQuery
{
    public const MAX_EMPTY_CATALOGS = 3;

    public const LOST_SALE_DAYS = 7;

    public function __construct(private readonly CurrentRate $rate) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(Organization $organization): array
    {
        $warnings = [];

        if (PaymentAccount::query()->where('is_active', true)->doesntExist()) {
            $warnings[] = ['kind' => 'no_active_payment_account'];
        }

        foreach (Catalog::query()
            ->whereNotNull('published_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))
            ->whereDoesntHave('products', fn (Builder $products) => $products->where('products.status', ProductStatus::Published))
            ->orderBy('name')
            ->limit(self::MAX_EMPTY_CATALOGS)
            ->pluck('slug') as $slug) {
            $warnings[] = ['kind' => 'published_catalog_empty', 'catalogSlug' => $slug];
        }

        $orphans = Product::query()->published()->whereDoesntHave('catalogs')->count();

        if ($orphans > 0) {
            $warnings[] = ['kind' => 'product_published_in_no_catalog', 'count' => $orphans];
        }

        $snapshot = $this->rate->snapshot();

        if ($snapshot instanceof RateSnapshot && $snapshot->isStale()) {
            $warnings[] = ['kind' => 'stale_exchange_rate', 'ageHours' => (int) $snapshot->publishedAt->diffInHours(CarbonImmutable::now())];
        }

        $overdue = SubscriptionInvoice::query()
            ->where('status', InvoiceStatus::Pending)
            ->where('due_date', '<', CarbonImmutable::now($organization->timezone)->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();

        if ($overdue instanceof SubscriptionInvoice) {
            $warnings[] = ['kind' => 'invoice_overdue', 'dueDate' => $overdue->due_date->toDateString()];
        }

        foreach (PlanLimitRejection::query()
            ->where('occurred_at', '>=', CarbonImmutable::now()->subDays(self::LOST_SALE_DAYS))
            ->distinct()
            ->orderBy('metric')
            ->pluck('metric') as $metric) {
            $warnings[] = ['kind' => 'plan_limit_blocking_sales', 'metric' => $metric instanceof UsageMetric ? $metric->value : (string) $metric];
        }

        if (User::query()->where('role', OrganizationRole::Owner)->whereNull('email_verified_at')->exists()) {
            $warnings[] = ['kind' => 'email_not_verified'];
        }

        return $warnings;
    }
}
