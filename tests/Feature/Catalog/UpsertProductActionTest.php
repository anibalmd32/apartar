<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ProductData;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Money\Money;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * El alta y la edición de un producto, por debajo del HTTP
 * (`REQ-BOF-020`).
 *
 * Lo que la prueba de la pantalla no ve: la resolución de la taxonomía con sus
 * dos mitades, la guarda de escritura de `product_tag` —que ninguna columna
 * sostiene— y qué se audita exactamente.
 */
final class UpsertProductActionTest extends TestCase
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

    /**
     * RB-3 · el producto nace en borrador y con el enlace derivado del nombre.
     */
    public function test_it_creates_a_draft_with_a_derived_slug(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro Bosch GSB'), $this->actor);

        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame('taladro-bosch-gsb', $product->slug);
        $this->assertSame(25_000, $product->price_cents->cents);
        $this->assertNull($product->published_at);
    }

    /**
     * Dos nombres distintos que dan el mismo enlace se desambiguan con sufijo,
     * igual que en la taxonomía: rechazar el segundo obligaría a renombrar un
     * producto legítimo por un detalle del que nadie le ha hablado.
     */
    public function test_a_colliding_slug_gets_a_suffix(): void
    {
        $this->upsert()->handle($this->data(name: 'Taladro rojo', sku: 'A-1'), $this->actor);
        $second = $this->upsert()->handle($this->data(name: 'Taladro-rojo', sku: 'A-2'), $this->actor);

        $this->assertSame('taladro-rojo-2', $second->slug);
    }

    /**
     * SP-4 · el SKU es único **por organización**, y el mensaje llega al campo
     * que hay que corregir.
     */
    public function test_a_duplicate_sku_in_the_same_organization_is_rejected(): void
    {
        $this->upsert()->handle($this->data(sku: 'BOSCH-01'), $this->actor);

        try {
            $this->upsert()->handle($this->data(name: 'Otro', sku: 'BOSCH-01'), $this->actor);
            $this->fail('El SKU duplicado debería rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }

        $this->assertSame(1, Product::query()->count());
    }

    /**
     * **Y también cuenta lo archivado con borrado lógico.** El índice único de
     * la base no sabe de `deleted_at`, así que comprobarlo sin `withTrashed()`
     * daría el `422` correcto casi siempre y un error de base de datos justo
     * cuando alguien reutiliza el código de algo que quitó de en medio.
     */
    public function test_a_sku_held_by_a_trashed_product_is_still_taken(): void
    {
        $this->upsert()->handle($this->data(sku: 'BOSCH-01'), $this->actor)->delete();

        $this->expectException(ValidationException::class);

        $this->upsert()->handle($this->data(name: 'Otro', sku: 'BOSCH-01'), $this->actor);
    }

    /**
     * RB-3 y SP-5 · el enlace se conserva al renombrar y se cierra en cuanto el
     * producto está publicado: su URL ya circuló.
     */
    public function test_renaming_keeps_the_slug_and_publishing_locks_it(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro'), $this->actor);

        $this->upsert()->handle($this->data(name: 'Taladro Bosch'), $this->actor, $product);

        $this->assertSame('Taladro Bosch', $product->refresh()->name);
        $this->assertSame('taladro', $product->slug);

        // Mientras es borrador, el enlace sí se cambia.
        $this->upsert()->handle($this->data(name: 'Taladro Bosch', slug: 'taladro-bosch'), $this->actor, $product);

        $this->assertSame('taladro-bosch', $product->refresh()->slug);

        $product->forceFill(['status' => ProductStatus::Published])->save();

        try {
            $this->upsert()->handle($this->data(name: 'Taladro Bosch', slug: 'otro-enlace'), $this->actor, $product);
            $this->fail('El enlace de un producto publicado no debería poder cambiarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
        }

        $this->assertSame('taladro-bosch', $product->refresh()->slug);
    }

    /**
     * Reenviar el mismo enlace sin cambiarlo no es pedir un cambio, así que un
     * producto publicado se sigue pudiendo editar entero.
     */
    public function test_resending_the_same_slug_on_a_published_product_is_not_a_change(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro'), $this->actor);
        $product->forceFill(['status' => ProductStatus::Published])->save();

        $this->upsert()->handle($this->data(name: 'Taladro Bosch', slug: 'taladro'), $this->actor, $product);

        $this->assertSame('Taladro Bosch', $product->refresh()->name);
    }

    /**
     * `REQ-BOF-023` RB-8 · la categoría escrita al vuelo se crea si es nueva y
     * **se reutiliza** si ya existe. Es lo contrario que en el CRUD de
     * `/categorias`, donde el mismo nombre es `422`.
     */
    public function test_a_category_written_on_the_fly_is_created_or_reused(): void
    {
        $first = $this->upsert()->handle($this->data(sku: 'A-1', categoryName: 'Herramientas'), $this->actor);
        $second = $this->upsert()->handle($this->data(name: 'Otro', sku: 'A-2', categoryName: 'herramientas'), $this->actor);

        $this->assertSame(1, Category::query()->count());
        $this->assertSame($first->category_id, $second->category_id);
        $this->assertSame('Herramientas', Category::query()->sole()->name);
    }

    public function test_tags_written_on_the_fly_are_created_or_reused(): void
    {
        $existing = Tag::factory()->named('Oferta')->create();

        $product = $this->upsert()->handle(
            $this->data(tagNames: ['oferta', 'Novedad', 'Novedad']),
            $this->actor,
        );

        $this->assertSame(2, Tag::query()->count());
        $this->assertEqualsCanonicalizing(
            ['Oferta', 'Novedad'],
            $product->tags()->pluck('name')->all(),
        );
        $this->assertTrue($product->tags()->whereKey($existing->getKey())->exists());
    }

    /**
     * Guardar con la lista completa **quita** lo que ya no está: `sync` y no
     * `attach`, o desmarcar una etiqueta no haría nada.
     */
    public function test_saving_replaces_the_tag_list(): void
    {
        $oferta = Tag::factory()->named('Oferta')->create();
        $novedad = Tag::factory()->named('Novedad')->create();

        $product = $this->upsert()->handle(
            $this->data(tagIds: [(int) $oferta->getKey(), (int) $novedad->getKey()]),
            $this->actor,
        );

        $this->upsert()->handle($this->data(tagIds: [(int) $novedad->getKey()]), $this->actor, $product);

        $this->assertSame(['Novedad'], $product->tags()->pluck('name')->all());
    }

    /**
     * **La guarda que ninguna columna sostiene.**
     *
     * `product_tag` no lleva `organization_id`, y el trait del tenant no cubre
     * este caso: lanza cuando alguien asigna a mano una organización ajena, no
     * cuando una clave foránea apunta a otra. Sin esta comprobación, la fila se
     * guardaría con la organización activa y el producto quedaría etiquetado
     * con algo del negocio de al lado, sin que nada fallara.
     */
    public function test_a_tag_of_another_organization_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(CrossTenantRelationException::class);

        $this->upsert()->handle($this->data(tagIds: [(int) $foreign->getKey()]), $this->actor);
    }

    /**
     * Y la misma frontera para la categoría, que sí tiene columna: lo que se
     * comprueba aquí es que el identificador se resuelve **por consulta del
     * tenant** y no con un `find()` sin filtrar.
     */
    public function test_a_category_of_another_organization_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Category => Category::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(CrossTenantRelationException::class);

        $this->upsert()->handle($this->data(categoryId: (int) $foreign->getKey()), $this->actor);
    }

    /**
     * Y nada se guarda a medias: la transacción deshace la categoría creada al
     * vuelo cuando la etiqueta de al lado resulta ser ajena.
     */
    public function test_nothing_is_written_when_a_relation_crosses_the_border(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        try {
            $this->upsert()->handle(
                $this->data(categoryName: 'Herramientas', tagIds: [(int) $foreign->getKey()]),
                $this->actor,
            );
        } catch (CrossTenantRelationException) {
            // Se espera.
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Category::query()->count());
    }

    /**
     * RB-6 · **se auditan las condiciones de pago**, con su antes y su después.
     */
    public function test_changing_the_payment_terms_is_audited(): void
    {
        $product = $this->upsert()->handle($this->data(), $this->actor);

        $this->upsert()->handle($this->data(price: '300.00'), $this->actor, $product);

        $entry = AuditEntry::query()->sole();

        $this->assertSame(AuditEvent::ProductPricingChanged->value, $entry->event);
        $this->assertSame(25_000, $entry->properties['changed']['price_cents']['before'] ?? null);
        $this->assertSame(30_000, $entry->properties['changed']['price_cents']['after'] ?? null);
    }

    /**
     * Y **no** se audita lo que no es una condición de pago: la lista de
     * `REQ-BOF-005` RB-1 es cerrada y corregir una errata no está en ella.
     * Tampoco el alta: un producto nuevo no cambió sus condiciones, las
     * estrenó.
     */
    public function test_creating_or_renaming_writes_no_audit_entry(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro'), $this->actor);

        $this->upsert()->handle($this->data(name: 'Taladro Bosch'), $this->actor, $product);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * RB-4 de `REQ-CAT-007` · agotado **no** cambia el estado. El producto
     * sigue publicado, se muestra con su sello y lo que se deshabilita es
     * apartarlo — que es información para quien mira, no un producto que
     * desaparece del catálogo.
     */
    public function test_running_out_of_stock_does_not_change_the_status(): void
    {
        $product = $this->upsert()->handle($this->data(), $this->actor);
        $product->forceFill(['status' => ProductStatus::Published])->save();

        $this->upsert()->handle($this->data(stock: 0), $this->actor, $product);

        $this->assertSame(0, $product->refresh()->stock);
        $this->assertSame(ProductStatus::Published, $product->status);
    }

    /**
     * De los dos roles: el inventario es operación diaria
     * (`REQ-ORG-003` RB-1).
     */
    public function test_a_member_may_create_products(): void
    {
        $member = User::factory()->create();

        $this->assertSame('Taladro', $this->upsert()->handle($this->data(name: 'Taladro'), $member)->name);
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->upsert()->handle($this->data(), $foreignOwner);
    }

    /**
     * @param  list<int>  $tagIds
     * @param  list<string>  $tagNames
     */
    private function data(
        string $name = 'Taladro Bosch',
        string $sku = 'BOSCH-01',
        ?string $slug = null,
        string $price = '250.00',
        string $downPayment = '50.00',
        int $stock = 10,
        ?int $categoryId = null,
        ?string $categoryName = null,
        array $tagIds = [],
        array $tagNames = [],
    ): ProductData {
        return new ProductData(
            name: $name,
            sku: $sku,
            slug: $slug,
            description: 'Un taladro.',
            price: Money::fromDecimalString($price),
            compareAtPrice: null,
            downPayment: Money::fromDecimalString($downPayment),
            installmentsCount: 4,
            frequencyDays: 15,
            stock: $stock,
            categoryId: $categoryId,
            categoryName: $categoryName,
            tagIds: $tagIds,
            tagNames: $tagNames,
        );
    }

    private function upsert(): UpsertProductAction
    {
        return app(UpsertProductAction::class);
    }
}
