<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Account\PortalContext;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Settings\SettingKey;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Queries\BusinessBalance;
use App\Queries\CustomerBalanceQuery;
use App\Queries\PortalAlert;
use App\Queries\PortalAlertsQuery;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalAlertsQueryTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_priority_of_rb2_is_respected(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $overdue = $this->scheduled($organization);
        $customer = $this->customerOf($overdue);
        $soon = $this->scheduled($organization, $customer, activatedOn: '2026-09-15 10:00:00');
        $waiting = $this->own($this->layawayIn($organization), $customer);
        $inReview = $this->own($this->layawayIn($organization), $customer);
        $this->window($waiting, '2026-09-27 18:00:00');
        $this->window($inReview, '2026-09-28 18:00:00');
        $this->fakeReceipts(pending: [$inReview->getKey() => 1], rejected: [$soon->getKey()]);

        CarbonImmutable::setTestNow('2026-09-27 12:00:00');

        $alerts = $this->alertsFor($customer);

        $this->assertSame(['down_payment_window', 'overdue', 'receipt_rejected', 'due_soon', 'receipt_pending'], $this->kinds($alerts));
        $this->assertSame($waiting->code, $alerts[0]->layawayCode);
        $this->assertSame($overdue->code, $alerts[1]->layawayCode);
        $this->assertSame('2026-09-30', $alerts[3]->dueDate?->toDateString());
    }

    public function test_the_due_soon_threshold_is_the_one_of_reminder_offsets_days(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $layaway = $this->scheduled($organization);
        $customer = $this->customerOf($layaway);

        $expectations = ['2026-09-21 12:00:00' => false, '2026-09-22 12:00:00' => true, '2026-09-25 23:00:00' => true];

        foreach ($expectations as $now => $soon) {
            CarbonImmutable::setTestNow($now);

            $this->assertSame($soon ? ['due_soon'] : [], $this->kinds($this->alertsFor($customer)), $now);
            $this->assertSame($soon ? 'soon' : 'pending', $this->toneOfFirstInstallment($customer, $layaway), $now);
        }

        $this->withinOrganization($organization, fn () => OrganizationSetting::factory()->forKey(SettingKey::ReminderOffsetsDays, [-5, 0, 1])->create());
        $this->withoutOrganization();
        app(Settings::class)->forget($organization);
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');

        $this->assertSame(['due_soon'], $this->kinds($this->alertsFor($customer)));
        $this->assertSame('soon', $this->toneOfFirstInstallment($customer, $layaway));
    }

    public function test_overdue_installments_in_three_businesses_are_one_consolidated_alert(): void
    {
        ExchangeRate::factory()->create(['rate' => '40.00000000']);
        ExchangeRate::factory()->parallel()->create(['rate' => '50.00000000']);
        Cache::flush();

        $blocks = [];

        foreach (['Ferretería Oriente' => RateSource::Bcv, 'Boutique Sur' => RateSource::Parallel, 'Óptica Centro' => RateSource::Bcv] as $name => $source) {
            $organization = $this->business($name);
            $this->withinOrganization($organization, fn () => OrganizationSetting::factory()->forKey(SettingKey::ExchangeRateSource, $source->value)->create());
            $this->withoutOrganization();
            $blocks = [...$blocks, ...$this->blocksFor($this->customerOf($this->scheduled($organization)), '2026-09-27 12:00:00')];
        }

        $alerts = app(PortalAlertsQuery::class)->forPortalContext(new PortalContext(null), $blocks);

        $this->assertSame(['overdue'], $this->kinds($alerts));
        $this->assertNull($alerts[0]->organization);
        $this->assertSame(3, $alerts[0]->count);

        $wire = (new \App\Http\Resources\PortalAlertResource($alerts[0]))->resolve(request());

        $this->assertNull($wire['businessName']);
        $this->assertSame(9_000, $wire['amount']['cents']);
        $this->assertSame('Bs. 3.900,00', $wire['amount']['ves']);
    }

    public function test_overdue_installments_of_one_business_name_it(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $first = $this->scheduled($organization);
        $customer = $this->customerOf($first);
        $this->scheduled($organization, $customer);
        DB::table('installments')->where('layaway_id', $first->getKey())->where('number', 1)->update(['paid_cents' => 1_000, 'status' => 'partially_paid']);

        CarbonImmutable::setTestNow('2026-10-12 12:00:00');

        $alerts = $this->alertsFor($customer);

        $this->assertSame(['overdue'], $this->kinds($alerts));
        $this->assertSame($organization->getKey(), $alerts[0]->organization?->getKey());
        $this->assertSame(4, $alerts[0]->count);
        $this->assertSame(11_000, $alerts[0]->amounts[0]['amount']->cents);
    }

    public function test_an_expired_window_with_a_receipt_in_review_shows_receipt_pending(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $closed = $this->layawayIn($organization);
        $customer = $this->customerOf($closed);
        $open = $this->own($this->layawayIn($organization), $customer);
        $this->window($closed, '2026-09-27 10:00:00');
        $this->window($open, '2026-09-28 10:00:00');
        $this->fakeReceipts(pending: [$closed->getKey() => 1, $open->getKey() => 1]);

        CarbonImmutable::setTestNow('2026-09-27 12:00:00');

        $alerts = $this->alertsFor($customer);

        $this->assertSame(['receipt_pending'], $this->kinds($alerts));
        $this->assertSame(2, $alerts[0]->count);
    }

    public function test_the_down_payment_window_counts_minutes_and_closes(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $layaway = $this->layawayIn($organization);
        $customer = $this->customerOf($layaway);
        $this->window($layaway, '2026-09-27 12:40:00');

        CarbonImmutable::setTestNow('2026-09-27 12:00:00');
        $this->actingAs($customer, 'customer');

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('alerts', 1)
            ->where('alerts.0.kind', 'down_payment_window')
            ->where('alerts.0.hoursLeft', 0)
            ->where('alerts.0.minutesLeft', 40)
            ->where('alerts.0.layawayCode', $layaway->code)
            ->where('alerts.0.businessName', 'Ferretería Oriente')
            ->where('alerts.0.actionUrl', route('portal.receipts.create', ['code' => $layaway->code])));

        CarbonImmutable::setTestNow('2026-09-27 12:40:00');

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->has('alerts', 0));
    }

    public function test_a_down_payment_paid_in_part_no_longer_counts_down_the_window(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $layaway = $this->layawayIn($organization);
        $customer = $this->customerOf($layaway);
        $this->window($layaway, '2026-09-27 12:40:00');
        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 2_000, 'remaining_cents' => 18_000]);

        CarbonImmutable::setTestNow('2026-09-27 12:00:00');

        $this->assertSame([], $this->kinds($this->alertsFor($customer)));
    }

    public function test_terminal_layaways_are_excluded(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $cancelled = $this->scheduled($organization);
        $customer = $this->customerOf($cancelled);
        $expired = $this->own($this->layawayIn($organization), $customer);
        $this->withinOrganization($organization, function () use ($cancelled, $expired): void {
            app(LayawayStateMachine::class)->transition($cancelled, LayawayStatus::Cancelled);
            app(LayawayStateMachine::class)->transition($expired, LayawayStatus::Expired);
        });
        $this->withoutOrganization();
        $this->fakeReceipts(pending: [$expired->getKey() => 1], rejected: [$cancelled->getKey()]);

        CarbonImmutable::setTestNow('2026-10-12 12:00:00');

        $this->assertSame([], $this->alertsFor($customer));
    }

    public function test_without_any_condition_there_are_no_alerts(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $layaway = $this->scheduled($organization);
        $customer = $this->customerOf($layaway);

        CarbonImmutable::setTestNow('2026-09-15 12:00:00');
        $this->actingAs($customer, 'customer');

        $this->get(route('portal.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('alerts', []));
    }

    public function test_claim_available_does_not_reveal_whether_a_record_exists(): void
    {
        $ours = $this->business('Ferretería Oriente');
        $theirs = $this->business('Boutique Sur');
        $withElsewhere = $this->scheduled($ours);
        $alone = $this->scheduled($ours);
        $this->layawayIn($theirs);
        DB::table('customers')->where('id', $withElsewhere->customer_id)->update(['document_number' => 'V12345678']);
        DB::table('customers')->where('organization_id', $theirs->getKey())->update(['document_number' => 'V12345678']);

        CarbonImmutable::setTestNow('2026-09-23 12:00:00');

        $first = $this->kinds($this->alertsFor($this->customerOf($withElsewhere)));
        $second = $this->kinds($this->alertsFor($this->customerOf($alone)));

        $this->assertSame(['due_soon'], $first);
        $this->assertSame($first, $second);
    }

    public function test_claim_available_invites_for_a_followed_business_without_a_record(): void
    {
        $followed = $this->business('Boutique Sur');
        $catalog = $this->withinOrganization($followed, fn (): Catalog => Catalog::factory()->published()->create());
        $withRecord = $this->layawayIn($followed);
        DB::table('customers')->where('id', $withRecord->customer_id)->update(['document_number' => 'V12345678']);
        $this->withoutOrganization();

        $account = Account::factory()->create();
        CatalogSubscription::factory()->create(['account_id' => $account->getKey(), 'catalog_id' => $catalog->getKey()]);

        $documentQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$documentQueries): void {
            $documentQueries += str_contains($query->sql, 'document_number') ? 1 : 0;
        });

        $this->actingAs($account, 'account')->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alerts', [[
                'kind' => 'claim_available',
                'businessName' => 'Boutique Sur',
                'actionUrl' => route('portal.claim'),
            ]]));

        $this->assertSame(0, $documentQueries);
    }

    public function test_claim_available_is_not_raised_where_the_account_already_has_a_record(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $catalog = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create());
        $layaway = $this->layawayIn($organization);
        $this->withoutOrganization();

        $account = Account::factory()->create();
        DB::table('customers')->where('id', $layaway->customer_id)->update(['account_id' => $account->getKey()]);

        $this->assertNotContains('claim_available', $this->kinds($this->alertsFor(PortalContext::forAccount($account))));

        CatalogSubscription::factory()->create(['account_id' => $account->getKey(), 'catalog_id' => $catalog->getKey()]);
        $this->withoutOrganization();

        $this->assertNotContains('claim_available', $this->kinds($this->alertsFor(PortalContext::forAccount($account))));
    }

    public function test_real_receipts_raise_receipt_pending_and_receipt_rejected(): void
    {
        $organization = $this->business('Ferretería Oriente');
        $waiting = $this->layawayIn($organization);
        $customer = $this->customerOf($waiting);
        $active = $this->scheduled($organization, $customer);
        $this->window($waiting, '2026-09-20 10:00:00');
        $this->receiptFor($waiting);
        $this->receiptFor($active, ReceiptStatus::Rejected);

        CarbonImmutable::setTestNow('2026-09-15 12:00:00');
        $this->actingAs($customer, 'customer');

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('alerts', 2)
            ->where('alerts.0.kind', 'receipt_rejected')
            ->where('alerts.0.layawayCode', $active->code)
            ->where('alerts.0.businessName', 'Ferretería Oriente')
            ->where('alerts.1.kind', 'receipt_pending')
            ->where('alerts.1.count', 1));
    }

    private function business(string $name): Organization
    {
        return $this->subscribe(Organization::factory()->create(['name' => $name, 'timezone' => 'America/Caracas']));
    }

    private function scheduled(Organization $organization, ?Customer $customer = null, string $activatedOn = '2026-09-10 10:00:00'): Layaway
    {
        $layaway = $this->layawayIn($organization);

        if ($customer instanceof Customer) {
            $this->own($layaway, $customer);
        }

        CarbonImmutable::setTestNow($activatedOn);
        $this->withinOrganization($organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        $this->withoutOrganization();
        CarbonImmutable::setTestNow();

        return $layaway->refresh();
    }

    private function own(Layaway $layaway, Customer $customer): Layaway
    {
        DB::table('layaways')->where('id', $layaway->getKey())->update(['customer_id' => $customer->getKey()]);

        return $layaway;
    }

    private function window(Layaway $layaway, string $expiresAt): void
    {
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => $expiresAt]);
    }

    private function customerOf(Layaway $layaway): Customer
    {
        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($layaway->customer_id);

        return $customer;
    }

    /**
     * @param  array<int, int>  $pending
     * @param  list<int>  $rejected
     */
    private function fakeReceipts(array $pending = [], array $rejected = []): void
    {
        $this->app->instance(LayawayReceipts::class, new class($pending, $rejected) extends LayawayReceipts
        {
            /**
             * @param  array<int, int>  $pending
             * @param  list<int>  $rejected
             */
            public function __construct(private readonly array $pending, private readonly array $rejected) {}

            public function pendingReviewCounts(array $layaways): array
            {
                $ids = array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways);

                return array_intersect_key($this->pending, array_flip($ids));
            }

            public function rejectedAwaitingRetry(array $layaways): array
            {
                $ids = array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways);

                return array_values(array_intersect($this->rejected, $ids));
            }
        });
    }

    /**
     * @return list<BusinessBalance>
     */
    private function blocksFor(Customer $customer, ?string $now = null): array
    {
        if ($now !== null) {
            CarbonImmutable::setTestNow($now);
        }

        return app(CustomerBalanceQuery::class)->forPortalContext(new PortalContext($customer));
    }

    /**
     * @return list<PortalAlert>
     */
    private function alertsFor(Customer|PortalContext $session): array
    {
        $portal = $session instanceof PortalContext ? $session : new PortalContext($session);

        return app(PortalAlertsQuery::class)->forPortalContext($portal, app(CustomerBalanceQuery::class)->forPortalContext($portal));
    }

    /**
     * @param  list<PortalAlert>  $alerts
     * @return list<string>
     */
    private function kinds(array $alerts): array
    {
        return array_map(fn (PortalAlert $alert): string => $alert->kind->value, $alerts);
    }

    private function toneOfFirstInstallment(Customer $customer, Layaway $layaway): string
    {
        $this->actingAs($customer, 'customer');
        $props = $this->get(route('portal.layaways.show', ['code' => $layaway->code]))->assertOk()->viewData('page')['props'];
        app('auth')->forgetGuards();

        return $props['installments'][0]['tone'];
    }
}
