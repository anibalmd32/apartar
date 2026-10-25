<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Http\Middleware\EnsureAccountSession;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class FeedTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-21 12:00:00');
        $this->account = Account::factory()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_only_an_account_reaches_the_feed(): void
    {
        $this->get(route('portal.feed'))->assertRedirect(route('account.google.redirect'));

        $organization = Organization::factory()->create();
        $customer = $this->withinOrganization($organization, fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($customer, 'customer')
            ->get(route('portal.feed'))
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('status', EnsureAccountSession::CONNECT_GOOGLE_MESSAGE);
    }

    public function test_each_item_is_projected_with_its_own_business_rate(): void
    {
        ExchangeRate::factory()->create(['rate' => '40.00000000']);
        ExchangeRate::factory()->parallel()->create(['rate' => '50.00000000']);
        Cache::flush();

        $bcv = $this->followedWith('Ferretería Oriente', RateSource::Bcv, 'Taladro');
        $parallel = $this->followedWith('Boutique Sur', RateSource::Parallel, 'Vestido');

        $props = $this->actingAs($this->account, 'account')->get(route('portal.feed'))->assertOk()->viewData('page')['props'];
        $byName = array_column($props['items']['data'], null, 'businessName');

        $this->assertSame('Bs. 4.000,00', $byName['Ferretería Oriente']['product']['price']['ves']);
        $this->assertSame('Bs. 5.000,00', $byName['Boutique Sur']['product']['price']['ves']);
        $this->assertSame(route('catalog.product', ['catalog' => $bcv->slug, 'product' => 'taladro']), $byName['Ferretería Oriente']['url']);
        $this->assertNotSame($bcv->slug, $parallel->slug);
        $this->assertCount(2, $props['subscriptions']);
    }

    public function test_the_account_dashboard_previews_six_items(): void
    {
        $organization = Organization::factory()->create();
        $catalog = $this->withinOrganization($organization, function (): Catalog {
            $catalog = Catalog::factory()->published()->create();

            foreach (range(1, 8) as $index) {
                $catalog->products()->attach(Product::factory()->published()->create(['name' => "Producto {$index}"]), ['position' => $index]);
            }

            return $catalog;
        });
        $this->withoutOrganization();
        CatalogSubscription::factory()->create(['account_id' => $this->account->getKey(), 'catalog_id' => $catalog->getKey()]);

        $this->actingAs($this->account, 'account')->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('feedPreview.items', 6)
            ->where('feedPreview.url', route('portal.feed')));
    }

    private function followedWith(string $business, RateSource $source, string $productName): Catalog
    {
        $organization = Organization::factory()->create(['name' => $business]);

        $catalog = $this->withinOrganization($organization, function () use ($source, $productName): Catalog {
            OrganizationSetting::factory()->forKey(SettingKey::ExchangeRateSource, $source->value)->create();
            $catalog = Catalog::factory()->published()->create();
            $catalog->products()->attach(Product::factory()->published()->create([
                'name' => $productName,
                'slug' => strtolower($productName),
                'price_cents' => 10_000,
            ]), ['position' => 1]);

            return $catalog;
        });

        $this->withoutOrganization();
        CatalogSubscription::factory()->create(['account_id' => $this->account->getKey(), 'catalog_id' => $catalog->getKey()]);

        return $catalog;
    }
}
