<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\SubscribeToCatalogAction;
use App\Actions\Account\UnsubscribeFromCatalogAction;
use App\Domain\Account\CatalogNotSubscribableException;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SubscribeToCatalogActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-21 10:00:00');
        $this->organization = Organization::factory()->create();
        $this->account = Account::factory()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_listed_and_unlisted_catalogs_can_be_followed(): void
    {
        foreach ([Catalog::factory()->published(), Catalog::factory()->published()->unlisted()] as $factory) {
            $catalog = $this->withinOrganization($this->organization, fn (): Catalog => $factory->create());

            $subscription = $this->subscribe($catalog);

            $this->assertTrue($subscription->wasRecentlyCreated);
            $this->assertSame('2026-10-21 10:00:00', $subscription->subscribed_at->format('Y-m-d H:i:s'));
            $this->assertNull($subscription->muted_at);
        }

        $this->assertSame(2, CatalogSubscription::query()->count());
    }

    public function test_following_twice_is_idempotent(): void
    {
        $catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());
        $first = $this->subscribe($catalog);
        $first->forceFill(['muted_at' => now()])->save();

        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        $second = $this->subscribe($catalog);

        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('2026-10-21 10:00:00', $second->subscribed_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($second->muted_at);
        $this->assertSame(1, CatalogSubscription::query()->count());
    }

    public function test_a_draft_or_expired_catalog_cannot_be_followed(): void
    {
        foreach ([Catalog::factory(), Catalog::factory()->expired()] as $factory) {
            $catalog = $this->withinOrganization($this->organization, fn (): Catalog => $factory->create());

            try {
                $this->subscribe($catalog);
                $this->fail('Siguió un catálogo que no está accesible.');
            } catch (CatalogNotSubscribableException $exception) {
                $this->assertFalse($exception->visibility->isPubliclyAccessible());
            }
        }

        $this->assertSame(0, CatalogSubscription::query()->count());
    }

    public function test_following_again_after_leaving_is_a_new_row(): void
    {
        $catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());
        $first = $this->subscribe($catalog);
        DB::table('catalog_subscriptions')->delete();

        CarbonImmutable::setTestNow('2026-11-01 09:00:00');
        $again = $this->subscribe($catalog);

        $this->assertNotSame($first->id, $again->id);
        $this->assertSame('2026-11-01 09:00:00', $again->subscribed_at->format('Y-m-d H:i:s'));
    }

    public function test_there_is_no_ceiling_on_subscriptions(): void
    {
        $catalogs = $this->withinOrganization($this->organization, fn () => Catalog::factory()->published()->count(30)->create());

        foreach ($catalogs as $catalog) {
            $this->subscribe($catalog);
        }

        $this->assertSame(30, CatalogSubscription::query()->count());
    }

    public function test_subscribing_and_leaving_record_their_telemetry_once(): void
    {
        $catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());

        $this->subscribe($catalog);
        $this->subscribe($catalog);
        app(UnsubscribeFromCatalogAction::class)->handle($this->account, (int) $catalog->getKey());
        app(UnsubscribeFromCatalogAction::class)->handle($this->account, (int) $catalog->getKey());

        $rows = DB::table('telemetry_events')->orderBy('id')->get();

        $this->assertSame(['catalog_subscribed', 'catalog_unsubscribed'], $rows->pluck('event_name')->all());

        foreach ($rows as $row) {
            $this->assertSame((int) $this->organization->getKey(), (int) $row->organization_id);
            $this->assertSame((int) $catalog->getKey(), (int) $row->catalog_id);
            $this->assertSame((int) $this->account->getKey(), (int) $row->account_id);
            $this->assertSame('catalog', $row->subject_type);
        }
    }

    private function subscribe(Catalog $catalog): CatalogSubscription
    {
        return app(SubscribeToCatalogAction::class)->handle($this->account, $catalog);
    }
}
