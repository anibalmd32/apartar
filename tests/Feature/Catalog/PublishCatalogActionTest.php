<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogNotPublishableException;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PublishCatalogActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_requires_at_least_one_published_product(): void
    {
        $catalog = $this->ready();
        $catalog->products()->detach();
        $catalog->products()->attach(Product::factory()->create(), ['position' => 1]);

        $missing = $this->reject($catalog);

        $this->assertArrayHasKey('products', $missing);
        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    public function test_it_requires_an_active_payment_account(): void
    {
        $catalog = $this->ready();
        PaymentAccount::query()->update(['is_active' => false]);

        $missing = $this->reject($catalog);

        $this->assertArrayHasKey('payment_accounts', $missing);
        $this->assertStringContainsString('cuenta de cobro', $missing['payment_accounts']);
    }

    public function test_it_rejects_an_expiration_that_already_passed(): void
    {
        $catalog = $this->ready();

        $missing = $this->reject($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->subDay(),
        ));

        $this->assertArrayHasKey('expires_at', $missing);
        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    public function test_republishing_an_expired_catalog_without_moving_the_date_is_rejected(): void
    {
        $catalog = $this->ready();
        $expired = CarbonImmutable::now()->subDay();

        $catalog->forceFill(['published_at' => now()->subMonth(), 'expires_at' => $expired])->save();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());

        $missing = $this->reject($catalog, new PublishCatalogData(isListed: true, expiresAt: $expired));

        $this->assertArrayHasKey('expires_at', $missing);
        $this->assertSame(CatalogVisibility::Expired, $catalog->refresh()->visibility());

        $this->publish()->handle($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->addWeek(),
        ), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $catalog->refresh()->visibility());
    }

    public function test_publishing_with_the_quota_exhausted_names_the_plan_that_unlocks_it(): void
    {
        $this->capCatalogsAt(1);
        Plan::factory()->create(['code' => 'pro-sp3', 'name' => 'Pro', 'position' => 2, 'max_catalogs' => 10]);

        $first = $this->ready();
        $this->ready();
        $second = $this->ready();

        $this->publish()->handle($first, $this->data(), $this->actor);

        try {
            $this->publish()->handle($second, $this->data(), $this->actor);
            $this->fail('Se publicó un segundo catálogo con el cupo de uno.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Catalogs, $exception->metric);
            $this->assertSame(1, $exception->used);
            $this->assertSame(1, $exception->limit);
            $this->assertSame('pro-sp3', $exception->minimumPlan?->code);
            $this->assertStringContainsString('despublicar otro catálogo', $exception->toArray()['suggestion']);
        }

        $this->assertSame(CatalogVisibility::Draft, $second->refresh()->visibility());

        app(UnpublishCatalogAction::class)->handle($first->refresh(), $this->actor);
        $this->publish()->handle($second, $this->data(), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $second->refresh()->visibility());
        $this->assertSame(1, $this->catalogsCounter());
    }

    public function test_the_publish_panel_shows_the_quota_before_pressing(): void
    {
        $this->capCatalogsAt(1);
        $published = $this->ready();
        $draft = $this->ready();
        $this->publish()->handle($published, $this->data(), $this->actor);

        $this->assertArrayHasKey('plan', $this->publish()->blockersFor($draft->refresh()));
        $this->assertStringContainsString('despublicar otro catálogo', $this->publish()->blockersFor($draft)['plan']);
        $this->assertArrayNotHasKey('plan', $this->publish()->blockersFor($published->refresh()));
    }

    public function test_editing_a_published_catalog_does_not_ask_for_a_slot(): void
    {
        $this->capCatalogsAt(1);
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->publish()->handle($catalog->refresh(), new PublishCatalogData(isListed: false, expiresAt: CarbonImmutable::now()->addWeek()), $this->actor);

        $this->assertSame(CatalogVisibility::Unlisted, $catalog->refresh()->visibility());
    }

    public function test_a_suspended_subscription_cannot_publish(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended->value]);

        $this->expectException(CreationSuspendedException::class);

        $this->publish()->handle($this->ready(), $this->data(), $this->actor);
    }

    public function test_publishing_and_unpublishing_move_the_catalogs_counter(): void
    {
        UsageCounter::factory()->forMetric(UsageMetric::Catalogs)->create();
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());

        $this->publish()->handle($catalog, new PublishCatalogData(isListed: false, expiresAt: CarbonImmutable::now()->addWeek()), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);
        $this->assertSame(0, $this->catalogsCounter());

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);
        $this->assertSame(0, $this->catalogsCounter());

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());
    }

    public function test_an_expired_catalog_moves_the_counter_like_a_draft(): void
    {
        UsageCounter::factory()->forMetric(UsageMetric::Catalogs)->create();
        $catalog = $this->ready();
        $catalog->forceFill(['published_at' => now()->subWeek(), 'expires_at' => now()->subDay()])->save();

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);
        $this->assertSame(0, $this->catalogsCounter());

        $catalog->forceFill(['published_at' => now()->subWeek(), 'expires_at' => now()->subDay()])->save();
        $this->publish()->handle($catalog, new PublishCatalogData(isListed: true, expiresAt: CarbonImmutable::now()->addWeek()), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());
    }

    public function test_it_requires_the_owner_email_to_be_verified(): void
    {
        $catalog = $this->ready();

        User::query()->update(['email_verified_at' => null]);

        $missing = $this->reject($catalog);

        $this->assertArrayHasKey('owner_email', $missing);
        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    public function test_a_suspended_organization_cannot_publish(): void
    {
        $catalog = $this->ready();

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->actingAsOrganization($this->organization->refresh());

        $this->expectException(CreationSuspendedException::class);

        try {
            $this->publish()->handle($catalog, $this->data(), $this->actor);
        } finally {
            $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
        }
    }

    public function test_every_unmet_requirement_travels_together(): void
    {
        $catalog = Catalog::factory()->create();

        User::query()->update(['email_verified_at' => null]);

        try {
            $this->publish()->handle($catalog, $this->data(), $this->actor);
            $this->fail('Se esperaba que la publicación se rechazara y no lo hizo.');
        } catch (CatalogNotPublishableException $exception) {
            $this->assertSame(['products', 'payment_accounts', 'owner_email'], array_keys($exception->missing));

            $this->assertStringContainsString('al menos un producto publicado dentro', $exception->getMessage());
            $this->assertStringContainsString('una cuenta de cobro activa', $exception->getMessage());
            $this->assertStringContainsString('verificar el correo del dueño', $exception->getMessage());
        }
    }

    public function test_it_publishes_inside_or_outside_the_storefront(): void
    {
        $listed = $this->ready();
        $unlisted = $this->ready();

        $this->publish()->handle($listed, $this->data(), $this->actor);
        $this->publish()->handle($unlisted, new PublishCatalogData(isListed: false, expiresAt: null), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $listed->refresh()->visibility());
        $this->assertSame(CatalogVisibility::Unlisted, $unlisted->refresh()->visibility());
        $this->assertTrue($unlisted->visibility()->isPubliclyAccessible());
    }

    public function test_the_expiration_derives_without_any_job(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->addDay(),
        ), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $catalog->refresh()->visibility());

        $this->travel(2)->days();

        $this->assertSame(CatalogVisibility::Expired, $catalog->refresh()->visibility());
    }

    public function test_republishing_after_retiring_starts_a_new_date(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);

        $first = $catalog->refresh()->published_at;

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);

        $this->travel(2)->days();

        $this->publish()->handle($catalog->refresh(), $this->data(), $this->actor);

        $this->assertNotNull($first);
        $this->assertNotNull($catalog->refresh()->published_at);
        $this->assertTrue($catalog->published_at->greaterThan($first));
    }

    public function test_it_audits_publishing_the_expiration_and_unpublishing(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->addWeek(),
        ), $this->actor);

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);

        foreach ([AuditEvent::CatalogPublished, AuditEvent::CatalogExpirationScheduled, AuditEvent::CatalogUnpublished] as $event) {
            $this->assertTrue(
                AuditEntry::query()->where('event', $event->value)->exists(),
                sprintf('Falta la entrada de auditoría de %s.', $event->value),
            );
        }

        $entry = AuditEntry::query()->where('event', AuditEvent::CatalogPublished->value)->sole();

        $this->assertStringContainsString($catalog->name, $entry->description);
    }

    public function test_publishing_twice_without_changes_writes_nothing_new(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->publish()->handle($catalog->refresh(), $this->data(), $this->actor);

        $this->assertSame(1, AuditEntry::query()->where('event', AuditEvent::CatalogPublished->value)->count());
    }

    private function ready(): Catalog
    {
        if (! PaymentAccount::query()->exists()) {
            PaymentAccount::factory()->create();
        }

        $catalog = Catalog::factory()->create();
        $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

        return $catalog;
    }

    /**
     * @return array<string, string>
     */
    private function reject(Catalog $catalog, ?PublishCatalogData $data = null): array
    {
        try {
            $this->publish()->handle($catalog, $data ?? $this->data(), $this->actor);
        } catch (CatalogNotPublishableException $exception) {
            return $exception->missing;
        }

        $this->fail('Se esperaba que la publicación se rechazara y no lo hizo.');
    }

    private function capCatalogsAt(int $limit): void
    {
        Subscription::query()->firstOrFail()->plan->forceFill(['max_catalogs' => $limit])->save();
    }

    private function catalogsCounter(): int
    {
        return (int) UsageCounter::query()->where('metric', UsageMetric::Catalogs)->value('value');
    }

    private function data(): PublishCatalogData
    {
        return new PublishCatalogData(isListed: true, expiresAt: null);
    }

    private function publish(): PublishCatalogAction
    {
        return app(PublishCatalogAction::class);
    }
}
