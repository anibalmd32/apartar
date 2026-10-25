<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Domain\Account\CatalogNotSubscribableException;
use App\Domain\Account\FollowIntent;
use App\Http\Controllers\Catalog\CatalogSubscriptionController;
use App\Http\Controllers\Portal\Auth\GoogleAccountController;
use App\Http\Middleware\EnsureAccountSession;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

final class CatalogSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-21 10:00:00');
        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['name' => 'Ferretería Oriente']);
        $this->catalog = $this->withinOrganization($this->organization, function (): Catalog {
            Subscription::factory()->forPlan(Plan::factory()->create())->create();

            return Catalog::factory()->published()->create(['name' => 'Ofertas de octubre']);
        });
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_following_without_a_session_completes_after_google(): void
    {
        $this->follow()->assertRedirectContains('/cuenta/google/redirect');

        Socialite::fake('google', $this->google());
        $this->get($this->followRedirectTarget());
        $completion = $this->get(route('account.google.callback', ['code' => 'c', 'state' => 's']));

        $completion->assertRedirect(route('account.follow.complete', absolute: false));
        $this->get(route('account.follow.complete'))
            ->assertRedirect($this->path())
            ->assertSessionHas('status', sprintf(CatalogSubscriptionController::FOLLOWING_MESSAGE, 'Ofertas de octubre'));

        $account = Account::query()->sole();
        $this->assertSame([(int) $this->catalog->getKey()], $account->subscriptions()->pluck('catalog_id')->all());

        $this->get($this->path())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('follow.signedIn', true)
            ->where('follow.following', true));
    }

    public function test_an_inertia_follow_without_a_session_leaves_with_a_full_page_location(): void
    {
        $this->post(route('catalog.follow', ['catalog' => $this->catalog->slug]), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');

        $this->assertTrue(session()->has(FollowIntent::SESSION_KEY));
    }

    public function test_an_abandoned_intent_expires_and_leaves_no_trace(): void
    {
        $this->follow();
        $this->travel(FollowIntent::TTL_MINUTES + 1)->minutes();

        $this->actingAs(Account::factory()->create(), 'account');
        $this->get(route('account.follow.complete'))->assertRedirect(route('portal.subscriptions'));

        $this->assertSame(0, CatalogSubscription::query()->count());
        $this->assertFalse(session()->has(FollowIntent::SESSION_KEY));
    }

    public function test_cancelling_at_google_returns_to_the_catalog(): void
    {
        $this->follow();
        $this->get($this->followRedirectTarget());

        $this->get(route('account.google.callback', ['error' => 'access_denied']))
            ->assertRedirect($this->path())
            ->assertSessionHas('status', GoogleAccountController::CANCELLED_MESSAGE);

        $this->assertSame(0, CatalogSubscription::query()->count());
    }

    public function test_a_catalog_that_expired_meanwhile_is_not_followed(): void
    {
        $this->follow();
        $this->withinOrganization($this->organization, fn () => $this->catalog->forceFill(['expires_at' => now()->subMinute()])->save());
        $this->withoutOrganization();

        $this->actingAs(Account::factory()->create(), 'account');
        $this->get(route('account.follow.complete'))
            ->assertRedirect($this->path())
            ->assertSessionHas('status', CatalogNotSubscribableException::MESSAGE);

        $this->assertSame(0, CatalogSubscription::query()->count());
    }

    public function test_completing_without_an_account_does_nothing(): void
    {
        $this->follow();

        $this->get(route('account.follow.complete'))->assertRedirect(route('home'));
        $this->assertSame(0, CatalogSubscription::query()->count());
    }

    public function test_following_with_a_session_is_immediate_and_idempotent(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($account, 'account');

        $this->follow()->assertRedirect();
        $this->follow()->assertRedirect();

        $this->assertSame(1, CatalogSubscription::query()->where('account_id', $account->getKey())->count());
    }

    public function test_an_expired_catalog_is_refused_with_422(): void
    {
        $this->withinOrganization($this->organization, fn () => $this->catalog->forceFill(['expires_at' => now()->subDay()])->save());
        $this->withoutOrganization();
        $this->actingAs(Account::factory()->create(), 'account');

        $this->postJson(route('catalog.follow', ['catalog' => $this->catalog->slug]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['follow' => CatalogNotSubscribableException::MESSAGE]);

        $this->get($this->path())->assertStatus(410);
        $this->assertSame(0, CatalogSubscription::query()->count());
    }

    public function test_unfollowing_deletes_the_row(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($account, 'account');
        $this->follow();

        $this->delete(route('catalog.unfollow', ['catalog' => $this->catalog->slug]))->assertRedirect();

        $this->assertSame(0, CatalogSubscription::query()->count());
        $this->get($this->path())->assertInertia(fn (AssertableInertia $page) => $page->where('follow.following', false));
    }

    public function test_the_catalog_is_browsable_without_an_account(): void
    {
        CatalogSubscription::factory()->count(3)->create(['catalog_id' => $this->catalog->getKey()]);
        $this->withoutOrganization();

        $this->get($this->path())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('follow', [
                'signedIn' => false,
                'following' => false,
                'url' => route('catalog.follow', ['catalog' => $this->catalog->slug]),
            ]));
    }

    public function test_the_subscriptions_page_requires_an_account(): void
    {
        $this->get(route('portal.subscriptions'))->assertRedirect(route('account.google.redirect'));
    }

    public function test_the_subscriptions_page_lists_catalogs_of_several_businesses(): void
    {
        $account = Account::factory()->create();
        $neighbour = Organization::factory()->create(['name' => 'Bodega Central']);

        $this->withinOrganization($this->organization, function (): void {
            $products = Product::factory()->published()->count(3)->create();
            $hidden = Product::factory()->create();

            foreach ([...$products, $hidden] as $index => $product) {
                $this->catalog->products()->attach($product, ['position' => $index + 1]);
            }

            DB::table('catalog_product')->where('catalog_id', $this->catalog->getKey())->update(['created_at' => '2026-09-01 10:00:00']);
            DB::table('catalog_product')->where('product_id', $products[0]->getKey())->update(['created_at' => '2026-10-15 10:00:00']);
        });
        $closed = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->published()->create(['name' => 'Liquidación']));

        $this->subscribeTo($account, $this->catalog, '2026-10-01 10:00:00');
        $this->subscribeTo($account, $closed, '2026-10-10 10:00:00', muted: true);
        $this->withinOrganization($neighbour, fn () => $closed->forceFill(['published_at' => null])->save());
        $this->withoutOrganization();

        $this->actingAs($account, 'account')
            ->get(route('portal.subscriptions'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/Subscriptions')
                ->has('subscriptions', 2)
                ->where('subscriptions.0.catalogName', 'Liquidación')
                ->where('subscriptions.0.businessName', 'Bodega Central')
                ->where('subscriptions.0.active', false)
                ->where('subscriptions.0.muted', true)
                ->where('subscriptions.0.url', null)
                ->where('subscriptions.0.productsCount', 0)
                ->where('subscriptions.1.catalogName', 'Ofertas de octubre')
                ->where('subscriptions.1.businessName', 'Ferretería Oriente')
                ->where('subscriptions.1.active', true)
                ->where('subscriptions.1.productsCount', 3)
                ->where('subscriptions.1.newCount', 1)
                ->where('subscriptions.1.url', route('catalog.show', ['catalog' => $this->catalog->slug])));
    }

    public function test_unpublishing_makes_it_inactive_and_republishing_revives_it(): void
    {
        $account = Account::factory()->create();
        $this->subscribeTo($account, $this->catalog, '2026-10-01 10:00:00');

        $this->withinOrganization($this->organization, fn () => $this->catalog->forceFill(['published_at' => null])->save());
        $this->withoutOrganization();
        $this->actingAs($account, 'account')->get(route('portal.subscriptions'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscriptions.0.active', false));
        $this->assertSame(1, CatalogSubscription::query()->count());

        $this->withinOrganization($this->organization, fn () => $this->catalog->forceFill(['published_at' => now()])->save());
        $this->withoutOrganization();
        $this->get(route('portal.subscriptions'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscriptions.0.active', true));
    }

    public function test_an_inactive_subscription_can_still_be_left(): void
    {
        $account = Account::factory()->create();
        $this->subscribeTo($account, $this->catalog, '2026-10-01 10:00:00');
        $this->withinOrganization($this->organization, fn () => $this->catalog->forceFill(['published_at' => null])->save());
        $this->withoutOrganization();

        $this->actingAs($account, 'account')->delete(route('catalog.unfollow', ['catalog' => $this->catalog->slug]))->assertRedirect();

        $this->assertSame(0, CatalogSubscription::query()->count());
    }

    public function test_muting_from_the_page_keeps_the_subscription(): void
    {
        $account = Account::factory()->create();
        $this->subscribeTo($account, $this->catalog, '2026-10-01 10:00:00');
        $this->actingAs($account, 'account');

        $this->patch(route('portal.subscriptions.update', ['catalog' => $this->catalog->getKey()]), ['muted' => true])->assertRedirect();
        $this->assertNotNull(CatalogSubscription::query()->sole()->muted_at);

        $this->patch(route('portal.subscriptions.update', ['catalog' => $this->catalog->getKey()]), ['muted' => false])->assertRedirect();
        $this->assertNull(CatalogSubscription::query()->sole()->muted_at);

        $this->patch(route('portal.subscriptions.update', ['catalog' => 999_999]), ['muted' => true])->assertNotFound();
    }

    public function test_a_scoped_portal_session_does_not_reach_the_subscriptions(): void
    {
        $customer = $this->customerInOrganization();
        $this->withoutOrganization();

        $this->actingAs($customer, 'customer')
            ->get(route('portal.subscriptions'))
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('status', EnsureAccountSession::CONNECT_GOOGLE_MESSAGE);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function follow(): TestResponse
    {
        return $this->post(route('catalog.follow', ['catalog' => $this->catalog->slug]));
    }

    private function followRedirectTarget(): string
    {
        return route('account.google.redirect', [
            'desde' => $this->path(),
            'luego' => route('account.follow.complete', absolute: false),
        ], absolute: false);
    }

    private function path(): string
    {
        return '/c/'.$this->catalog->slug;
    }

    private function subscribeTo(Account $account, Catalog $catalog, string $at, bool $muted = false): void
    {
        CatalogSubscription::factory()->create([
            'account_id' => $account->getKey(),
            'catalog_id' => $catalog->getKey(),
            'subscribed_at' => $at,
            'muted_at' => $muted ? $at : null,
        ]);
    }

    private function customerInOrganization(): Customer
    {
        return $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create());
    }

    private function google(): GoogleUser
    {
        return (new GoogleUser)->setRaw(['sub' => 'sub-1', 'email' => 'maria@gmail.test', 'email_verified' => true])->map([
            'id' => 'sub-1',
            'email' => 'maria@gmail.test',
            'name' => 'María Pérez',
            'avatar' => null,
        ]);
    }
}
