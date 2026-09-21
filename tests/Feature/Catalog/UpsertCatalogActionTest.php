<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\CatalogData;
use App\Actions\Catalog\UpsertCatalogAction;
use App\Domain\Catalog\CatalogVisibility;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El alta y la edición de un catálogo (`REQ-BOF-024` RB-1).
 *
 * El enlace tiene su propio archivo —`UniqueCatalogSlugTest`—, porque es la
 * única regla de unicidad del proyecto que cruza la frontera del tenant. Aquí
 * está lo demás del CRUD, y sobre todo **dónde nace un catálogo**: en
 * borrador, siempre, con las tres columnas de publicación vacías. Un alta que
 * entregara el catálogo publicado se saltaría los seis requisitos de RB-4 de
 * una vez.
 */
final class UpsertCatalogActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(UpsertCatalogAction::COVER_DISK);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    /**
     * Un catálogo nace en borrador y en la vitrina: publicarlo es un paso
     * aparte, y `is_listed` solo decide dónde se verá cuando lo esté.
     */
    public function test_a_catalog_is_born_as_a_draft(): void
    {
        $catalog = $this->upsert(null, new CatalogData(
            name: 'Ofertas de septiembre',
            slug: null,
            description: 'Lo que queda de temporada.',
            cover: null,
        ));

        $this->assertSame(CatalogVisibility::Draft, $catalog->visibility());
        $this->assertNull($catalog->published_at);
        $this->assertNull($catalog->expires_at);
        $this->assertTrue($catalog->is_listed);
        $this->assertSame('Lo que queda de temporada.', $catalog->description);
    }

    /**
     * Y el guardado **no toca las columnas de publicación** de uno que ya lo
     * está: son de `PublishCatalogAction`, y un segundo camino hacia ellas es
     * el que se olvidaría de los requisitos.
     */
    public function test_editing_never_touches_the_publication_columns(): void
    {
        $catalog = Catalog::factory()->published()->unlisted()->create();
        $catalog->forceFill(['expires_at' => now()->addWeek()])->save();

        $before = [$catalog->published_at, $catalog->expires_at, $catalog->is_listed];

        $this->upsert($catalog, new CatalogData(
            name: 'Ofertas de octubre',
            slug: null,
            description: 'Prórroga.',
            cover: null,
        ));

        $catalog->refresh();

        $this->assertSame('Ofertas de octubre', $catalog->name);
        $this->assertEquals($before, [$catalog->published_at, $catalog->expires_at, $catalog->is_listed]);
    }

    /**
     * La descripción vacía se guarda como nula y no como cadena: un texto
     * vacío pintaría un párrafo en blanco en la portada del catálogo.
     */
    public function test_an_empty_description_is_stored_as_null(): void
    {
        $catalog = $this->upsert(null, new CatalogData(name: 'Campaña', slug: null, description: null, cover: null));

        $this->assertNull($catalog->description);
    }

    /**
     * RB-1 · la portada se guarda en el disco público, bajo carpeta por
     * organización, y **sustituirla borra la anterior**: no la referencia
     * nadie más, y dejarla haría crecer el almacenamiento —que es una métrica
     * de plan— con archivos que ya nadie puede ver.
     */
    public function test_the_cover_is_stored_per_organization_and_replaces_the_previous_one(): void
    {
        $catalog = $this->upsert(null, new CatalogData(
            name: 'Campaña',
            slug: null,
            description: null,
            cover: UploadedFile::fake()->image('septiembre.jpg'),
        ));

        $first = $catalog->cover_path;

        $this->assertNotNull($first);
        $this->assertStringStartsWith('catalogos/'.$this->organization->getKey().'/', $first);
        Storage::disk(UpsertCatalogAction::COVER_DISK)->assertExists($first);

        $this->upsert($catalog, new CatalogData(
            name: 'Campaña',
            slug: null,
            description: null,
            cover: UploadedFile::fake()->image('octubre.jpg'),
        ));

        $this->assertNotSame($first, $catalog->refresh()->cover_path);
        Storage::disk(UpsertCatalogAction::COVER_DISK)->assertMissing($first);
    }

    /**
     * Y guardar **sin** portada nueva conserva la que hubiera: el formulario
     * que solo cambia el nombre no puede dejar el catálogo sin imagen.
     */
    public function test_saving_without_a_new_cover_keeps_the_current_one(): void
    {
        $catalog = $this->upsert(null, new CatalogData(
            name: 'Campaña',
            slug: null,
            description: null,
            cover: UploadedFile::fake()->image('septiembre.jpg'),
        ));

        $cover = $catalog->cover_path;

        $this->upsert($catalog, new CatalogData(name: 'Campaña nueva', slug: null, description: null, cover: null));

        $this->assertSame($cover, $catalog->refresh()->cover_path);
    }

    /**
     * La policy es la del inventario, y por tanto **de los dos roles**: armar
     * una campaña es operación diaria. Quien no pertenece al negocio no pasa.
     */
    public function test_someone_outside_the_organization_cannot_write(): void
    {
        $stranger = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->expectException(AuthorizationException::class);

        $this->upsert(null, new CatalogData(name: 'Campaña', slug: null, description: null, cover: null), $stranger);
    }

    private function upsert(?Catalog $catalog, CatalogData $data, ?User $actor = null): Catalog
    {
        return app(UpsertCatalogAction::class)->handle($data, $actor ?? $this->actor, $catalog);
    }
}
