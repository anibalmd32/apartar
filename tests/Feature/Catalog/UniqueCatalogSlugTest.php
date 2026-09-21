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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El enlace del catálogo, único en toda la plataforma (`REQ-CAT-022` RB-1,
 * RB-2, SP-6, SP-7).
 *
 * **El caso que justifica la clase entera es el segundo**: dos negocios
 * distintos que quieren el mismo enlace. Es la única colisión del proyecto que
 * cruza la frontera del tenant, y se resuelve sin leer una sola fila ajena
 * —`Catalog::withoutTenantScope()` está prohibido fuera de `Platform`—:
 * decide el índice `UNIQUE(slug)` y la vuelta siguiente desambigua.
 */
final class UniqueCatalogSlugTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * RB-1 · el enlace se sugiere del nombre.
     */
    public function test_it_derives_the_slug_from_the_name(): void
    {
        $this->assertSame('ofertas-de-septiembre', $this->upsert(null, 'Ofertas de Septiembre')->slug);
    }

    /**
     * RB-1 y `REQ-BOF-024` SP-11 · **el enlace que ya tiene otro negocio se
     * desambigua con sufijo, no se rechaza.**
     *
     * Rechazarlo obligaría a renombrar una campaña legítima por un detalle del
     * que nadie ha hablado nunca — y para saber que está tomado habría que
     * leer los catálogos del vecino.
     */
    public function test_a_slug_taken_by_another_organization_gets_a_suffix(): void
    {
        $first = $this->asNeighbour(fn (): Catalog => $this->upsert(null, 'Ofertas de septiembre'));

        $second = $this->upsert(null, 'Ofertas de septiembre');
        $third = $this->upsert(null, 'Ofertas de septiembre');

        $this->assertSame('ofertas-de-septiembre', $first->slug);
        $this->assertSame('ofertas-de-septiembre-2', $second->slug);
        $this->assertSame('ofertas-de-septiembre-3', $third->slug);
    }

    /**
     * Y el mecanismo **no mira al vecino para conseguirlo**: las dos clases
     * que resuelven la colisión no pueden contener la única llamada que
     * rompería el aislamiento.
     */
    public function test_it_never_reads_the_catalogs_of_another_organization(): void
    {
        foreach (['app/Support/UniqueCatalogSlug.php', 'app/Actions/Catalog/UpsertCatalogAction.php'] as $file) {
            // Sin comentarios: los dos archivos **nombran** la llamada para
            // explicar por qué no está, y un `grep` sobre el texto crudo
            // confundiría la explicación con la infracción.
            $this->assertStringNotContainsString(
                'withoutTenantScope',
                php_strip_whitespace(base_path($file)),
                $file.' lee fuera del tenant para resolver el slug. Quien decide tiene que ser el índice.',
            );
        }
    }

    /**
     * RB-2 y SP-6 · mientras es borrador el enlace se edita; tras publicar,
     * el intento es `SlugLockedException` y la fila no se mueve.
     */
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

    /**
     * Y el cerrojo **no bloquea el formulario entero**: renombrar un catálogo
     * publicado se sigue pudiendo, porque reenviar el mismo enlace no es
     * intentar cambiarlo.
     */
    public function test_a_published_catalog_can_still_be_renamed(): void
    {
        $catalog = $this->upsert(null, 'Campaña');
        $catalog->forceFill(['published_at' => now()])->save();

        $this->upsert($catalog, 'Campaña de verano', $catalog->slug);

        $this->assertSame('Campaña de verano', $catalog->refresh()->name);
        $this->assertSame('campana', $catalog->slug);
    }

    /**
     * SP-7 · despublicar **no libera el enlace**, así que el siguiente
     * catálogo con el mismo nombre se desambigua igual.
     */
    public function test_an_unpublished_catalog_keeps_its_slug_for_itself(): void
    {
        $retired = $this->upsert(null, 'Black Friday');
        $retired->forceFill(['published_at' => now()->subMonth()])->save();
        $retired->forceFill(['published_at' => null])->save();

        $next = $this->upsert(null, 'Black Friday');

        $this->assertSame('black-friday', $retired->refresh()->slug);
        $this->assertSame('black-friday-2', $next->slug);
    }

    /**
     * Un nombre que no deja letras —emoji, u otro alfabeto— no puede producir
     * un slug vacío: dos así chocarían entre ellos.
     */
    public function test_a_name_without_letters_still_gets_a_usable_link(): void
    {
        $this->assertSame('catalogo', $this->upsert(null, '🔥🔥🔥')->slug);
        $this->assertSame('catalogo-2', $this->upsert(null, '🎉')->slug);
    }

    /**
     * El sufijo no desborda la columna: el tope de `catalogs.slug` son 100
     * caracteres, y una vuelta que lo pasara fallaría por un motivo que no
     * tiene nada que ver con la colisión.
     */
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
     * Lo mismo, pero desde el negocio de al lado y con su propio `owner`.
     *
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
