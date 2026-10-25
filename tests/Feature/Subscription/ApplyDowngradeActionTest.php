<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Account\SubscribeToCatalogAction;
use App\Actions\Payment\SubmitReceiptAction;
use App\Actions\Payment\SubmitReceiptData;
use App\Actions\Subscription\ApplyDowngradeAction;
use App\Actions\Subscription\ChangePlanAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Subscription\DowngradeOutcome;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantAlreadySetException;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\User;
use App\Queries\AccountSubscriptionsQuery;
use App\Queries\SubscriptionRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

final class ApplyDowngradeActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase, UploadsReceiptFiles;

    private Organization $organization;

    private User $owner;

    private Plan $free;

    private Plan $pro;

    /** @var list<Catalog> */
    private array $catalogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-09 12:00:00');
        Notification::fake();

        $this->free = Plan::factory()->create([
            'code' => 'free', 'name' => 'Free', 'position' => 1,
            'max_users' => 1, 'max_catalogs' => 1, 'max_products' => 2, 'max_products_per_catalog' => 2,
            'max_active_layaways' => 1, 'max_storage_mb' => 1, 'max_payment_accounts' => 1,
        ]);
        $this->pro = Plan::factory()->unlimited()->withEveryFeature()->create(['code' => 'pro', 'name' => 'Pro', 'position' => 2]);

        $this->organization = Organization::factory()->create(['brand_color' => '#123456']);
        $this->owner = $this->within(function (): User {
            Subscription::factory()->forPlan($this->pro)->create([
                'current_period_start' => '2026-10-01 00:00:00',
                'current_period_end' => '2026-11-01 00:00:00',
            ]);
            User::factory()->count(2)->create();
            Product::factory()->count(2)->create();
            PaymentAccount::factory()->count(2)->create();

            foreach (['2026-09-01 10:00:00', '2026-09-05 10:00:00', '2026-09-05 10:00:00', '2026-09-20 10:00:00'] as $published) {
                $this->catalogs[] = Catalog::factory()->create(['published_at' => $published]);
            }

            return User::factory()->owner()->create();
        });

        $first = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->layawayIn($this->organization, LayawayStatus::Overdue);
        $this->within(fn () => Receipt::factory()->create(['layaway_id' => $first->getKey(), 'file_size' => 5 * 1024 * 1024]));
        $this->recalculateUsage($this->organization);

        app(ChangePlanAction::class)->handle($this->organization, $this->free, $this->owner, confirmed: true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_at_the_end_of_the_period_the_plan_becomes_free(): void
    {
        $cancelledAt = $this->subscription()->cancelled_at;
        $this->assertNotNull($cancelledAt);

        $this->assertTrue($this->runAt('2026-10-31 23:00:00')->isNoop(), 'Antes del fin del período no cambia nada.');

        $outcome = $this->runAt('2026-11-01 00:05:00');

        $subscription = $this->subscription();
        $this->assertTrue($outcome->applied());
        $this->assertSame(['pro', 'free'], [$outcome->fromPlan, $outcome->toPlan]);
        $this->assertTrue($subscription->plan->is($this->free));
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNull($subscription->scheduled_plan_at);
        $this->assertNull($subscription->current_period_end);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 00:05:00'), $subscription->downgrade_applied_at);
        $this->assertEquals($cancelledAt, $subscription->cancelled_at);
        $this->assertEquals(CarbonImmutable::parse('2026-11-08 00:05:00'), $outcome->unpublishAt);
    }

    #[DataProvider('everyMetric')]
    public function test_nothing_is_deleted(string $metric): void
    {
        $before = $this->measure($metric);

        $this->runAt('2026-11-01 00:05:00');
        $this->runAt('2026-11-08 00:05:00');

        $this->assertEquals($before, $this->measure($metric));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyMetric(): array
    {
        return array_combine(
            array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases()),
            array_map(fn (UsageMetric $metric): array => [$metric->value], UsageMetric::cases()),
        );
    }

    public function test_after_the_grace_the_newest_catalogs_are_unpublished_keeping_the_oldest(): void
    {
        $this->runAt('2026-11-01 00:05:00');
        $this->assertSame(4, $this->publishedCount(), 'Durante la gracia, todos siguen publicados.');

        $this->assertTrue($this->runAt('2026-11-08 00:04:00')->isNoop());

        $outcome = $this->runAt('2026-11-08 00:05:00');

        [$oldest, $tieFirst, $tieSecond, $newest] = $this->catalogs;
        $this->assertSame([$tieFirst->slug, $tieSecond->slug, $newest->slug], $outcome->unpublished);
        $this->assertNotNull($oldest->fresh()?->published_at);
        $this->assertSame(1, $this->publishedCount());
        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));
        $this->assertNull($this->subscription()->downgrade_applied_at);

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionDowngradeApplied)->latest('id')->firstOrFail());
        $this->assertSame([$tieFirst->slug, $tieSecond->slug, $newest->slug], $entry->properties['changed']['unpublished_catalogs']['after'] ?? null);
    }

    public function test_the_tie_is_broken_by_id_whatever_the_order_on_disk(): void
    {
        [, $tieFirst, $tieSecond] = $this->catalogs;
        DB::table('catalogs')->where('id', $tieFirst->getKey())->update(['published_at' => '2026-09-01 10:00:00']);

        $this->runAt('2026-11-01 00:05:00');
        $outcome = $this->runAt('2026-11-08 00:05:00');

        $this->assertNotContains($this->catalogs[0]->slug, $outcome->unpublished);
        $this->assertContains($tieFirst->slug, $outcome->unpublished, 'A igual fecha que el más antiguo, gana el de `id` menor.');
        $this->assertContains($tieSecond->slug, $outcome->unpublished);
    }

    public function test_an_expired_catalog_neither_counts_nor_is_touched(): void
    {
        $this->within(fn () => Catalog::query()->whereKey($this->catalogs[0]->getKey())->update(['expires_at' => '2026-10-20 00:00:00']));

        $this->runAt('2026-11-01 00:05:00');
        $outcome = $this->runAt('2026-11-08 00:05:00');

        $this->assertNotContains($this->catalogs[0]->slug, $outcome->unpublished);
        $this->assertNotNull($this->catalogs[0]->fresh()?->published_at);
        $this->assertNotNull($this->catalogs[1]->fresh()?->published_at, 'El más antiguo de los vigentes se conserva.');
    }

    public function test_layaways_and_the_team_are_untouched_and_only_creation_is_blocked(): void
    {
        $layaways = $this->within(fn (): array => Layaway::query()->orderBy('id')->pluck('status')->map(fn (LayawayStatus $status): string => $status->value)->all());

        $this->runAt('2026-11-01 00:05:00');
        $this->runAt('2026-11-08 00:05:00');

        $this->assertSame($layaways, $this->within(fn (): array => Layaway::query()->orderBy('id')->pluck('status')->map(fn (LayawayStatus $status): string => $status->value)->all()));
        $this->assertSame(0, $this->within(fn (): int => User::query()->whereNotNull('disabled_at')->count()));

        foreach ([UsageMetric::Users, UsageMetric::Products, UsageMetric::ActiveLayaways, UsageMetric::PaymentAccounts] as $metric) {
            try {
                $this->within(fn () => DB::transaction(fn () => app(LimitEnforcer::class)->assertCanCreate($metric)));
                $this->fail(sprintf('En Free, por encima del techo de %s, crear tenía que rechazarse.', $metric->value));
            } catch (PlanLimitReachedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_storage_over_the_ceiling_blocks_the_business_but_not_the_customers_receipt(): void
    {
        $this->runAt('2026-11-01 00:05:00');

        try {
            $this->within(fn () => DB::transaction(fn () => app(LimitEnforcer::class)->assertCanCreate(UsageMetric::StorageMb, 1)));
            $this->fail('Una subida del negocio con la cuota agotada se rechaza.');
        } catch (PlanLimitReachedException) {
            $this->addToAssertionCount(1);
        }

        $layaway = $this->within(fn (): Layaway => Layaway::query()->where('status', LayawayStatus::Active)->firstOrFail());
        $this->submitReceipt($layaway);

        $this->assertSame(2, $this->within(fn (): int => Receipt::query()->count()));
        $this->assertGreaterThan(1, $this->counter(UsageMetric::StorageMb), 'El exceso se registra.');
    }

    public function test_lost_features_switch_off_keeping_their_configuration(): void
    {
        $this->runAt('2026-11-01 00:05:00');

        $this->assertFalse($this->within(fn (): bool => app(LimitEnforcer::class)->hasFeature(PlanFeature::Branding)));
        $this->assertSame('#123456', $this->organization->fresh()?->brand_color);

        CarbonImmutable::setTestNow('2026-11-03 10:00:00');
        app(ChangePlanAction::class)->handle($this->organization, $this->pro, $this->owner);

        $this->assertTrue($this->within(fn (): bool => app(LimitEnforcer::class)->hasFeature(PlanFeature::Branding)));
    }

    public function test_upgrading_during_the_grace_cancels_the_unpublishing(): void
    {
        $this->runAt('2026-11-01 00:05:00');
        CarbonImmutable::setTestNow('2026-11-03 10:00:00');
        app(ChangePlanAction::class)->handle($this->organization, $this->pro, $this->owner);

        $outcome = $this->runAt('2026-11-09 00:05:00');

        $this->assertSame([], $outcome->unpublished);
        $this->assertSame(4, $this->publishedCount());
        $this->assertNull($this->subscription()->downgrade_applied_at, 'Cumplida la gracia sin nada que despublicar, no queda pendiente.');
    }

    public function test_upgrading_to_a_plan_still_below_unpublishes_what_still_exceeds(): void
    {
        $basic = Plan::factory()->create(['code' => 'basico', 'position' => 2, 'max_catalogs' => 2]);
        $this->pro->forceFill(['position' => 3])->save();
        $this->runAt('2026-11-01 00:05:00');
        CarbonImmutable::setTestNow('2026-11-03 10:00:00');
        app(ChangePlanAction::class)->handle($this->organization, $basic, $this->owner);

        $outcome = $this->runAt('2026-11-08 00:05:00');

        $this->assertCount(2, $outcome->unpublished);
        $this->assertSame(2, $this->publishedCount());
    }

    public function test_a_second_downgrade_during_the_grace_keeps_the_first_date(): void
    {
        $basic = Plan::factory()->create(['code' => 'basico', 'position' => 2, 'max_catalogs' => 2]);
        $this->pro->forceFill(['position' => 3])->save();
        $this->within(fn () => Subscription::query()->update(['scheduled_plan_id' => $basic->getKey()]));
        $this->runAt('2026-11-01 00:05:00');

        CarbonImmutable::setTestNow('2026-11-03 10:00:00');
        app(ChangePlanAction::class)->handle($this->organization, $this->free, $this->owner, confirmed: true);
        $this->runAt('2026-11-03 10:05:00');

        $this->assertTrue($this->subscription()->plan->is($this->free));
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 00:05:00'), $this->subscription()->downgrade_applied_at);
        $this->assertCount(3, $this->runAt('2026-11-08 00:05:00')->unpublished);
    }

    public function test_running_twice_is_idempotent(): void
    {
        $this->runAt('2026-11-01 00:05:00');
        $this->assertTrue($this->runAt('2026-11-01 01:05:00')->isNoop());

        $this->runAt('2026-11-08 00:05:00');
        $this->assertTrue($this->runAt('2026-11-08 01:05:00')->isNoop());

        $this->assertSame(1, $this->publishedCount());
        $this->assertSame(2, $this->within(fn (): int => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionDowngradeApplied)->count()));
    }

    public function test_below_every_ceiling_only_the_plan_changes(): void
    {
        $this->within(fn () => Catalog::query()->whereKeyNot($this->catalogs[0]->getKey())->update(['published_at' => null]));
        $this->recalculateUsage($this->organization);

        $this->runAt('2026-11-01 00:05:00');
        $outcome = $this->runAt('2026-11-08 00:05:00');

        $this->assertSame([], $outcome->unpublished);
        $this->assertSame(1, $this->publishedCount());
    }

    public function test_landing_on_free_lifts_a_suspension_at_once(): void
    {
        $this->within(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => '2026-10-20 23:59:59']));
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();

        $this->runAt('2026-11-01 00:05:00');

        $subscription = $this->subscription();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
        $this->assertSame(1, $this->within(fn (): int => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionReactivated)->count()));
    }

    public function test_landing_on_free_settles_a_past_due_subscription(): void
    {
        $this->within(fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => '2026-11-05 23:59:59']));

        $this->runAt('2026-11-01 00:05:00');

        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);
        $this->assertNull($this->subscription()->grace_ends_at);
    }

    public function test_landing_on_a_paid_plan_keeps_the_delinquency(): void
    {
        $basic = Plan::factory()->create(['code' => 'basico', 'position' => 1, 'price_cents' => 900, 'max_catalogs' => 2]);
        $this->within(fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue, 'scheduled_plan_id' => $basic->getKey()]));

        $this->runAt('2026-11-01 00:05:00');

        $subscription = $this->subscription();
        $this->assertTrue($subscription->plan->is($basic));
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 00:00:00'), $subscription->current_period_end);
        $this->assertNotNull($subscription->downgrade_applied_at, 'Lo que sobre se despublicará igual.');
    }

    public function test_it_applies_at_the_exact_instant(): void
    {
        $this->assertTrue($this->runAt('2026-10-31 23:59:59')->isNoop());
        $this->assertTrue($this->runAt('2026-11-01 00:00:00')->applied());
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 00:00:00'), $this->subscription()->current_period_start, 'En Free el período empieza al aplicar.');
    }

    public function test_landing_on_a_paid_plan_keeps_a_suspension_and_its_grace_end(): void
    {
        $basic = Plan::factory()->create(['code' => 'basico', 'position' => 1, 'price_cents' => 900, 'max_catalogs' => 2]);
        $this->within(fn () => Subscription::query()->update([
            'status' => SubscriptionStatus::Suspended,
            'grace_ends_at' => '2026-10-20 23:59:59',
            'scheduled_plan_id' => $basic->getKey(),
        ]));
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();

        $this->runAt('2026-11-01 00:05:00');

        $subscription = $this->subscription();
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertEquals(CarbonImmutable::parse('2026-10-20 23:59:59'), $subscription->grace_ends_at);
        $this->assertEquals(CarbonImmutable::parse('2026-10-01 00:00:00'), $subscription->current_period_start, 'Con precio, el período no se toca.');
        $this->assertSame(OrganizationStatus::Suspended, $this->organization->fresh()?->status);
    }

    public function test_a_plan_without_a_catalog_ceiling_unpublishes_nothing(): void
    {
        $this->runAt('2026-11-01 00:05:00');
        $this->free->forceFill(['max_catalogs' => null])->save();

        $outcome = $this->runAt('2026-11-08 00:05:00');

        $this->assertSame([], $outcome->unpublished);
        $this->assertSame(4, $this->publishedCount());
        $this->assertNull($this->subscription()->downgrade_applied_at);
    }

    public function test_the_neighbours_catalogs_neither_count_nor_are_touched(): void
    {
        $this->runAt('2026-11-01 00:05:00');
        $neighbour = $this->neighbourOrganization();
        $theirs = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->create(['published_at' => '2026-08-01 10:00:00']));

        $outcome = $this->runAt('2026-11-08 00:05:00');

        $this->assertCount(3, $outcome->unpublished);
        $this->assertNotNull($this->catalogs[0]->fresh()?->published_at, 'El más antiguo propio se conserva aunque el del vecino sea anterior.');
        $this->assertNotNull($this->withinOrganization($neighbour, fn (): ?Catalog => Catalog::query()->find($theirs->getKey()))?->published_at);
    }

    public function test_a_catalog_expiring_at_the_exact_instant_still_counts(): void
    {
        $this->runAt('2026-11-01 00:05:00');
        $this->within(fn () => Catalog::query()->whereKey($this->catalogs[0]->getKey())->update(['expires_at' => '2026-11-08 00:05:00']));

        $outcome = $this->runAt('2026-11-08 00:05:00');

        $this->assertNotContains($this->catalogs[0]->slug, $outcome->unpublished, 'Es el más antiguo: cuenta y se conserva.');
        $this->assertCount(3, $outcome->unpublished);
    }

    public function test_the_outcome_and_the_audit_say_what_happened(): void
    {
        $applied = $this->runAt('2026-11-01 00:05:00');
        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionDowngradeApplied)->sole());
        $this->assertSame('pro', $entry->properties['changed']['from_plan']['after'] ?? null);
        $this->assertSame('free', $entry->properties['changed']['to_plan']['after'] ?? null);
        $this->assertNotNull($entry->properties['changed']['unpublish_at']['after'] ?? null);
        $this->assertNotNull($applied->unpublishAt);

        $unpublished = $this->runAt('2026-11-08 00:05:00');
        $this->assertNull($unpublished->unpublishAt);
    }

    public function test_another_tenant_already_set_refuses(): void
    {
        $subscription = $this->subscription();
        $this->actingAsOrganization($this->neighbourOrganization());

        $this->expectException(TenantAlreadySetException::class);

        app(ApplyDowngradeAction::class)->handle($subscription);
    }

    public function test_a_cancelled_subscription_is_left_alone(): void
    {
        $this->within(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Cancelled]));

        $this->assertTrue($this->runAt('2026-11-01 00:05:00')->isNoop());
        $this->assertTrue($this->subscription()->plan->is($this->pro));
    }

    public function test_catalog_subscribers_survive_the_unpublishing_and_revive(): void
    {
        $newest = $this->catalogs[3];
        $account = Account::factory()->create();
        app(SubscribeToCatalogAction::class)->handle($account, $newest);

        $this->runAt('2026-11-01 00:05:00');
        $this->runAt('2026-11-08 00:05:00');

        $this->assertNull($newest->fresh()?->published_at);
        $this->assertSame(1, CatalogSubscription::query()->where('catalog_id', $newest->getKey())->count());
        $this->assertSame([false], $this->activeSubscriptionsOf($account));

        $this->within(fn () => $newest->forceFill(['published_at' => now()])->save());

        $this->assertSame([true], $this->activeSubscriptionsOf($account));
    }

    /**
     * @return list<bool>
     */
    private function activeSubscriptionsOf(Account $account): array
    {
        $this->withoutOrganization();

        return array_map(fn (SubscriptionRow $row): bool => $row->active, app(AccountSubscriptionsQuery::class)->forAccount($account));
    }

    private function runAt(string $now): DowngradeOutcome
    {
        CarbonImmutable::setTestNow($now);
        $this->withoutOrganization();

        return app(ApplyDowngradeAction::class)->handle($this->subscription());
    }

    private function subscription(): Subscription
    {
        return $this->within(fn (): Subscription => Subscription::query()->with(['plan', 'scheduledPlan'])->firstOrFail());
    }

    private function publishedCount(): int
    {
        return $this->within(fn (): int => Catalog::query()->whereNotNull('published_at')->count());
    }

    private function counter(UsageMetric $metric): int
    {
        return (int) DB::table('usage_counters')->where('organization_id', $this->organization->getKey())->where('metric', $metric->value)->value('value');
    }

    /**
     * @return list<mixed>
     */
    private function measure(string $metric): array
    {
        return $this->within(fn (): array => match (UsageMetric::from($metric)) {
            UsageMetric::Users => User::query()->orderBy('id')->get(['id', 'disabled_at', 'role'])->toArray(),
            UsageMetric::Catalogs => Catalog::query()->orderBy('id')->get(['id', 'slug', 'expires_at', 'is_listed'])->toArray(),
            UsageMetric::Products => Product::query()->withTrashed()->orderBy('id')->get(['id', 'status', 'deleted_at'])->toArray(),
            UsageMetric::ActiveLayaways => Layaway::query()->orderBy('id')->get(['id', 'status', 'remaining_cents'])->toArray(),
            UsageMetric::StorageMb => Receipt::query()->orderBy('id')->get(['id', 'file_path', 'file_size'])->toArray(),
            UsageMetric::PaymentAccounts => PaymentAccount::query()->orderBy('id')->get(['id', 'is_active'])->toArray(),
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function within(\Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }

    private function submitReceipt(Layaway $layaway): void
    {
        Storage::fake(Receipt::DISK);
        Queue::fake();

        $this->within(fn (): Receipt => app(SubmitReceiptAction::class)->handle(new SubmitReceiptData(
            $layaway, null, PaymentMethod::PagoMovil, 'Banesco', '0000000777', CarbonImmutable::now(), '850.00', Currency::VES, null, $this->receiptImage(),
        )));
    }
}
