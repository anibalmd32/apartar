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

/**
 * La derivación de `Catalog::visibility()` (`REQ-CAT-022` RB-6).
 *
 * **Los cuatro casos se solapan**, así que lo que se prueba aquí no es cada
 * estado por separado sino el **orden** en que se evalúan: un catálogo sin
 * publicar y con la caducidad ya pasada cumple dos condiciones a la vez, y si
 * gana la equivocada la vista pública devuelve `410` sobre algo que nunca
 * existió para nadie.
 *
 * Clase de PHPUnit y no sintaxis de Pest: necesita los helpers de instancia
 * del caso base para situarse en una organización.
 */
final class CatalogVisibilityDerivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    /**
     * Caso 1 · sin `published_at` es borrador. Es también lo que devuelve
     * despublicar, que vacía la columna (RB-7).
     */
    public function test_a_catalog_without_publication_date_is_a_draft(): void
    {
        $catalog = Catalog::factory()->create();

        $this->assertSame(CatalogVisibility::Draft, $catalog->visibility());
        $this->assertFalse($catalog->visibility()->isPubliclyAccessible());
    }

    /**
     * Caso 2 · publicado y en la vitrina.
     */
    public function test_a_published_and_listed_catalog_is_listed(): void
    {
        $catalog = Catalog::factory()->published()->create();

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());
        $this->assertTrue($catalog->visibility()->appearsInStorefront());
    }

    /**
     * Caso 3 · publicado fuera de la vitrina. `is_listed` **no** controla el
     * acceso (RB-3): sigue siendo alcanzable por enlace, que es como se
     * reparte una campaña por WhatsApp.
     */
    public function test_a_published_and_unlisted_catalog_is_unlisted_but_accessible(): void
    {
        $catalog = Catalog::factory()->published()->unlisted()->create();

        $this->assertSame(CatalogVisibility::Unlisted, $catalog->visibility());
        $this->assertTrue($catalog->visibility()->isPubliclyAccessible());
        $this->assertFalse($catalog->visibility()->appearsInStorefront());
    }

    /**
     * Caso 4 · la caducidad pasó. Su URL responde `410` y no `404`
     * (`REQ-CAT-021` SP-3).
     */
    public function test_a_catalog_past_its_expiry_is_expired(): void
    {
        $catalog = Catalog::factory()->expired()->create();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());
        $this->assertFalse($catalog->visibility()->isPubliclyAccessible());
    }

    /**
     * **El cruce raro, y el que se tuerce solo.**
     *
     * Sin publicar y con la caducidad ya pasada: es borrador, no expirado. Lo
     * que nunca se publicó no puede haber caducado, y su enlace nunca circuló,
     * así que la respuesta correcta es `404` y no `410`. Evaluar la expiración
     * antes que la publicación daría lo contrario sin que ningún otro test se
     * pusiera rojo.
     */
    public function test_a_never_published_catalog_with_a_past_expiry_is_still_a_draft(): void
    {
        $catalog = Catalog::factory()->expiresAt(now()->subDay())->create();

        $this->assertNull($catalog->published_at);
        $this->assertSame(CatalogVisibility::Draft, $catalog->visibility());
    }

    /**
     * La segunda precedencia: la expiración va **por delante de
     * `is_listed`**. Un catálogo caducado no aparece en la vitrina ni se sirve
     * por enlace, así que el valor de `is_listed` deja de importar.
     */
    public function test_expiry_wins_over_is_listed(): void
    {
        foreach ([true, false] as $isListed) {
            $catalog = Catalog::factory()->expired()->create(['is_listed' => $isListed]);

            $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());
        }
    }

    /**
     * Una caducidad **futura** no expira nada: es la campaña todavía viva.
     */
    public function test_a_future_expiry_does_not_expire_the_catalog(): void
    {
        $catalog = Catalog::factory()->published()->create(['expires_at' => now()->addMonth()]);

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());
    }

    /**
     * Un `published_at` **futuro** cuenta como publicado.
     *
     * Para el producto la fecha futura es publicación programada
     * (`REQ-CAT-007` RB-5), pero para el catálogo no existe tal cosa:
     * `REQ-CAT-022` deja la programación de la publicación fuera de alcance
     * —«solo se programa la expiración»— y no hay quinto estado. El test fija
     * la respuesta para que nadie la invente en la otra dirección al leer la
     * regla del producto.
     */
    public function test_a_future_publication_date_still_counts_as_published(): void
    {
        $catalog = Catalog::factory()->create(['published_at' => now()->addWeek()]);

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());
    }

    /**
     * La expiración se evalúa **sin job** (RB-6): basta con que pase el reloj
     * para que el mismo registro cambie de estado.
     */
    public function test_the_catalog_expires_on_the_clock_without_a_job(): void
    {
        $catalog = Catalog::factory()->published()->create(['expires_at' => now()->addHour()]);

        $this->assertSame(CatalogVisibility::Listed, $catalog->visibility());

        $this->travel(2)->hours();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());
    }

    /**
     * Y la invariante que sostiene a todas las anteriores: **no hay columna
     * `visibility`**. El día que apareciera, la lista estaría escrita dos
     * veces y podría discrepar de las fechas.
     */
    public function test_the_catalogs_table_has_no_visibility_column(): void
    {
        $this->assertFalse(Schema::hasColumn('catalogs', 'visibility'));
        $this->assertFalse(Schema::hasColumn('catalogs', 'status'));
        $this->assertTrue(Schema::hasColumn('catalogs', TenantScope::COLUMN));
    }
}
