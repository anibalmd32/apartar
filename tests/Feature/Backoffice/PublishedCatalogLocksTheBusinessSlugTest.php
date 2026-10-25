<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Catalog\PublishedCatalogs;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PublishedCatalogLocksTheBusinessSlugTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);
        $this->subscribeWithoutLimits($this->organization);

        $this->withoutOrganization();
    }

    public function test_publishing_a_catalog_closes_the_business_link_for_good(): void
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            PaymentAccount::factory()->create();

            $catalog = Catalog::factory()->create(['name' => 'Ofertas de septiembre']);
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

            return $catalog;
        });

        $this->assertFalse($this->withinOrganization($this->organization, fn (): bool => app(PublishedCatalogs::class)->exist()));

        $this->actingAs($owner)
            ->put('/backoffice/ajustes/identidad', $this->identity('ferreteria-tornillo'))
            ->assertSessionHasNoErrors();

        $this->assertSame('ferreteria-tornillo', $this->organization->refresh()->slug);

        $this->actingAs($owner)
            ->post("/backoffice/catalogos/{$catalog->getKey()}/publicar", ['is_listed' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($catalog->refresh()->published_at);
        $this->assertTrue($this->withinOrganization($this->organization, fn (): bool => app(PublishedCatalogs::class)->exist()));

        $this->actingAs($owner)
            ->from('/backoffice/ajustes')
            ->put('/backoffice/ajustes/identidad', $this->identity('otro-nombre'))
            ->assertSessionHasErrors('slug');

        $this->assertSame('ferreteria-tornillo', $this->organization->refresh()->slug);

        $this->actingAs($owner)
            ->put('/backoffice/ajustes/identidad', $this->identity('ferreteria-tornillo', 'Ferretería El Tornillo, C.A.'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Ferretería El Tornillo, C.A.', $this->organization->refresh()->name);
    }

    /**
     * @return array<string, string>
     */
    private function identity(string $slug, string $name = 'Ferretería El Tornillo'): array
    {
        return [
            '_method' => 'put',
            'name' => $name,
            'slug' => $slug,
            'document' => 'J-12345678-9',
            'phone' => '0412-1234567',
            'timezone' => 'America/Caracas',
            'brand_color' => '#1d4ed8',
            'storefront_enabled' => '1',
        ];
    }
}
