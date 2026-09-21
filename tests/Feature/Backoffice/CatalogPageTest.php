<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El CRUD de catálogos por HTTP (`REQ-BOF-024`).
 *
 * Lo que la prueba de las acciones no ve: las siete rutas, sus dos
 * declaraciones —de qué superficie son y si sobreviven a una suspensión—, y
 * **qué ve el negocio**: el badge de los cuatro estados, la URL completa y la
 * frase que distingue `Listed` de `Unlisted`.
 */
final class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withoutOrganization();
    }

    // ------------------------------------------------------------------
    // El listado (RB-5)
    // ------------------------------------------------------------------

    /**
     * RB-5 · el badge de **los cuatro estados**, derivado en cada lectura.
     *
     * El expirado es el que importa: ni su fila ni ninguna columna dicen
     * «caducado», y la pantalla lo dice igual porque se deriva contra el reloj
     * (RB-6).
     */
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

    /**
     * RB-5 · y la **URL completa**, no el slug suelto — desde el borrador, que
     * es cuando todavía se puede cambiar y cuando hay que verla por si se
     * desambiguó con sufijo (SP-11).
     */
    public function test_the_listing_carries_the_whole_share_url(): void
    {
        $this->inTenant(fn (): Catalog => Catalog::factory()->create(['slug' => 'ofertas-de-septiembre']));

        $this->actingAs($this->owner())
            ->get('/backoffice/catalogos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('catalogs.0.shareUrl', config('app.url').'/c/ofertas-de-septiembre')
            );
    }

    // ------------------------------------------------------------------
    // El CRUD (RB-1)
    // ------------------------------------------------------------------

    public function test_it_creates_a_catalog_and_lands_on_its_form(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/catalogos', ['name' => 'Ofertas de septiembre'])
            ->assertSessionHasNoErrors();

        $catalog = $this->inTenant(fn (): Catalog => Catalog::query()->sole());

        $this->assertSame('ofertas-de-septiembre', $catalog->slug);
        $this->assertSame(CatalogVisibility::Draft, $catalog->visibility());
    }

    /**
     * RB-1 · la portada viaja por `PUT` con `_method`, que es lo que hace
     * posible mandar un archivo: un `PUT` multipart no lo interpretan ni PHP
     * ni Laravel. Dónde acaba el archivo lo prueba `UpsertCatalogActionTest`;
     * lo de aquí es que la ruta lo reciba.
     */
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

    /**
     * RB-4 · la pantalla explica el cerrojo del enlace **antes** de publicar:
     * mientras es borrador viaja abierto, y en cuanto se publica viaja
     * bloqueado.
     */
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

    /**
     * SP-5 · y el intento de cambiarlo tras publicar es `422` **sobre el
     * campo del enlace**, con el motivo.
     */
    public function test_editing_the_slug_of_a_published_catalog_is_rejected(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->published()->create(['slug' => 'ya-circula']));

        $this->actingAs($this->owner())
            ->from("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->put("/backoffice/catalogos/{$catalog->getKey()}", ['name' => $catalog->name, 'slug' => 'otro'])
            ->assertSessionHasErrors('slug');

        $this->assertSame('ya-circula', $catalog->refresh()->slug);
    }

    /**
     * RB-4 de `REQ-BOF-024` · lo que falta para publicar se dice **antes**, y
     * sale de la misma función que lo rechazaría después.
     */
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

    // ------------------------------------------------------------------
    // Publicar y despublicar
    // ------------------------------------------------------------------

    /**
     * RB-6 · publicar fuera de la vitrina lo dice **con esas palabras**: es la
     * confusión que hace que alguien comparta lo que creía privado.
     */
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

    /**
     * SP-1 y SP-2 · el rechazo aterriza **por campo** y además con el resumen
     * bajo `status`, que es lo que se lee desde el listado.
     */
    public function test_the_publishing_requirements_land_on_their_fields(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->actingAs($this->owner())
            ->from("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->post($this->publishUrl($catalog), ['is_listed' => '1'])
            ->assertSessionHasErrors(['status', 'products', 'payment_accounts']);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    /**
     * RB-7 · despublicar devuelve a borrador y lo dice, sin tocar nada más.
     */
    public function test_unpublishing_returns_the_catalog_to_draft(): void
    {
        $catalog = $this->publishable();

        $owner = $this->owner();

        $this->actingAs($owner)->post($this->publishUrl($catalog), ['is_listed' => '1']);
        $this->actingAs($owner)->delete($this->publishUrl($catalog))->assertRedirect('/backoffice/catalogos');

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    // ------------------------------------------------------------------
    // Las dos declaraciones de cada ruta
    // ------------------------------------------------------------------

    /**
     * De los dos roles: armar y publicar una campaña es operación diaria.
     */
    public function test_a_member_manages_catalogs(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)
            ->post('/backoffice/catalogos', ['name' => 'Campaña del empleado'])
            ->assertSessionHasNoErrors();
    }

    /**
     * **Ninguna sobrevive a la suspensión**, como el inventario entero: lo que
     * `REQ-ORG-008` RB-3 conserva es cobrar y aprobar comprobantes, y mover el
     * escaparate no es ninguna de las dos (RB-11).
     */
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

    /**
     * SP-10 · el catálogo de otra organización da `404` y no `403`: no se
     * confirma que exista.
     */
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

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /**
     * Un catálogo que reúne los requisitos comprobables de RB-4.
     */
    private function publishable(): Catalog
    {
        return $this->inTenant(function (): Catalog {
            PaymentAccount::factory()->create();

            $catalog = Catalog::factory()->create();
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

            return $catalog;
        });
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
