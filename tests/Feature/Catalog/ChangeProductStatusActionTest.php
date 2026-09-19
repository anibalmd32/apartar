<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ChangeProductStatusAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El archivado, que es lo único que esta acción sabe hacer hoy
 * (`REQ-BOF-020` RB-10, `REQ-CAT-007`).
 *
 * **Nace a medias y el relevo está declarado:** `T-030` le añade la matriz
 * completa de transiciones y la completitud de RB-5. Lo que se fija aquí es lo
 * que aquella tarea no puede romper —archivar conserva la fila y su sitio en
 * los catálogos— y que el evento de auditoría ya sale de un único sitio.
 */
final class ChangeProductStatusActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_archives_a_published_product(): void
    {
        $product = Product::factory()->published()->create();

        $this->archiver()->archive($product, $this->actor);

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
        $this->assertFalse($product->status->isVisibleInCatalog());
        $this->assertFalse($product->status->canBeReserved());
    }

    /**
     * RB-10 · **el borrado es lógico**: la fila sigue ahí, porque los
     * apartados vigentes operan sobre su snapshot y el historial tiene que
     * sobrevivir (`REQ-CAT-007` RB-2).
     */
    public function test_archiving_does_not_delete_the_row(): void
    {
        $product = Product::factory()->published()->create();

        $this->archiver()->archive($product, $this->actor);

        $this->assertNotNull(Product::query()->find($product->getKey()));
        $this->assertNull($product->refresh()->deleted_at);
    }

    /**
     * SP-10 y `REQ-CAT-007` RB-7 · **conserva su relación con los catálogos**,
     * que simplemente dejan de renderizarlo. Es lo que permite desarchivarlo
     * sin recomponer nada, y lo que se rompería si alguien decidiera desatar
     * el pivote «para limpiar».
     */
    public function test_archiving_keeps_the_product_in_its_catalogs(): void
    {
        $catalog = Catalog::factory()->published()->create();
        $product = Product::factory()->published()->create();

        $catalog->products()->attach($product, ['position' => 1]);

        $this->archiver()->archive($product, $this->actor);

        $this->assertSame(1, $catalog->products()->count());
        $this->assertSame(1, $product->refresh()->catalogs()->count());
    }

    /**
     * Se audita con su antes y su después, desde el emisor que declara
     * `AuditEvent::ProductStatusChanged`.
     */
    public function test_archiving_is_audited(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Taladro Bosch']);

        $this->archiver()->archive($product, $this->actor);

        $entry = AuditEntry::query()->sole();

        $this->assertSame(AuditEvent::ProductStatusChanged->value, $entry->event);
        $this->assertStringContainsString('Taladro Bosch', $entry->description);
        $this->assertSame('published', $entry->properties['changed']['status']['before'] ?? null);
        $this->assertSame('archived', $entry->properties['changed']['status']['after'] ?? null);
    }

    /**
     * Archivar lo ya archivado no es un error —suele ser el segundo clic de
     * quien no vio el primero— y **no escribe una entrada**, porque no ha
     * cambiado nada que auditar.
     */
    public function test_archiving_twice_is_idempotent_and_writes_one_entry(): void
    {
        $product = Product::factory()->published()->create();

        $this->archiver()->archive($product, $this->actor);
        $this->archiver()->archive($product, $this->actor);

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_a_member_may_archive(): void
    {
        $product = Product::factory()->published()->create();
        $member = User::factory()->create();

        $this->archiver()->archive($product, $member);

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $product = Product::factory()->published()->create();

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->archiver()->archive($product, $foreignOwner);
    }

    private function archiver(): ChangeProductStatusAction
    {
        return app(ChangeProductStatusAction::class);
    }
}
