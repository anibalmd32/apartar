<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Catalog;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PlanLimitRejection;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\UsageCounter;
use App\Models\User;
use App\Queries\DashboardMetricsQuery;
use App\Queries\DashboardWarningsQuery;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 12:00:00', 'America/Caracas'));
        Cache::flush();
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'Asia/Tokyo']));
        $this->owner = $this->within(fn (): User => User::factory()->owner()->create(['email_verified_at' => now()]));
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->within(fn () => PaymentAccount::factory()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_each_metric_counts_what_its_list_shows(): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->installment($active, 1, '2026-10-03');
        $this->installment($active, 2, '2026-09-01');
        $this->installment($active, 3, '2026-09-02');
        $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->receiptFor($active);
        $this->receiptFor($active, attributes: ['created_at' => CarbonImmutable::now()->subHours(50)]);

        $metrics = $this->metrics();

        $this->assertSame([2, 1], [$metrics['pendingReceipts'], $metrics['overdueReceiptsCount']]);
        $this->assertArrayNotHasKey('dueThisWeek', $metrics);
        $this->assertSame([1, 1, 1], [$metrics['customersOverdue'], $metrics['longOverdueLayaways'], $metrics['completedNotDelivered']]);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/Dashboard')
            ->missing('links.dueThisWeek')
            ->where('links.customersOverdue', route('customers.index', ['overdue' => '1'])));
    }

    public function test_collected_this_month_is_the_business_month_in_dollars(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->payment($layaway, 4_000, CarbonImmutable::parse('2026-10-01 00:30:00', 'Asia/Tokyo'));
        $this->payment($layaway, 9_999, CarbonImmutable::parse('2026-09-30 23:30:00', 'Asia/Tokyo'));

        $this->assertSame(4_000, $this->metrics()['collectedThisMonthCents']);
    }

    public function test_each_warning_fires_only_in_its_condition(): void
    {
        $this->assertSame([], $this->warnings());

        $this->within(fn () => PaymentAccount::query()->update(['is_active' => false]));
        $this->assertSame(['no_active_payment_account'], $this->kinds());
        $this->within(fn () => PaymentAccount::query()->update(['is_active' => true]));

        $catalog = $this->within(fn (): Catalog => Catalog::factory()->published()->create(['slug' => 'temporada']));
        $this->assertSame([['kind' => 'published_catalog_empty', 'catalogSlug' => 'temporada']], $this->warnings());
        $product = $this->within(fn (): Product => Product::factory()->published()->create());
        $this->within(fn () => $catalog->products()->attach($product->getKey(), ['position' => 1]));
        $this->assertSame([], $this->warnings());

        $this->within(fn () => Product::factory()->published()->create());
        $this->assertSame([['kind' => 'product_published_in_no_catalog', 'count' => 1]], $this->warnings());
        DB::table('products')->where('id', '!=', $product->getKey())->delete();

        DB::table('exchange_rates')->update(['published_at' => CarbonImmutable::now()->subHours(30)]);
        Cache::flush();
        app()->forgetInstance(CurrentRate::class);
        $this->assertSame([['kind' => 'stale_exchange_rate', 'ageHours' => 30]], $this->warnings());
        DB::table('exchange_rates')->update(['published_at' => CarbonImmutable::now()->subHour()]);
        Cache::flush();
        app()->forgetInstance(CurrentRate::class);

        $this->within(fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue]));
        $this->assertSame([], $this->warnings(), 'Sin factura no hay factura vencida.');

        $later = $this->within(fn (): SubscriptionInvoice => SubscriptionInvoice::factory()->create(['due_date' => CarbonImmutable::now('Asia/Tokyo')->subDays(2)->toDateString()]));
        $this->within(fn () => SubscriptionInvoice::factory()->create(['due_date' => '2026-09-25']));
        $this->assertSame([['kind' => 'invoice_overdue', 'dueDate' => '2026-09-25']], $this->warnings(), 'La más antigua.');

        $this->within(fn () => SubscriptionInvoice::query()->where('due_date', '2026-09-25')->update(['status' => InvoiceStatus::UnderReview]));
        $this->assertSame([['kind' => 'invoice_overdue', 'dueDate' => $later->due_date->toDateString()]], $this->warnings(), 'Con el comprobante en revisión ya pagó: no avisa.');

        $this->within(fn () => SubscriptionInvoice::query()->update(['status' => InvoiceStatus::Paid]));
        $this->within(fn () => SubscriptionInvoice::factory()->create(['due_date' => CarbonImmutable::now('Asia/Tokyo')->toDateString()]));
        $this->assertSame([], $this->warnings(), 'Pagadas, o vence hoy en el día del negocio: no está vencida.');

        $yesterday = CarbonImmutable::now('Asia/Tokyo')->subDay()->toDateString();
        $this->assertSame($yesterday, CarbonImmutable::now()->toDateString());
        $this->within(fn () => SubscriptionInvoice::factory()->create(['due_date' => $yesterday]));
        $this->assertSame([['kind' => 'invoice_overdue', 'dueDate' => $yesterday]], $this->warnings());

        $this->within(fn () => SubscriptionInvoice::query()->where('due_date', $yesterday)->update(['status' => InvoiceStatus::Void]));
        $this->assertSame([], $this->warnings(), 'Una anulada no se debe.');

        $this->within(fn () => SubscriptionInvoice::query()->delete());
        $this->within(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Active]));

        DB::table('users')->where('id', $this->owner->getKey())->update(['email_verified_at' => null]);
        $this->assertSame(['email_not_verified'], $this->kinds());
        $this->within(fn () => User::factory()->create(['role' => OrganizationRole::Member, 'email_verified_at' => null]));
        DB::table('users')->where('id', $this->owner->getKey())->update(['email_verified_at' => now()]);
        $this->assertSame([], $this->warnings(), 'Un miembro sin verificar no es el dueño.');
    }

    public function test_the_lost_sales_warning_needs_evidence_and_not_proximity(): void
    {
        $this->within(function (): void {
            Subscription::query()->firstOrFail()->plan->forceFill(['max_active_layaways' => 3])->save();
            UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => 3, 'computed_at' => now()]);
        });

        $this->assertNotContains('plan_limit_blocking_sales', $this->kinds(), 'Estar en el techo no es una venta perdida.');

        $this->within(fn () => PlanLimitRejection::query()->create(['metric' => UsageMetric::ActiveLayaways, 'occurred_at' => CarbonImmutable::now()->subDays(8)]));
        $this->assertNotContains('plan_limit_blocking_sales', $this->kinds(), 'Un rechazo de hace ocho días no es de esta semana.');

        $this->within(fn () => PlanLimitRejection::query()->create(['metric' => UsageMetric::ActiveLayaways, 'occurred_at' => CarbonImmutable::now()->subDays(6)]));
        $this->within(fn () => PlanLimitRejection::query()->create(['metric' => UsageMetric::ActiveLayaways, 'occurred_at' => CarbonImmutable::now()->subHour()]));

        $this->assertSame(
            [['kind' => 'plan_limit_blocking_sales', 'metric' => 'active_layaways']],
            array_values(array_filter($this->warnings(), fn (array $warning): bool => $warning['kind'] === 'plan_limit_blocking_sales')),
            'Dos rechazos de la misma métrica son un aviso, no dos.',
        );

        $this->withinOrganization($this->neighbourOrganization(), fn () => PlanLimitRejection::query()->create(['metric' => UsageMetric::Products, 'occurred_at' => now()]));
        $this->assertSame(1, count(array_filter($this->kinds(), fn (string $kind): bool => $kind === 'plan_limit_blocking_sales')));
    }

    public function test_catalog_warnings_count_only_what_is_on_display(): void
    {
        $this->within(fn () => Catalog::factory()->create(['slug' => 'borrador']));
        $this->within(fn () => Catalog::factory()->expired()->create(['slug' => 'caducado']));
        $this->assertSame([], $this->warnings());

        $catalog = $this->within(fn (): Catalog => Catalog::factory()->published()->create(['slug' => 'temporada']));
        $draft = $this->within(fn (): Product => Product::factory()->create());
        $this->within(fn () => $catalog->products()->attach($draft->getKey(), ['position' => 1]));
        $this->assertSame([['kind' => 'published_catalog_empty', 'catalogSlug' => 'temporada']], $this->warnings(), 'Un borrador no llena el catálogo.');

        $this->within(fn () => Product::factory()->create());
        $this->assertSame(['published_catalog_empty'], $this->kinds(), 'Un borrador sin catálogo no es un huérfano publicado.');

        foreach (['a', 'b', 'c'] as $slug) {
            $this->within(fn () => Catalog::factory()->published()->create(['slug' => "vacio-{$slug}", 'name' => "A {$slug}"]));
        }

        $this->assertCount(DashboardWarningsQuery::MAX_EMPTY_CATALOGS, $this->warnings());
    }

    public function test_metrics_are_cached_sixty_seconds_and_survive_without_cache(): void
    {
        $this->assertSame(0, $this->metrics()['pendingReceipts']);
        $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active));
        $this->assertSame(0, $this->metrics()['pendingReceipts']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $this->travel(61)->seconds();
        $this->assertSame(1, $this->metrics()['pendingReceipts']);

        foreach (['get', 'put', 'remember'] as $method) {
            Cache::shouldReceive($method)->andThrow(new RuntimeException('Redis no responde.'));
        }

        $this->assertSame(1, $this->metrics()['pendingReceipts']);
    }

    public function test_a_pass_with_a_failed_metric_is_not_cached(): void
    {
        $key = 'dashboard:metrics:'.$this->organization->getKey();
        $subscriptions = DB::table('subscriptions')->get()->map(fn (object $row): array => (array) $row)->all();
        DB::table('subscriptions')->delete();

        $this->assertNull($this->metrics()['plan']);
        $this->assertFalse(Cache::has($key));

        DB::table('subscriptions')->insert($subscriptions);

        $this->assertNotNull($this->metrics()['plan']);
        $this->assertTrue(Cache::has($key));
    }

    public function test_a_failing_metric_does_not_take_the_dashboard_down(): void
    {
        DB::table('subscriptions')->delete();

        $metrics = $this->metrics();

        $this->assertNull($metrics['plan']);
        $this->assertSame(0, $metrics['pendingReceipts']);
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
    }

    public function test_the_dashboard_lists_the_oldest_pending_receipts_first(): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        $newest = $this->receiptFor($active, attributes: ['created_at' => CarbonImmutable::now()->subHour()]);
        $oldest = $this->receiptFor($active, attributes: ['created_at' => CarbonImmutable::now()->subHours(50)]);
        $this->receiptFor($cancelled, attributes: ['created_at' => CarbonImmutable::now()->subHours(90)]);

        foreach (range(1, 4) as $hours) {
            $this->receiptFor($active, attributes: ['created_at' => CarbonImmutable::now()->subMinutes($hours)]);
        }

        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pendingReceipts', 4)
            ->where('pendingReceipts.0.id', $oldest->getKey())
            ->where('pendingReceipts.0.overdue', true)
            ->where('pendingReceipts.0.layawayCode', $active->code)
            ->where('pendingReceipts.1.id', $newest->getKey())
            ->where('pendingReceipts.1.overdue', false)
            ->where('pendingReceipts.0.reviewUrl', route('receipts.show', $oldest)));
    }

    public function test_a_suspended_business_sees_how_to_lift_it(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('suspension', null));

        $this->within(function (): void {
            SubscriptionInvoice::factory()->create(['due_date' => '2026-09-20', 'amount_cents' => 1_500]);
            Subscription::query()->update(['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => CarbonImmutable::parse('2026-09-27 03:00:00')]);
        });
        $this->organization->forceFill(['status' => 'suspended', 'suspended_at' => CarbonImmutable::parse('2026-09-28 03:00:00')])->save();
        $this->withoutOrganization();

        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('suspension.suspendedAt', '2026-09-28')
            ->where('suspension.graceEndsAt', '2026-09-27')
            ->where('suspension.invoice.dueDate', '2026-09-20')
            ->where('suspension.invoice.amount', '$15,00')
            ->where('suspension.invoice.amountVes', 'Bs. 637,50')
            ->where('suspension.invoice.number', fn (string $number): bool => str_starts_with($number, 'F-'))
            ->where('links.invoices', route('subscription.invoices.index')));
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(): array
    {
        return $this->within(fn (): array => app(DashboardMetricsQuery::class)->compute($this->organization));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function warnings(): array
    {
        return $this->within(fn (): array => app(DashboardWarningsQuery::class)->forOrganization($this->organization));
    }

    /**
     * @return list<string>
     */
    private function kinds(): array
    {
        return array_column($this->warnings(), 'kind');
    }

    private function installment(Layaway $layaway, int $number, string $due): void
    {
        DB::table('installments')->insert([
            'organization_id' => $layaway->organization_id,
            'layaway_id' => $layaway->getKey(),
            'number' => $number,
            'due_date' => $due,
            'amount_cents' => 3_000,
            'paid_cents' => 0,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payment(Layaway $layaway, int $cents, CarbonImmutable $settledAt): void
    {
        $this->within(fn () => Payment::factory()->createOne([
            'layaway_id' => $layaway->getKey(),
            'amount_cents_usd' => new Money($cents),
            'settled_at' => $settledAt->setTimezone((string) config('app.timezone')),
        ]));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
