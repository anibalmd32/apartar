<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Queries\Platform\PlatformMetricsQuery;
use App\Queries\Platform\PlatformMoney;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PlatformMetricsTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_mrr_includes_past_due_and_excludes_suspended(): void
    {
        $pro = Plan::factory()->create(['code' => 'pro', 'price_cents' => 1_500]);

        foreach ([SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled] as $status) {
            $organization = Organization::factory()->create();
            $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($pro)->create(['status' => $status]));
        }

        $this->withoutOrganization();

        $this->assertSame(3_000, app(PlatformMetricsQuery::class)->fresh()['mrr']['cents']);
    }

    public function test_multi_business_accounts(): void
    {
        [$a, $b] = [Organization::factory()->create(), Organization::factory()->create()];
        [$a1, $a2] = $this->withinOrganization($a, fn (): array => [Catalog::factory()->published()->create(), Catalog::factory()->published()->create()]);
        $b1 = $this->withinOrganization($b, fn (): Catalog => Catalog::factory()->published()->create());
        $this->withoutOrganization();

        $both = Account::factory()->create();
        $oneBusiness = Account::factory()->create();

        foreach ([[$both, $a1], [$both, $b1], [$oneBusiness, $a1], [$oneBusiness, $a2]] as [$account, $catalog]) {
            CatalogSubscription::factory()->create(['account_id' => $account->getKey(), 'catalog_id' => $catalog->getKey()]);
        }

        $network = app(PlatformMetricsQuery::class)->fresh()['network'];

        $this->assertSame(['accounts' => 2, 'subscriptions' => 4, 'multiBusinessAccounts' => 1], $network);
    }

    public function test_organization_counts_and_an_empty_platform(): void
    {
        $empty = app(PlatformMetricsQuery::class)->fresh();
        $this->assertSame(['total' => 0, 'active' => 0, 'suspended' => 0, 'newLast30Days' => 0], $empty['organizations']);
        $this->assertSame(0, $empty['mrr']['cents']);

        Organization::factory()->create();
        Organization::factory()->suspended()->create();
        Organization::factory()->closed()->create();

        $this->assertSame(['total' => 2, 'active' => 1, 'suspended' => 1, 'newLast30Days' => 2], app(PlatformMetricsQuery::class)->fresh()['organizations']);
    }

    public function test_a_failing_metric_is_isolated(): void
    {
        $this->mock(PlatformMoney::class)->shouldReceive('view')->andThrow(new RuntimeException('roto'));

        $metrics = app(PlatformMetricsQuery::class)->fresh();

        $this->assertNull($metrics['mrr']);
        $this->assertNull($metrics['gmv']);
        $this->assertIsArray($metrics['organizations']);
        $this->assertIsArray($metrics['network']);
    }

    public function test_the_monthly_series_and_the_details_of_the_cards(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $this->withinOrganization($organization, function (): void {
            SubscriptionInvoice::factory()->create(['amount_cents' => 1_500, 'period_start' => now(), 'due_date' => now()->subDays(10)->toDateString()]);
            SubscriptionInvoice::factory()->create(['amount_cents' => 900, 'period_start' => now(), 'status' => InvoiceStatus::Void]);
        });
        $this->withoutOrganization();

        $metrics = app(PlatformMetricsQuery::class)->fresh(90);

        $this->assertCount(12, $metrics['monthly']);
        $this->assertSame(1_500, $metrics['monthly'][11]['cents']);
        $this->assertSame(1, $metrics['suspendedLongOverdue']);
        $this->assertSame(0, $metrics['invoices']['pendingReviewOld']);
        $this->assertSame(90, $metrics['periodDays']);

        $this->asOperator();
        $this->get('/plataforma?periodo=365')->assertInertia(fn ($page) => $page->where('metrics.periodDays', 365)->where('periods', [30, 90, 365]));
    }

    public function test_metrics_are_cached_for_five_minutes(): void
    {
        Cache::flush();
        $this->asOperator();
        $this->get('/plataforma')->assertOk();

        Organization::factory()->create();

        $this->get('/plataforma')->assertInertia(fn ($page) => $page->where('metrics.organizations.total', 0));
        $this->assertSame(300, PlatformMetricsQuery::CACHE_SECONDS);
    }
}
