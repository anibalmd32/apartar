<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\CatalogData;
use App\Actions\Catalog\UpsertCatalogAction;
use App\Domain\Tenancy\SlugLockedException;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\User;
use App\Support\UniqueCatalogSlug;
use Closure;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UniqueCatalogSlugTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Organization $neighbour;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_derives_the_slug_from_the_name(): void
    {
        $this->assertSame('ofertas-de-septiembre', $this->upsert(null, 'Ofertas de Septiembre')->slug);
    }

    public function test_a_slug_taken_by_another_organization_gets_a_suffix(): void
    {
        $first = $this->asNeighbour(fn (): Catalog => $this->upsert(null, 'Ofertas de septiembre'));

        $second = $this->upsert(null, 'Ofertas de septiembre');
        $third = $this->upsert(null, 'Ofertas de septiembre');

        $this->assertSame('ofertas-de-septiembre', $first->slug);
        $this->assertSame('ofertas-de-septiembre-2', $second->slug);
        $this->assertSame('ofertas-de-septiembre-3', $third->slug);
    }

    public function test_it_never_reads_the_catalogs_of_another_organization(): void
    {
        foreach (['app/Support/UniqueCatalogSlug.php', 'app/Actions/Catalog/UpsertCatalogAction.php'] as $file) {
            $this->assertStringNotContainsString(
                'withoutTenantScope',
                php_strip_whitespace(base_path($file)),
                $file.' lee fuera del tenant para resolver el slug. Quien decide tiene que ser el índice.',
            );
        }
    }

    public function test_the_slug_is_editable_until_the_first_publication(): void
    {
        $catalog = $this->upsert(null, 'Campaña');

        $this->upsert($catalog, 'Campaña', 'liquidacion');

        $this->assertSame('liquidacion', $catalog->refresh()->slug);

        $catalog->forceFill(['published_at' => now()])->save();

        try {
            $this->upsert($catalog, 'Campaña', 'otro-enlace');
            $this->fail('Se esperaba que el enlace estuviera bloqueado tras publicar.');
        } catch (SlugLockedException $exception) {
            $this->assertStringContainsString('ya no se puede cambiar', $exception->getMessage());
            $this->assertStringContainsString('/c/liquidacion', $exception->getMessage());
        }

        $this->assertSame('liquidacion', $catalog->refresh()->slug);
    }

    public function test_a_published_catalog_can_still_be_renamed(): void
    {
        $catalog = $this->upsert(null, 'Campaña');
        $catalog->forceFill(['published_at' => now()])->save();

        $this->upsert($catalog, 'Campaña de verano', $catalog->slug);

        $this->assertSame('Campaña de verano', $catalog->refresh()->name);
        $this->assertSame('campana', $catalog->slug);
    }

    public function test_an_unpublished_catalog_keeps_its_slug_for_itself(): void
    {
        $retired = $this->upsert(null, 'Black Friday');
        $retired->forceFill(['published_at' => now()->subMonth()])->save();
        $retired->forceFill(['published_at' => null])->save();

        $next = $this->upsert(null, 'Black Friday');

        $this->assertSame('black-friday', $retired->refresh()->slug);
        $this->assertSame('black-friday-2', $next->slug);
    }

    public function test_a_name_without_letters_still_gets_a_usable_link(): void
    {
        $this->assertSame('catalogo', $this->upsert(null, '🔥🔥🔥')->slug);
        $this->assertSame('catalogo-2', $this->upsert(null, '🎉')->slug);
    }

    public function test_the_suffix_never_overflows_the_column(): void
    {
        $name = str_repeat('campana-de-temporada-', 7);

        $first = $this->upsert(null, $name);
        $second = $this->upsert(null, $name);

        $this->assertLessThanOrEqual(UniqueCatalogSlug::MAX_LENGTH, strlen($first->slug));
        $this->assertLessThanOrEqual(UniqueCatalogSlug::MAX_LENGTH, strlen($second->slug));
        $this->assertNotSame($first->slug, $second->slug);
    }

    private function upsert(?Catalog $catalog, string $name, ?string $slug = null): Catalog
    {
        return app(UpsertCatalogAction::class)->handle(
            new CatalogData(name: $name, slug: $slug, description: null, cover: null),
            $this->actor,
            $catalog,
        );
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function asNeighbour(Closure $callback): mixed
    {
        $actor = $this->actor;

        $result = $this->withinOrganization($this->neighbour, function () use ($callback): mixed {
            $this->actor = User::factory()->owner()->create();

            return $callback();
        });

        $this->actor = $actor;

        return $result;
    }
}
