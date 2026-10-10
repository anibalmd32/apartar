<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ImageSet;
use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Las fotos de un producto, por HTTP (`REQ-BOF-022`, `REQ-CAT-005`).
 *
 * Las imágenes son de verdad —generadas con GD— y no `UploadedFile::fake()`,
 * por lo mismo que en `RealMimeTypeTest`: el doble de Laravel deduce su tipo
 * del nombre del archivo, que es justo lo que la validación de MIME real
 * existe para no hacer.
 *
 * El disco se falsea con `Storage::fake()` para que la suite no escriba en el
 * volumen de verdad, y eso deja igual de comprobable lo que importa aquí: **la
 * ruta**, que es donde vive el aislamiento físico de RB-9.
 */
final class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // Las conversiones se generan en cola (RB-1). En los tests la cola es
        // `sync`, así que sin esto cada subida intentaría convertir de verdad
        // sobre un PNG de cuatro píxeles.
        Bus::fake([TranscodeProductImage::class]);

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        // `T-067` · crear, publicar y componer preguntan al plan.
        $this->subscribeWithoutLimits($this->organization);

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_it_uploads_an_image_and_lands_back_on_the_form(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertRedirect("/backoffice/productos/{$product->getKey()}/editar");

        $this->assertSame(1, $this->imagesOf($product)->count());
    }

    /**
     * **RB-9 · la ruta física lleva el `organization_id` dentro.**
     *
     * Es el aislamiento que el *global scope* no puede dar: un archivo en
     * disco no se consulta, se sirve por su ruta. Sin este segmento, «qué
     * archivos son de quién» solo se puede responder mirando una tabla.
     */
    public function test_the_stored_path_includes_the_organization(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertSessionHasNoErrors();

        $media = $this->imagesOf($product)->sole();

        // `getPath()` es absoluto —lleva la raíz del disco delante— y lo que
        // se comprueba es el tramo que compone el generador.
        $this->assertStringContainsString(
            sprintf('organizations/%d/media/%s/', $this->organization->getKey(), $media->getKey()),
            $media->getPath(),
        );

        Storage::disk('public')->assertExists(
            sprintf('organizations/%d/media/%s/%s', $this->organization->getKey(), $media->getKey(), $media->file_name),
        );
    }

    /**
     * Y las de dos negocios no comparten directorio, que es lo que este
     * segmento tiene que garantizar de verdad.
     */
    public function test_two_organizations_do_not_share_a_directory(): void
    {
        $neighbour = $this->neighbourOrganization();

        $mine = $this->uploadTo($this->organization);
        $theirs = $this->uploadTo($neighbour);

        $this->assertStringContainsString('organizations/'.$this->organization->getKey().'/', $mine->getPath());
        $this->assertStringContainsString('organizations/'.$neighbour->getKey().'/', $theirs->getPath());
        $this->assertNotSame(dirname($mine->getPath()), dirname($theirs->getPath()));
    }

    /**
     * RB-1 y SP-1 · **la undécima se rechaza**, y el límite cuenta las que ya
     * hay: quien tiene ocho y sube cuatro se pasa.
     */
    public function test_the_eleventh_image_is_rejected(): void
    {
        $product = $this->product();
        $owner = $this->owner();

        for ($number = 1; $number <= 10; $number++) {
            $this->actingAs($owner)
                ->post($this->url($product), ['images' => [$this->image("foto-{$number}.png")]])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($owner)
            ->post($this->url($product), ['images' => [$this->image('undecima.png')]])
            ->assertSessionHasErrors('images');

        $this->assertSame(10, $this->imagesOf($product)->count());
    }

    /**
     * RB-1 y SP-2 · 8 MB por archivo.
     */
    public function test_a_file_over_eight_megabytes_is_rejected(): void
    {
        $product = $this->product();

        $heavy = UploadedFile::fake()->create('pesada.png', 9 * 1024, 'image/png');

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$heavy]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->imagesOf($product)->count());
    }

    /**
     * RB-1 y SP-3 · el MIME real, no la extensión.
     */
    public function test_a_file_that_is_not_an_image_is_rejected(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->fileWith('malicioso.png', '<?php echo 1;')]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->imagesOf($product)->count());
    }

    /**
     * RB-1 y RB-3 · **las conversiones se encolan**, nunca se hacen en el
     * request de subida.
     */
    public function test_the_conversions_are_queued(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertSessionHasNoErrors();

        Bus::assertDispatched(TranscodeProductImage::class);
    }

    /**
     * **RB-3 de `REQ-CAT-005` · mientras la conversión no exista se sirve el
     * original.**
     *
     * Es la regla que hace que un producto publicado funcione con la cola
     * parada o con un job caído: nunca un hueco roto. Las tres URL apuntan al
     * original y el formulario lo dice con «Procesando».
     */
    public function test_an_image_without_conversions_serves_the_original(): void
    {
        $media = $this->uploadTo($this->organization);

        $set = ImageSet::of($media);

        $this->assertSame($media->getUrl(), $set->thumb);
        $this->assertSame($media->getUrl(), $set->card);
        $this->assertSame($media->getUrl(), $set->zoom);
    }

    /**
     * **`REQ-CAT-005` · las tres conversiones se generan de verdad**, en WebP,
     * y a partir de ahí cada tamaño sirve la suya en lugar del original.
     *
     * El job se ejecuta aquí a mano porque la cola está falseada: lo que se
     * comprueba no es el encolado —eso es el caso de arriba— sino que el
     * trabajo produce los tres archivos que `REQ-CAT-005` declara.
     */
    public function test_the_three_conversions_are_generated(): void
    {
        $media = $this->uploadTo($this->organization);

        (new TranscodeProductImage(ConversionCollection::createForMedia($media), $media))
            ->handle(app(FileManipulator::class));

        $media->refresh();

        foreach (array_keys(ImageSet::CONVERSIONS) as $conversion) {
            $this->assertTrue(
                $media->hasGeneratedConversion($conversion),
                "La conversión «{$conversion}» no se generó.",
            );
        }

        $set = ImageSet::of($media);

        $this->assertStringContainsString('thumb', $set->thumb);
        $this->assertStringContainsString('card', $set->card);
        $this->assertStringContainsString('zoom', $set->zoom);
        $this->assertNotSame($media->getUrl(), $set->zoom);
    }

    /**
     * SP-6 · un job de conversión que falla deja la imagen **utilizable** y
     * marcada para revisión. No se borra el medio ni se despublica nada: un
     * catálogo que se apaga cuando cae un *worker* es peor que una foto sin
     * optimizar.
     */
    public function test_a_failed_conversion_marks_the_image_for_review(): void
    {
        $media = $this->uploadTo($this->organization);

        $this->assertFalse(ImageSet::needsReview($media));

        $job = new TranscodeProductImage(
            ConversionCollection::createForMedia($media),
            $media,
        );

        $job->failed(new \RuntimeException('el worker se quedó sin memoria'));

        $this->assertTrue(ImageSet::needsReview($media->refresh()));
        $this->assertNotSame('', ImageSet::of($media)->thumb);
    }

    /**
     * `REQ-CAT-005` RB-4 · el `alt` por defecto nunca deja una imagen sin
     * describir.
     */
    public function test_the_default_alt_names_the_product_and_the_number(): void
    {
        $product = $this->product(['name' => 'Taladro Bosch']);
        $owner = $this->owner();

        foreach (['una.png', 'dos.png'] as $name) {
            $this->actingAs($owner)->post($this->url($product), ['images' => [$this->image($name)]]);
        }

        $alts = $this->imagesOf($product)->map(fn (Media $media): string => ImageSet::of($media)->alt)->all();

        $this->assertSame(['Taladro Bosch — imagen 1', 'Taladro Bosch — imagen 2'], $alts);
    }

    /**
     * RB-7 · **borrar borra el archivo del disco**, que es lo contrario de lo
     * que pasa con un comprobante (`REQ-PAY-002` RB-8): una foto de catálogo
     * es material del negocio; un comprobante es la prueba de un pago ajeno.
     */
    public function test_deleting_an_image_removes_the_file(): void
    {
        $media = $this->uploadTo($this->organization);
        $path = sprintf(
            'organizations/%d/media/%s/%s',
            $this->organization->getKey(),
            $media->getKey(),
            $media->file_name,
        );

        Storage::disk('public')->assertExists($path);

        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->actingAs($this->owner())
            ->delete("/backoffice/productos/{$product->getKey()}/imagenes/{$media->getKey()}")
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, $this->imagesOf($product)->count());
    }

    /**
     * `REQ-SUB-003` RB-5 · subir y borrar vuelven a sumar `storage_mb` en el
     * acto: unos bytes ya son un mega empezado, y sin imágenes, cero.
     */
    public function test_uploading_and_deleting_an_image_move_the_storage_counter(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->storageCounter());

        $media = $this->imagesOf($product)->sole();

        $this->actingAs($this->owner())
            ->delete("/backoffice/productos/{$product->getKey()}/imagenes/{$media->getKey()}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->storageCounter());
    }

    private function storageCounter(): int
    {
        return (int) DB::table('usage_counters')
            ->where('organization_id', $this->organization->getKey())
            ->where('metric', 'storage_mb')
            ->value('value');
    }

    /**
     * **RB-4 · borrar la principal promueve a la siguiente**, sin dejar hueco.
     *
     * No hay ninguna bandera «es la principal» que actualizar: la principal es
     * la primera, así que renumerar de 1 a n **es** la promoción.
     */
    public function test_deleting_the_main_image_promotes_the_next_one(): void
    {
        $product = $this->product();
        $owner = $this->owner();

        foreach (['una.png', 'dos.png', 'tres.png'] as $name) {
            $this->actingAs($owner)->post($this->url($product), ['images' => [$this->image($name)]]);
        }

        $first = $this->imagesOf($product)->first();

        $this->actingAs($owner)
            ->delete("/backoffice/productos/{$product->getKey()}/imagenes/{$first?->getKey()}")
            ->assertSessionHasNoErrors();

        $remaining = $this->imagesOf($product);

        $this->assertSame([1, 2], $remaining->map(fn (Media $media): int => (int) $media->order_column)->all());
        $this->assertSame('dos.png', $remaining->first()?->file_name);
    }

    /**
     * **SP-4 · borrar la única foto de un producto publicado es `422`.**
     *
     * No es la comprobación de completitud de `REQ-BOF-020` RB-5 —que se hace
     * **al publicar** y mira cuatro cosas—: es la misma invariante vista desde
     * el momento contrario, cuando el producto ya está publicado y visible.
     */
    public function test_deleting_the_only_image_of_a_published_product_is_rejected(): void
    {
        $media = $this->uploadTo($this->organization, ['status' => 'published']);
        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->actingAs($this->owner())
            ->delete("/backoffice/productos/{$product->getKey()}/imagenes/{$media->getKey()}")
            ->assertSessionHasErrors('images');

        $this->assertSame(1, $this->imagesOf($product)->count());
    }

    /**
     * Y en borrador **sí** se permite: un borrador puede estar incompleto por
     * definición.
     */
    public function test_deleting_the_only_image_of_a_draft_is_allowed(): void
    {
        $media = $this->uploadTo($this->organization);
        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->actingAs($this->owner())
            ->delete("/backoffice/productos/{$product->getKey()}/imagenes/{$media->getKey()}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->imagesOf($product)->count());
    }

    /**
     * La pieza que `T-030` necesita: el producto sabe contestar si tiene
     * fotos. La respuesta a «¿se puede publicar?» es de aquella tarea.
     */
    public function test_the_product_knows_whether_it_has_images(): void
    {
        $product = $this->product();

        $this->assertFalse($product->hasImages());

        $this->actingAs($this->owner())->post($this->url($product), ['images' => [$this->image('foto.png')]]);

        $this->assertTrue($this->inTenant(fn (): bool => Product::query()->sole()->hasImages()));
    }

    /**
     * SP-9 · el producto de otra organización da `404`, no `403`.
     */
    public function test_a_product_of_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Product => Product::factory()->create(),
        );

        $this->actingAs($this->owner())
            ->post($this->url($foreign), ['images' => [$this->image('foto.png')]])
            ->assertNotFound();
    }

    /**
     * RB-11 de `REQ-BOF-020` · con la organización suspendida tampoco se
     * suben fotos: el inventario espera a que se regularice el pago.
     */
    public function test_a_suspended_organization_cannot_upload(): void
    {
        $product = $this->product();

        $this->organization->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertForbidden();
    }

    private function url(Product $product): string
    {
        return "/backoffice/productos/{$product->getKey()}/imagenes";
    }

    /**
     * Sube una imagen dentro de esa organización y devuelve su `media`.
     *
     * @param  array<string, mixed>  $attributes  del producto que la recibe
     */
    private function uploadTo(Organization $organization, array $attributes = []): Media
    {
        $this->subscribeWithoutLimits($organization);

        $product = $this->withinOrganization(
            $organization,
            fn (): Product => Product::factory()->create($attributes),
        );

        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        // El `TenantContext` es un singleton que sobrevive entre peticiones
        // dentro de un test, y `setOrganization()` **lanza** al intentar
        // cambiar de organización (`TenantAlreadySetException`). En la
        // aplicación real cada petición es un proceso limpio; aquí hay que
        // vaciarlo a mano antes de entrar como alguien de otro negocio.
        $this->withoutOrganization();

        $this->actingAs($owner)
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertRedirect("/backoffice/productos/{$product->getKey()}/editar");

        return $this->withinOrganization(
            $organization,
            fn (): Media => $product->fresh()?->getMedia(Product::IMAGES)->sole() ?? throw new \RuntimeException('sin media'),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return $this->inTenant(fn (): Product => Product::factory()->create($attributes));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Media>
     */
    private function imagesOf(Product $product): \Illuminate\Support\Collection
    {
        return $this->inTenant(
            fn (): \Illuminate\Support\Collection => ($product->fresh() ?? $product)->getMedia(Product::IMAGES),
        );
    }

    /**
     * Un PNG de verdad, no un doble: el doble de Laravel deduce su tipo del
     * nombre, y eso es justo lo que `RealMimeType` no hace.
     */
    private function image(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function fileWith(string $name, string $contents): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'image/png', null, true);
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
