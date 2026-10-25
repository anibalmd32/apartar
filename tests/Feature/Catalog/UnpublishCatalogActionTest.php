<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UnpublishCatalogActionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_returns_to_draft_keeping_the_slug_and_the_products(): void
    {
        $catalog = $this->published();
        $slug = $catalog->slug;

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
        $this->assertNull($catalog->published_at);
        $this->assertSame($slug, $catalog->slug);
        $this->assertSame(1, $catalog->products()->count());
    }

    public function test_it_leaves_the_storefront_flag_and_the_expiration_alone(): void
    {
        $expiresAt = CarbonImmutable::now()->addWeek();

        $catalog = $this->published(new PublishCatalogData(isListed: false, expiresAt: $expiresAt));

        $this->unpublish()->handle($catalog, $this->actor);

        $catalog->refresh();

        $this->assertFalse($catalog->is_listed);
        $this->assertNotNull($catalog->expires_at);
        $this->assertSame($expiresAt->toDateString(), $catalog->expires_at->toDateString());
    }

    public function test_an_expired_catalog_can_still_be_retired(): void
    {
        $catalog = $this->published();
        $catalog->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    public function test_it_is_audited_with_the_derived_state_on_both_sides(): void
    {
        $catalog = $this->published();

        $this->unpublish()->handle($catalog, $this->actor);

        $entry = AuditEntry::query()->where('event', AuditEvent::CatalogUnpublished->value)->sole();

        $this->assertStringContainsString($catalog->name, $entry->description);

        /** @var array<string, array{before?: mixed, after?: mixed}> $changes */
        $changes = $entry->properties['changed'] ?? [];

        $this->assertSame(CatalogVisibility::Listed->value, $changes['visibility']['before'] ?? null);
        $this->assertSame(CatalogVisibility::Draft->value, $changes['visibility']['after'] ?? null);
    }

    public function test_retiring_a_draft_writes_nothing(): void
    {
        $catalog = Catalog::factory()->create();

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(0, AuditEntry::query()->where('event', AuditEvent::CatalogUnpublished->value)->count());
    }

    public function test_the_action_itself_does_not_block_a_suspended_organization(): void
    {
        $catalog = $this->published();

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->actingAsOrganization($this->organization->refresh());

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    private function published(?PublishCatalogData $data = null): Catalog
    {
        PaymentAccount::factory()->create();

        $catalog = Catalog::factory()->create();
        $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

        app(PublishCatalogAction::class)->handle(
            $catalog,
            $data ?? new PublishCatalogData(isListed: true, expiresAt: null),
            $this->actor,
        );

        return $catalog->refresh();
    }

    private function unpublish(): UnpublishCatalogAction
    {
        return app(UnpublishCatalogAction::class);
    }
}
