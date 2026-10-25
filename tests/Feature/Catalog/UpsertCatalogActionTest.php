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

    public function test_an_empty_description_is_stored_as_null(): void
    {
        $catalog = $this->upsert(null, new CatalogData(name: 'Campaña', slug: null, description: null, cover: null));

        $this->assertNull($catalog->description);
    }

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
