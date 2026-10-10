<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ChangeProductStatusAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\IncompleteProductException;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\TranscodeProductImage;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La matriz de estados de un producto y lo que cada paso exige
 * (`REQ-CAT-007`, `REQ-BOF-020` RB-5).
 *
 * `T-027` escribió el archivado; `T-030` le añadió delante la matriz, la
 * completitud de RB-5 y las otras tres transiciones. Los dos bloques de casos
 * conviven aquí a propósito: lo que aquella tarea fijó —archivar conserva la
 * fila y su sitio en los catálogos, y el evento de auditoría sale de un único
 * sitio— es exactamente lo que esta no podía romper.
 *
 * Lo que **no** se prueba aquí es el `404` de SP-1: no hay página pública
 * hasta `T-033` y `T-035`. El mecanismo —que un `draft` o un `archived` no
 * tengan forma de entrar en la consulta— es `Product::scopePublished()` y se
 * prueba en `ProductModelTest`.
 */
final class ChangeProductStatusActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // Las conversiones se generan en cola (`REQ-BOF-022` RB-1). En los
        // tests la cola es `sync`, así que sin esto cada foto de atrezo
        // intentaría convertirse de verdad.
        Bus::fake([TranscodeProductImage::class]);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
        // `T-067` · crear, publicar y componer preguntan al plan.
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    // ------------------------------------------------------------------
    // El cupo `products` (`REQ-SUB-003` RB-3, SP-2)
    // ------------------------------------------------------------------

    /**
     * Archivar libera una unidad y restaurar la vuelve a ocupar, en la misma
     * transacción; publicar y despublicar no la mueven, porque el borrador ya
     * cuenta. Repetir un paso no mueve nada.
     */
    public function test_archiving_frees_a_products_slot_and_restoring_takes_it_back(): void
    {
        $product = $this->publishable();
        $this->recalculateUsage();
        $this->assertSame(1, $this->productsCounter());

        $this->changer()->publish($product, $this->actor);
        $this->assertSame(1, $this->productsCounter());

        $this->changer()->archive($product, $this->actor);
        $this->assertSame(0, $this->productsCounter());

        app(ChangeProductStatusAction::class)->archive($product, $this->actor);
        $this->assertSame(0, $this->productsCounter());

        app(ChangeProductStatusAction::class)->restore($product, $this->actor);
        $this->assertSame(1, $this->productsCounter());

        app(ChangeProductStatusAction::class)->restore($product, $this->actor);
        $this->assertSame(1, $this->productsCounter());
    }

    // ------------------------------------------------------------------
    // La matriz (RB-1, SP-4)
    // ------------------------------------------------------------------

    /**
     * Los cinco pasos que la matriz acepta, uno por uno.
     *
     * Van juntos en un solo caso porque lo que se afirma es la **matriz**, no
     * cada paso: repartidos en cinco métodos, quitar una fila de la tabla
     * dejaría cuatro en verde y uno en rojo, y el que falta no se leería como
     * «falta un paso» sino como «ese paso se rompió».
     */
    public function test_the_matrix_accepts_the_five_legitimate_steps(): void
    {
        // Sembrados antes de `changer()`, que cuadra el contador con ellos.
        [$draft, $toArchive, $published, $publishedToArchive, $archived] = [
            $this->publishable(),
            Product::factory()->create(),
            Product::factory()->published()->create(),
            Product::factory()->published()->create(),
            Product::factory()->archived()->create(),
        ];

        $status = $this->changer();

        $this->assertSame(ProductStatus::Published, $status->publish($draft, $this->actor)->status);
        $this->assertSame(ProductStatus::Archived, $status->archive($toArchive, $this->actor)->status);
        $this->assertSame(ProductStatus::Draft, $status->unpublish($published, $this->actor)->status);
        $this->assertSame(ProductStatus::Archived, $status->archive($publishedToArchive, $this->actor)->status);
        $this->assertSame(ProductStatus::Draft, $status->restore($archived, $this->actor)->status);
    }

    /**
     * **SP-4 · el paso que la matriz existe para negar.**
     *
     * Un archivado que volviera publicado se cuela de golpe en los catálogos
     * donde seguía —su relación se conserva (RB-7)— con precio, existencias y
     * fotos que nadie ha revisado desde el día en que se retiró. La parada en
     * borrador es lo que obliga a mirarlo.
     *
     * Se comprueba **antes** que la completitud: un archivado completo sigue
     * sin poder publicarse de un salto, y si el orden fuera el contrario el
     * mensaje hablaría de lo que le falta en vez de del paso que no existe.
     */
    public function test_an_archived_product_cannot_be_published_directly(): void
    {
        $product = $this->publishable();

        $this->changer()->archive($product, $this->actor);

        try {
            $this->changer()->publish($product->refresh(), $this->actor);

            $this->fail('Publicar un archivado tenía que rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertStringContainsString('restáuralo primero', $exception->getMessage());
        }

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
    }

    /**
     * Repetir un paso ya aplicado no es un error —suele ser el segundo clic de
     * quien no vio el primero— y **no escribe nada**: ni columna ni entrada de
     * auditoría, porque no ha cambiado nada que auditar.
     */
    public function test_repeating_an_applied_step_changes_nothing(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        $publishedAt = $product->refresh()->published_at;

        $this->travel(2)->hours();

        $this->changer()->publish($product, $this->actor);

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        // La fecha no se mueve: volver a pulsar «Publicar» no puede adelantar
        // ni retrasar una publicación programada.
        $this->assertTrue($publishedAt?->equalTo($product->published_at));
        $this->assertSame(1, AuditEntry::query()->count());
    }

    /**
     * Y lo mismo por el lado del archivado, que es lo que `T-027` fijó.
     */
    public function test_archiving_twice_is_idempotent_and_writes_one_entry(): void
    {
        $product = Product::factory()->published()->create();

        $this->changer()->archive($product, $this->actor);
        $this->changer()->archive($product, $this->actor);

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    // ------------------------------------------------------------------
    // La completitud de RB-5 (SP-3)
    // ------------------------------------------------------------------

    /**
     * **RB-5 · sin foto no se publica** (`REQ-BOF-020` SP-3).
     *
     * La pregunta la contesta `Product::hasImages()`, que es de `T-029` y la
     * misma que su SP-4 usa para no dejar sin fotos un producto ya publicado.
     * Dos momentos, un solo dato.
     */
    public function test_publishing_without_images_is_refused(): void
    {
        $this->assertMissingToPublish($this->publishable(withImage: false), 'images', 'al menos una foto');
    }

    /**
     * RB-5 · sin categoría no se publica. `category_id` es nullable justamente
     * porque un borrador puede estar incompleto.
     */
    public function test_publishing_without_a_category_is_refused(): void
    {
        $product = $this->publishable();
        $product->forceFill(['category_id' => null])->save();

        $this->assertMissingToPublish($product, 'category_id', 'una categoría');
    }

    /**
     * RB-5 · sin descripción no se publica. Una cadena de espacios tampoco
     * cuenta: lo que exige la regla es texto, no una columna no nula.
     */
    public function test_publishing_without_a_description_is_refused(): void
    {
        $product = $this->publishable();
        $product->forceFill(['description' => '   '])->save();

        $this->assertMissingToPublish($product, 'description', 'la descripción');
    }

    /**
     * **RB-5 · condiciones financieras válidas, y no una segunda lista de
     * ellas.** La comprobación se delega en `CoherentFinancingTerms`, que es
     * la misma que atraviesa el formulario; lo que se afirma aquí es que la
     * fila guardada se vuelve a mirar al publicar, porque un `draft` pudo
     * llegar a ese estado por caminos que no pasan por el formulario.
     */
    public function test_publishing_with_incoherent_financing_terms_is_refused(): void
    {
        $product = $this->publishable();

        // La inicial por encima del precio: con ella no queda nada que
        // financiar y el cronograma saldría negativo.
        $product->forceFill(['down_payment_cents' => 30_000, 'price_cents' => 25_000])->save();

        $this->assertMissingToPublish($product, 'down_payment', 'las condiciones de pago');
    }

    /**
     * **SP-3 · la lista, que es lo que hace útil al `422`.**
     *
     * Quien pulsa «Publicar» con un producto sin foto y sin categoría va a
     * arreglar las dos de una pasada. Un mensaje que nombrara solo la primera
     * lo mandaría a buscar dos veces, y un «el producto está incompleto» lo
     * mandaría a buscar sin más.
     */
    public function test_the_refusal_names_everything_that_is_missing_at_once(): void
    {
        $product = $this->publishable(withImage: false);
        $product->forceFill(['category_id' => null, 'description' => null])->save();

        try {
            $this->changer()->publish($product, $this->actor);

            $this->fail('Publicar un producto incompleto tenía que rechazarse.');
        } catch (IncompleteProductException $exception) {
            $this->assertSame(
                ['images', 'category_id', 'description'],
                array_keys($exception->missing),
            );

            $this->assertStringContainsString('al menos una foto, una categoría y la descripción', $exception->getMessage());
            $this->assertSame(422, IncompleteProductException::STATUS);
        }

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
    }

    /**
     * Un producto completo pasa, y esa es la otra mitad: una lista de
     * requisitos que nunca deja publicar nada está tan rota como una que deja
     * publicar cualquier cosa.
     */
    public function test_a_complete_product_publishes(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertTrue($product->status->isVisibleInCatalog());
        $this->assertSame(1, Product::query()->published()->count());
    }

    /**
     * **RB-6 y SP-6 · publicado en 0 catálogos no es un error.**
     *
     * La visibilidad es la **intersección** del estado del producto y la
     * accesibilidad del catálogo que lo contiene, así que un producto puede
     * estar `published` y no verlo nadie. No es un caso que haya que impedir:
     * es el estado normal de un producto recién publicado, y pedirle un
     * catálogo para dejarlo publicar invertiría el orden en que se trabaja.
     * Quien lo dice con palabras es el backoffice («publicado, en 0
     * catálogos»), y quien cruza las dos mitades es la consulta pública de
     * `T-033`.
     */
    public function test_publishing_a_product_that_is_in_no_catalog_is_not_an_error(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertSame(0, $product->catalogs()->count());
        // El estado contesta que sí; que además lo vea alguien depende del
        // catálogo, y eso este enum no lo sabe.
        $this->assertTrue($product->status->isVisibleInCatalog());
    }

    // ------------------------------------------------------------------
    // La fecha de publicación (RB-5, SP-5)
    // ------------------------------------------------------------------

    /**
     * Publicar sin decir cuándo fija `published_at` en el acto, y el producto
     * se lista de inmediato.
     */
    public function test_publishing_stamps_the_date_now(): void
    {
        $this->freezeTime();

        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        // Al segundo, que es la precisión con la que `DATETIME` guarda la
        // columna: comparar microsegundos sería comparar contra lo que la base
        // truncó al escribir.
        $this->assertSame(
            now()->toDateTimeString(),
            $product->refresh()->published_at?->toDateTimeString(),
        );

        $this->assertFalse($product->isScheduled());
        $this->assertSame(1, Product::query()->published()->count());
    }

    /**
     * **SP-5 · fecha futura es publicación programada, evaluada en la
     * consulta.**
     *
     * El estado pasa a `published` igualmente —no hay un cuarto estado— y lo
     * que lo mantiene invisible es `scopePublished()`. Un job que publicara a
     * la hora sería un segundo mecanismo que puede no correr.
     */
    public function test_publishing_with_a_future_date_schedules_it(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor, now()->addWeek());

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertTrue($product->isScheduled());
        $this->assertSame(0, Product::query()->published()->count());

        $this->travel(8)->days();

        $this->assertSame(1, Product::query()->published()->count());
        $this->assertFalse($product->refresh()->isScheduled());
    }

    /**
     * Despublicar vacía la fecha: un borrador con fecha de publicación es una
     * fila que se contradice, y dejarla puesta haría que una programación que
     * alguien canceló volviera a leerse como programada.
     */
    public function test_unpublishing_clears_the_publication_date(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor, now()->addWeek());
        $this->changer()->unpublish($product->refresh(), $this->actor);

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
        $this->assertNull($product->published_at);
        $this->assertFalse($product->isScheduled());
    }

    // ------------------------------------------------------------------
    // Restaurar (SP-4)
    // ------------------------------------------------------------------

    /**
     * **SP-4 · restaurar deja `draft`, nunca `published`.**
     *
     * Y deja además la fecha vacía, para que el producto no vuelva a la vista
     * pública sin que alguien pulse «Publicar» con la lista de RB-5 delante.
     */
    public function test_restoring_an_archived_product_leaves_it_in_draft(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);
        $this->changer()->archive($product->refresh(), $this->actor);
        $this->changer()->restore($product->refresh(), $this->actor);

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
        $this->assertNull($product->published_at);
        $this->assertFalse($product->status->isVisibleInCatalog());
        $this->assertSame(0, Product::query()->published()->count());
    }

    /**
     * Y **el producto sigue donde estaba** (RB-7): restaurar no recompone
     * catálogos porque archivar no los tocó. Lo único que falta para que
     * vuelva a verse es publicarlo.
     */
    public function test_restoring_does_not_have_to_recompose_catalogs(): void
    {
        $catalog = Catalog::factory()->published()->create();
        $product = $this->publishable();

        $catalog->products()->attach($product, ['position' => 1]);

        $this->changer()->archive($product, $this->actor);
        $this->changer()->restore($product->refresh(), $this->actor);

        $this->assertSame(1, $catalog->products()->count());
        $this->assertSame(1, $product->refresh()->catalogs()->count());
    }

    // ------------------------------------------------------------------
    // Lo que `T-027` fijó y esta tarea no podía romper
    // ------------------------------------------------------------------

    public function test_it_archives_a_published_product(): void
    {
        $product = Product::factory()->published()->create();

        $this->changer()->archive($product, $this->actor);

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

        $this->changer()->archive($product, $this->actor);

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

        $this->changer()->archive($product, $this->actor);

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

        $this->changer()->archive($product, $this->actor);

        $entry = AuditEntry::query()->sole();

        $this->assertSame(AuditEvent::ProductStatusChanged->value, $entry->event);
        $this->assertStringContainsString('Taladro Bosch', $entry->description);
        $this->assertSame('published', $entry->properties['changed']['status']['before'] ?? null);
        $this->assertSame('archived', $entry->properties['changed']['status']['after'] ?? null);
    }

    /**
     * **Un solo evento para las cuatro transiciones** (`REQ-BOF-005` RB-1).
     *
     * La lista de eventos auditables es cerrada; un evento por transición
     * nueva la haría crecer sin tocar la spec. Lo que sí cambia con el paso es
     * el contenido: publicar arrastra además la fecha desde la que lo está,
     * que es lo que explica una programación al leer el historial.
     */
    public function test_every_transition_writes_the_same_event(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);
        $this->changer()->unpublish($product->refresh(), $this->actor);
        $this->changer()->archive($product->refresh(), $this->actor);

        $events = AuditEntry::query()->pluck('event')->all();

        $this->assertSame([
            AuditEvent::ProductStatusChanged->value,
            AuditEvent::ProductStatusChanged->value,
            AuditEvent::ProductStatusChanged->value,
        ], $events);

        $changed = AuditEntry::query()->orderBy('id')->firstOrFail()->properties['changed'] ?? [];

        $this->assertArrayHasKey('published_at', $changed);
        $this->assertNull($changed['published_at']['before']);
        $this->assertNotNull($changed['published_at']['after']);
    }

    public function test_a_member_may_change_the_status(): void
    {
        $product = Product::factory()->published()->create();
        $member = User::factory()->create();

        $this->changer()->archive($product, $member);

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

        $this->changer()->archive($product, $foreignOwner);
    }

    /**
     * Y la autorización se ejerce en las cuatro, no solo en la que `T-027`
     * escribió: publicar es la que cambia lo que ve un desconocido.
     */
    public function test_publishing_from_another_organization_is_not_authorised(): void
    {
        $product = $this->publishable();

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->changer()->publish($product, $foreignOwner);
    }

    /**
     * Afirma que al producto le falta ese requisito, y solo ese.
     *
     * El campo es donde aterriza el mensaje —junto al control que hay que
     * corregir— y el rótulo es cómo se nombra en el resumen, que es lo que lee
     * quien está en el listado.
     */
    private function productsCounter(): int
    {
        return (int) UsageCounter::query()->where('metric', UsageMetric::Products)->value('value');
    }

    private function assertMissingToPublish(Product $product, string $field, string $label): void
    {
        try {
            $this->changer()->publish($product, $this->actor);

            $this->fail(sprintf('Publicar sin «%s» tenía que rechazarse.', $label));
        } catch (IncompleteProductException $exception) {
            $this->assertSame([$field], array_keys($exception->missing));
            $this->assertStringContainsString($label, $exception->getMessage());
            $this->assertTrue($exception->product->is($product));
        }

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
    }

    /**
     * Un producto que cumple las cuatro condiciones de RB-5.
     *
     * Con `$withImage` en `false` cumple tres, que es el caso de la foto
     * ausente: la única de las cuatro que no se puede quitar editando una
     * columna.
     */
    private function publishable(bool $withImage = true): Product
    {
        $product = Product::factory()
            ->inCategory(Category::factory()->create())
            ->create(['description' => 'Taladro percutor de 750 W con maletín.']);

        if ($withImage) {
            $product
                ->addMedia($this->image())
                ->withCustomProperties([
                    TenantPathGenerator::ORGANIZATION_PROPERTY => $this->organization->getKey(),
                ])
                ->toMediaCollection(Product::IMAGES);
        }

        return $product->refresh();
    }

    /**
     * Un PNG de verdad: medialibrary lee el archivo al adjuntarlo.
     */
    private function image(): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-foto.png';

        imagepng(imagecreatetruecolor(4, 4), $path);

        return new UploadedFile($path, 'foto.png', 'image/png', null, true);
    }

    /**
     * Cuadra antes el contador de `products` (`T-066`): las factories no
     * ocupan su unidad, y archivar una que nunca se sumó la restaría de cero.
     */
    private function changer(): ChangeProductStatusAction
    {
        $this->recalculateUsage();

        return app(ChangeProductStatusAction::class);
    }
}
