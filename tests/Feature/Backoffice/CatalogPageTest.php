<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Actions\Catalog\CatalogData;
use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Actions\Catalog\UpsertCatalogAction;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($this->organization);

        $this->withoutOrganization();
    }

    public function test_the_listing_shows_the_four_derived_states(): void
    {
        $this->inTenant(function (): void {
            Catalog::factory()->create(['name' => 'Borrador']);
            Catalog::factory()->published()->create(['name' => 'En vitrina']);
            Catalog::factory()->published()->unlisted()->create(['name' => 'Por enlace']);
            Catalog::factory()->expired()->create(['name' => 'Terminada']);
        });

        $states = [];

        $this->actingAs($this->owner())
            ->get('/backoffice/catalogos')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$states): void {
                /** @var list<array<string, mixed>> $catalogs */
                $catalogs = $page->toArray()['props']['catalogs'];

                foreach ($catalogs as $catalog) {
                    $states[$catalog['name']] = $catalog['visibility'];
                }
            });

        $this->assertEqualsCanonicalizing([
            'Borrador' => CatalogVisibility::Draft->value,
            'En vitrina' => CatalogVisibility::Listed->value,
            'Por enlace' => CatalogVisibility::Unlisted->value,
            'Terminada' => CatalogVisibility::Expired->value,
        ], $states);
    }

    public function test_the_listing_carries_the_whole_share_url(): void
    {
        $this->inTenant(fn (): Catalog => Catalog::factory()->create(['slug' => 'ofertas-de-septiembre']));

        $this->actingAs($this->owner())
            ->get('/backoffice/catalogos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('catalogs.0.shareUrl', config('app.url').'/c/ofertas-de-septiembre')
            );
    }

    public function test_the_listing_counts_the_subscribers(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->published()->create());
        CatalogSubscription::factory()->count(2)->create(['catalog_id' => $catalog->getKey()]);

        $this->actingAs($this->owner())
            ->get('/backoffice/catalogos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('catalogs.0.subscribersCount', 2)
                ->where('catalogs.0.thumbs', [])
                ->where('catalogs.0.coverUrl', null));
    }

    public function test_it_creates_a_catalog_and_lands_on_its_form(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/catalogos', ['name' => 'Ofertas de septiembre'])
            ->assertSessionHasNoErrors();

        $catalog = $this->inTenant(fn (): Catalog => Catalog::query()->sole());

        $this->assertSame('ofertas-de-septiembre', $catalog->slug);
        $this->assertSame(CatalogVisibility::Draft, $catalog->visibility());
    }

    public function test_the_cover_travels_with_the_form(): void
    {
        Storage::fake('public');

        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/catalogos/{$catalog->getKey()}", [
                '_method' => 'put',
                'name' => $catalog->name,
                'cover' => UploadedFile::fake()->image('septiembre.jpg'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($catalog->refresh()->cover_path);
    }

    public function test_the_form_says_whether_the_link_is_still_editable(): void
    {
        [$draft, $published] = $this->inTenant(fn (): array => [
            Catalog::factory()->create(),
            Catalog::factory()->published()->create(),
        ]);

        $owner = $this->owner();

        $this->actingAs($owner)
            ->get("/backoffice/catalogos/{$draft->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('catalog.slugIsLocked', false));

        $this->actingAs($owner)
            ->get("/backoffice/catalogos/{$published->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('catalog.slugIsLocked', true));
    }

    public function test_editing_the_slug_of_a_published_catalog_is_rejected(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->published()->create(['slug' => 'ya-circula']));

        $this->actingAs($this->owner())
            ->from("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->put("/backoffice/catalogos/{$catalog->getKey()}", ['name' => $catalog->name, 'slug' => 'otro'])
            ->assertSessionHasErrors('slug');

        $this->assertSame('ya-circula', $catalog->refresh()->slug);
    }

    public function test_the_form_explains_what_is_missing_before_publishing(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->actingAs($this->owner())
            ->get("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('blockers.products')
                ->has('blockers.payment_accounts')
            );
    }

    public function test_publishing_outside_the_storefront_says_it_is_still_reachable(): void
    {
        $catalog = $this->publishable();

        $response = $this->actingAs($this->owner())
            ->post($this->publishUrl($catalog), ['expires_at' => '']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas(
            'status',
            'Catálogo publicado fuera de tu vitrina: cualquiera con el enlace puede verlo, aunque no aparezca en tu '
            .'vitrina.',
        );

        $this->assertSame(CatalogVisibility::Unlisted, $catalog->refresh()->visibility());
    }

    public function test_the_publishing_requirements_land_on_their_fields(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->actingAs($this->owner())
            ->from("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->post($this->publishUrl($catalog), ['is_listed' => '1'])
            ->assertSessionHasErrors(['status', 'products', 'payment_accounts']);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    public function test_unpublishing_returns_the_catalog_to_draft(): void
    {
        $catalog = $this->publishable();

        $owner = $this->owner();

        $this->actingAs($owner)->post($this->publishUrl($catalog), ['is_listed' => '1']);
        $this->actingAs($owner)->delete($this->publishUrl($catalog))->assertRedirect('/backoffice/catalogos');

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    public function test_a_member_manages_catalogs(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)
            ->post('/backoffice/catalogos', ['name' => 'Campaña del empleado'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_suspended_organization_cannot_touch_its_catalogs(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $owner = $this->owner();

        $this->actingAs($owner)->get('/backoffice/catalogos')->assertForbidden();
        $this->actingAs($owner)->post('/backoffice/catalogos', ['name' => 'Otra'])->assertForbidden();
        $this->actingAs($owner)->post($this->publishUrl($catalog), ['is_listed' => '1'])->assertForbidden();
        $this->actingAs($owner)->delete($this->publishUrl($catalog))->assertForbidden();
    }

    public function test_a_catalog_of_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Catalog => Catalog::factory()->published()->create(),
        );

        $owner = $this->owner();

        $this->actingAs($owner)->get("/backoffice/catalogos/{$foreign->getKey()}/editar")->assertNotFound();
        $this->actingAs($owner)->post($this->publishUrl($foreign), ['is_listed' => '1'])->assertNotFound();
        $this->actingAs($owner)->delete($this->publishUrl($foreign))->assertNotFound();
    }

    private function publishable(): Catalog
    {
        return $this->inTenant(function (): Catalog {
            PaymentAccount::factory()->create();

            $catalog = Catalog::factory()->create();
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

            return $catalog;
        });
    }

    public function test_the_listing_and_the_form_name_the_last_editor(): void
    {
        $owner = $this->owner();
        $composer = $this->inTenant(fn (): User => User::factory()->owner()->create(['name' => 'Quien compuso']));

        $catalog = $this->inTenant(function () use ($owner, $composer): Catalog {
            $catalog = app(UpsertCatalogAction::class)->handle(
                new CatalogData(name: 'Ofertas', slug: null, description: null, cover: null),
                $owner,
            );

            app(SyncCatalogProductsAction::class)->handle($catalog, [(int) Product::factory()->create()->getKey()], $composer);

            return $catalog;
        });

        $this->withoutOrganization();

        $this->actingAs($owner)
            ->get('/backoffice/catalogos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('catalogs.0.updatedBy', 'Quien compuso')
                ->where('catalogs.0.updatedAt', fn (mixed $at): bool => is_string($at) && $at !== ''));

        $this->withoutOrganization();

        $this->actingAs($owner)
            ->get("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('catalog.updatedBy', 'Quien compuso'));
    }

    private function publishUrl(Catalog $catalog): string
    {
        return "/backoffice/catalogos/{$catalog->getKey()}/publicar";
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
