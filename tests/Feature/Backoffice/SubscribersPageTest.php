<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Http\Resources\SubscriberRowResource;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class SubscribersPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Catalog $ofertas;

    private Catalog $novedades;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        [$this->member, $this->ofertas, $this->novedades] = $this->withinOrganization($this->organization, fn (): array => [
            User::factory()->create(),
            Catalog::factory()->published()->create(['name' => 'Ofertas']),
            Catalog::factory()->published()->create(['name' => 'Novedades']),
        ]);
        $this->withoutOrganization();
    }

    public function test_rows_carry_the_public_name_and_never_contact_or_account(): void
    {
        $this->onPlan(withAnalytics: true);
        $account = Account::factory()->create(['display_name' => 'María P.', 'email' => 'maria@gmail.test']);
        $this->follow($account, $this->ofertas);

        $props = $this->actingAs($this->member)->get(route('subscribers.index'))->assertOk()->viewData('page')['props'];
        $row = $props['subscribers'][0];

        $this->assertSame(SubscriberRowResource::FIELDS, array_keys($row));
        $this->assertSame('María P.', $row['displayName']);
        $this->assertFalse($row['isCustomer']);
        $this->assertStringNotContainsString('maria@gmail.test', (string) json_encode($props));
        $this->assertStringNotContainsString('account_id', (string) json_encode($props));
    }

    public function test_a_subscriber_who_is_a_customer_links_to_the_record(): void
    {
        $this->onPlan(withAnalytics: true);
        $account = Account::factory()->create();
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['account_id' => $account->getKey()]));
        $this->withoutOrganization();
        $this->follow($account, $this->ofertas);

        $this->actingAs($this->member)->get(route('subscribers.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subscribers.0.isCustomer', true)
            ->where('subscribers.0.customerUrl', route('customers.show', ['customer' => $customer->getKey()])));
    }

    public function test_another_business_subscriptions_never_show(): void
    {
        $this->onPlan(withAnalytics: true);
        $neighbour = Organization::factory()->create();
        $theirs = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->published()->create(['name' => 'Ajeno']));
        $this->withoutOrganization();

        $account = Account::factory()->create();
        $this->follow($account, $this->ofertas);
        $this->follow($account, $theirs);
        $this->follow(Account::factory()->create(), $theirs);

        $this->actingAs($this->member)->get(route('subscribers.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('subscribers', 1)
            ->where('subscribers.0.catalogName', 'Ofertas')
            ->where('stats.total', 1)
            ->where('stats.people', 1));
    }

    public function test_people_are_counted_once_across_catalogs(): void
    {
        $this->onPlan(withAnalytics: true);
        $account = Account::factory()->create();
        $this->follow($account, $this->ofertas);
        $this->follow($account, $this->novedades);

        $this->actingAs($this->member)->get(route('subscribers.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('subscribers', 2)
            ->where('stats.total', 2)
            ->where('stats.people', 1)
            ->has('stats.byCatalog', 2));
    }

    public function test_it_compares_weeks_and_counts_who_are_already_customers(): void
    {
        $this->onPlan(withAnalytics: true);
        $customer = Account::factory()->create();
        $this->withinOrganization($this->organization, fn () => Customer::factory()->create(['account_id' => $customer->getKey()]));
        $this->withoutOrganization();
        CatalogSubscription::factory()->create(['account_id' => $customer->getKey(), 'catalog_id' => $this->ofertas->getKey(), 'subscribed_at' => now()->subDays(2)]);
        CatalogSubscription::factory()->create(['account_id' => Account::factory()->create()->getKey(), 'catalog_id' => $this->ofertas->getKey(), 'subscribed_at' => now()->subDays(10)]);
        CatalogSubscription::factory()->create(['account_id' => Account::factory()->create()->getKey(), 'catalog_id' => $this->novedades->getKey(), 'subscribed_at' => now()->subDays(9)]);

        $this->actingAs($this->member)->get(route('subscribers.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.last7Days', 1)
            ->where('stats.previous7Days', 2)
            ->where('stats.customers', 1)
            ->has('stats.weekly', 12)
            ->where('stats.weekly.11', 1)
            ->where('stats.weekly.10', 2));
    }

    public function test_without_full_analytics_only_the_total_travels(): void
    {
        $this->onPlan(withAnalytics: false);
        $this->follow(Account::factory()->create(), $this->ofertas);

        $this->actingAs($this->member)->get(route('subscribers.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats', ['total' => 1, 'people' => null, 'last7Days' => null, 'byCatalog' => null, 'previous7Days' => null, 'customers' => null, 'weekly' => null]));
    }

    public function test_unfollowing_removes_the_row_but_keeps_the_customer(): void
    {
        $this->onPlan(withAnalytics: true);
        $account = Account::factory()->create();
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['account_id' => $account->getKey()]));
        $this->withoutOrganization();
        $this->follow($account, $this->ofertas);

        CatalogSubscription::query()->delete();

        $this->actingAs($this->member)->get(route('subscribers.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('subscribers', 0));
        $this->assertNotNull(Customer::query()->withoutGlobalScopes()->find($customer->getKey()));
    }

    public function test_the_customer_profile_lists_only_this_business_catalogs(): void
    {
        $this->onPlan(withAnalytics: true);
        $account = Account::factory()->create();
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['account_id' => $account->getKey()]));
        $neighbour = Organization::factory()->create();
        $theirs = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->published()->create(['name' => 'Ajeno']));
        $this->withoutOrganization();
        $this->follow($account, $this->ofertas);
        $this->follow($account, $theirs);

        $this->actingAs($this->member)->get(route('customers.show', ['customer' => $customer->getKey()]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('subscriptions', 1)
                ->where('subscriptions.0.name', 'Ofertas')
                ->where('subscriptions.0.muted', false)
                ->has('subscriptions.0.since'));
    }

    private function onPlan(bool $withAnalytics): void
    {
        $plan = $withAnalytics ? Plan::factory()->unlimited()->withEveryFeature()->create() : Plan::factory()->create(['features' => []]);
        $this->withinOrganization($this->organization, fn () => Subscription::factory()->forPlan($plan)->create());
        $this->withoutOrganization();
    }

    private function follow(Account $account, Catalog $catalog): void
    {
        CatalogSubscription::factory()->create(['account_id' => $account->getKey(), 'catalog_id' => $catalog->getKey()]);
    }
}
