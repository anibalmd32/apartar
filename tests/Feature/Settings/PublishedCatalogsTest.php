<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Catalog\PublishedCatalogs;
use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublishedCatalogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_answers_false_when_the_organization_has_no_catalogs(): void
    {
        $organization = Organization::factory()->create();

        $this->assertFalse($this->existFor($organization));
    }

    public function test_a_draft_catalog_does_not_count(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->create());

        $this->assertFalse($this->existFor($organization));
    }

    public function test_a_published_catalog_counts(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create());

        $this->assertTrue($this->existFor($organization));
    }

    public function test_a_published_catalog_of_another_organization_does_not_count(): void
    {
        $organization = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->published()->create());

        $this->assertFalse($this->existFor($organization));
        $this->assertTrue($this->existFor($neighbour));
    }

    public function test_an_expired_catalog_still_counts(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create([
            'expires_at' => now()->subDay(),
        ]));

        $this->assertTrue($this->existFor($organization));
    }

    public function test_it_can_still_be_substituted(): void
    {
        $withCatalogs = new class extends PublishedCatalogs
        {
            public function exist(): bool
            {
                return true;
            }
        };

        $this->assertTrue($withCatalogs->exist());
    }

    private function existFor(Organization $organization): bool
    {
        return $this->withinOrganization(
            $organization,
            fn (): bool => (new PublishedCatalogs)->exist(),
        );
    }
}
