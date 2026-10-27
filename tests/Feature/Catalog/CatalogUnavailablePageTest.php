<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Catalog;
use App\Models\Organization;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogUnavailablePageTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_an_unknown_catalog_link_shows_the_unavailable_page_with_a_404(): void
    {
        $this->get('/c/no-existe-este-catalogo')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('catalog/CatalogUnavailable'));
    }

    public function test_a_draft_answers_exactly_like_a_link_that_never_existed(): void
    {
        $organization = Organization::factory()->create(['name' => 'Ferretería Secreta']);
        $this->subscribeWithoutLimits($organization);
        $draft = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->create());
        $this->withoutOrganization();

        $response = $this->get('/c/'.$draft->slug)->assertNotFound();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catalog/CatalogUnavailable')
            ->missing('catalogName')
            ->missing('businessName'));
        $this->assertStringNotContainsString('Ferretería Secreta', (string) $response->getContent());
        $this->assertStringNotContainsString($draft->name, (string) $response->getContent());
    }

    public function test_a_withdrawn_catalog_says_it_is_no_longer_available_and_names_its_business(): void
    {
        $organization = Organization::factory()->create(['name' => 'Ferretería Oriente', 'storefront_enabled' => true]);
        $this->subscribeWithoutLimits($organization);
        $catalog = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->create([
            'name' => 'Ofertas de octubre',
            'withdrawn_at' => now(),
        ]));
        $this->withoutOrganization();

        $this->get('/c/'.$catalog->slug)
            ->assertStatus(410)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('catalog/CatalogExpired')
                ->where('withdrawn', true)
                ->where('catalogName', 'Ofertas de octubre')
                ->where('businessName', 'Ferretería Oriente')
                ->whereNot('storefrontUrl', null));
    }

    public function test_a_withdrawn_catalog_also_closes_its_product_links(): void
    {
        $organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($organization);
        $catalog = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->create(['withdrawn_at' => now()]));
        $this->withoutOrganization();

        $this->get('/c/'.$catalog->slug.'/p/cualquiera')->assertStatus(410);
    }

    public function test_other_404s_keep_the_default_response(): void
    {
        $response = $this->get('/esto/no/existe')->assertNotFound();

        $this->assertStringNotContainsString('catalog/CatalogUnavailable', (string) $response->getContent());
    }

    public function test_a_json_request_keeps_the_plain_404(): void
    {
        $this->getJson('/c/no-existe-este-catalogo')
            ->assertNotFound()
            ->assertHeaderMissing('X-Inertia');
    }
}
