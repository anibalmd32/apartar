<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\TenantScope;
use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogVisibilityDerivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    public function test_a_catalog_without_publication_date_is_a_draft(): void
    {
        $catalog = Catalog::factory()->create();

        $this->assertSame(CatalogVisibility::Draft, $catalog->visibility());
        $this->assertFalse($catalog->visibility()->isPubliclyAccessible());
    }

    public function test_a_published_and_listed_catalog_is_listed(): void
    {
        $catalog = Catalog::factory()->published()->create();

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());
        $this->assertTrue($catalog->visibility()->appearsInStorefront());
    }

    public function test_a_published_and_unlisted_catalog_is_unlisted_but_accessible(): void
    {
        $catalog = Catalog::factory()->published()->unlisted()->create();

        $this->assertSame(CatalogVisibility::Unlisted, $catalog->visibility());
        $this->assertTrue($catalog->visibility()->isPubliclyAccessible());
        $this->assertFalse($catalog->visibility()->appearsInStorefront());
    }

    public function test_a_catalog_past_its_expiry_is_expired(): void
    {
        $catalog = Catalog::factory()->expired()->create();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());
        $this->assertFalse($catalog->visibility()->isPubliclyAccessible());
    }

    public function test_a_never_published_catalog_with_a_past_expiry_is_still_a_draft(): void
    {
        $catalog = Catalog::factory()->expiresAt(now()->subDay())->create();

        $this->assertNull($catalog->published_at);
        $this->assertSame(CatalogVisibility::Draft, $catalog->visibility());
    }

    public function test_expiry_wins_over_is_listed(): void
    {
        foreach ([true, false] as $isListed) {
            $catalog = Catalog::factory()->expired()->create(['is_listed' => $isListed]);

            $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());
        }
    }

    public function test_a_future_expiry_does_not_expire_the_catalog(): void
    {
        $catalog = Catalog::factory()->published()->create(['expires_at' => now()->addMonth()]);

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());
    }

    public function test_a_future_publication_date_still_counts_as_published(): void
    {
        $catalog = Catalog::factory()->create(['published_at' => now()->addWeek()]);

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());
    }

    public function test_the_catalog_expires_on_the_clock_without_a_job(): void
    {
        $catalog = Catalog::factory()->published()->create(['expires_at' => now()->addHour()]);

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());

        $this->travel(2)->hours();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());
    }

    public function test_the_catalogs_table_has_no_visibility_column(): void
    {
        $this->assertFalse(Schema::hasColumn('catalogs', 'visibility'));
        $this->assertFalse(Schema::hasColumn('catalogs', 'status'));
        $this->assertTrue(Schema::hasColumn('catalogs', TenantScope::COLUMN));
    }
}
