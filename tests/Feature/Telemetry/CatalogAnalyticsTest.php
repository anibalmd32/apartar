<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Domain\Telemetry\ServerTelemetry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const ANA = '0190f0f0-0000-7000-8000-00000000000a';

    private const BETO = '0190f0f0-0000-7000-8000-00000000000b';

    private Organization $organization;

    private Organization $neighbour;

    private User $member;

    private Catalog $catalog;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-22 12:00:00');
        $this->organization = Organization::factory()->create();
        $this->neighbour = Organization::factory()->create();
        [$this->member, $this->catalog, $this->product] = $this->withinOrganization($this->organization, fn (): array => [
            User::factory()->create(),
            Catalog::factory()->published()->create(['name' => 'Ofertas']),
            Product::factory()->published()->create(['name' => 'Taladro']),
        ]);
        $this->withoutOrganization();

        $this->event('catalog_viewed', self::ANA);
        $this->event('product_viewed', self::ANA, subject: 'product');
        $this->event('layaway_form_started', self::ANA, subject: 'product');
        $this->event('layaway_created', ServerTelemetry::NO_VISITOR, accountId: 77);
        $this->event('catalog_viewed', self::BETO, accountId: 77);
        $this->event('search_performed', self::BETO, payload: ['term' => 'Brocas', 'results' => 0]);
        $this->event('search_performed', self::BETO, payload: ['term' => 'taladro', 'results' => 3]);
        $this->event('catalog_viewed', self::ANA, organization: $this->neighbour);
        $this->event('search_performed', self::ANA, payload: ['term' => 'secreto-del-vecino', 'results' => 0], organization: $this->neighbour);
        $this->event('catalog_viewed', self::ANA, daysAgo: 45);
        DB::table('telemetry_monthly_aggregates')->insert(['organization_id' => $this->organization->getKey(), 'event_name' => 'catalog_viewed', 'year_month' => '2026-06', 'count' => 340, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_paid_plan_sees_the_whole_aggregate_picture(): void
    {
        $this->onPlan(Plan::factory()->unlimited()->withEveryFeature()->create());

        $analytics = $this->props()['analytics'];

        $this->assertSame(['visits' => 2, 'visitors' => 2, 'layaways' => 1], $analytics['totals']);
        $this->assertSame([2, 1, 1, 1], array_column($analytics['funnel'], 'count'));
        $this->assertSame([['name' => 'Ofertas', 'slug' => $this->catalog->slug, 'visits' => 2, 'layaways' => 1]], $analytics['catalogs']);
        $this->assertSame([['name' => 'Taladro', 'views' => 1]], $analytics['topProducts']);
        $this->assertSame([['term' => 'brocas', 'count' => 1]], $analytics['searchesWithoutResults']);

        $months = array_column($analytics['monthly'], 'visits', 'month');
        $this->assertSame(340, $months['2026-06']);
        $this->assertSame(1, $months['2026-09']);
        $this->assertSame(2, $months['2026-10']);
    }

    public function test_nothing_identifies_a_person_or_leaks_another_business(): void
    {
        $this->onPlan(Plan::factory()->unlimited()->withEveryFeature()->create());

        $json = (string) json_encode($this->props()['analytics']);

        foreach (['account', self::ANA, self::BETO, 'secreto-del-vecino', 'ip_hash', 'user_agent', 'Prueba/1.0', 'visitor_id', 'customer'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
    }

    public function test_free_sees_only_the_basic_counts(): void
    {
        $this->onPlan(Plan::factory()->create(['features' => []]));

        $props = $this->props();

        $this->assertFalse($props['full']);
        $this->assertSame(['visits' => 2, 'visitors' => 2, 'layaways' => 1], $props['analytics']['totals']);
        $this->assertNull($props['analytics']['funnel']);
        $this->assertNull($props['analytics']['monthly']);
        $this->assertStringNotContainsString('Brocas', (string) json_encode($props));
    }

    public function test_an_unpublished_catalog_keeps_its_analytics(): void
    {
        $this->onPlan(Plan::factory()->unlimited()->withEveryFeature()->create());
        $this->withinOrganization($this->organization, fn () => $this->catalog->forceFill(['published_at' => null])->save());
        $this->withoutOrganization();

        $this->assertSame('Ofertas', $this->props()['analytics']['catalogs'][0]['name']);
    }

    public function test_it_compares_with_the_previous_period_and_filters_by_catalog(): void
    {
        $this->onPlan(Plan::factory()->unlimited()->withEveryFeature()->create());

        $props = $this->props();
        $this->assertSame(['visits' => 1, 'visitors' => 1, 'layaways' => 0], $props['previous']);
        $this->assertSame([$this->catalog->slug], array_column($props['catalogOptions'], 'value'));

        $other = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create(['name' => 'Navidad']));
        $this->withoutOrganization();

        $filtered = $this->actingAs($this->member)->get(route('analytics.index', ['periodo' => 30, 'catalogo' => $other->slug]))->viewData('page')['props'];
        $this->assertSame($other->slug, $filtered['catalog']);
        $this->assertSame(['visits' => 0, 'visitors' => 0, 'layaways' => 0], $filtered['analytics']['totals']);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(): array
    {
        return $this->actingAs($this->member)->get(route('analytics.index', ['periodo' => 30]))->assertOk()->viewData('page')['props'];
    }

    private function onPlan(Plan $plan): void
    {
        $this->withinOrganization($this->organization, fn () => Subscription::factory()->forPlan($plan)->create());
        $this->withoutOrganization();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(string $name, string $visitor, ?string $subject = null, ?int $accountId = null, array $payload = [], ?Organization $organization = null, int $daysAgo = 1): void
    {
        $organization ??= $this->organization;
        $own = $organization->is($this->organization);

        DB::table('telemetry_events')->insert([
            'organization_id' => $organization->getKey(),
            'catalog_id' => $own ? $this->catalog->getKey() : null,
            'event_name' => $name,
            'visitor_id' => $visitor,
            'account_id' => $accountId,
            'subject_type' => $subject,
            'subject_id' => $subject === null ? null : $this->product->getKey(),
            'payload' => (string) json_encode((object) $payload),
            'session_id' => 'sesion-abc-123',
            'ip_hash' => str_repeat('a', 64),
            'user_agent' => 'Prueba/1.0',
            'occurred_at' => now()->subDays($daysAgo),
        ]);
    }
}
