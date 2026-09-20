<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Catalog\ImageSet;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * El job de conversión y su tenant (`REQ-CAT-005` RB-1, SP-1;
 * `REQ-ORG-002` RB-5 y SP-7).
 *
 * Vive en la suite de tenancy porque lo que sostiene no es la conversión: es
 * que **un job no hereda la organización de quien lo encoló**. Entre encolar y
 * ejecutar pasan minutos y el *worker* es otro proceso; lo único que podría
 * heredar es lo que dejó puesto el job anterior, que sería la fuga más difícil
 * de detectar del diseño.
 */
final class TranscodeProductImageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
    }

    /**
     * La organización sale del propio medio, que es la misma fuente de la que
     * `TenantPathGenerator` compone la ruta: **una sola declaración** de a
     * quién pertenece el archivo.
     */
    public function test_it_takes_its_organization_from_the_media(): void
    {
        $media = $this->uploadedMedia();

        $this->assertSame((int) $this->organization->getKey(), $this->jobFor($media)->tenantOrganizationId());
    }

    /**
     * Un medio sin esa declaración **lanza** en vez de convertir sin tenant
     * fijado: es preferible un job caído a uno que trabaja sobre la fila
     * equivocada.
     */
    public function test_a_media_without_its_organization_fails_loudly(): void
    {
        $media = $this->uploadedMedia();

        $media->forgetCustomProperty(TenantPathGenerator::ORGANIZATION_PROPERTY);
        $media->save();

        $this->expectException(RuntimeException::class);

        $this->jobFor($media)->tenantOrganizationId();
    }

    /**
     * **RB-5 · el job fija su tenant y lo limpia al terminar.**
     *
     * Se observa desde dentro del trabajo de verdad: medialibrary guarda el
     * `Media` cuando anota las conversiones generadas, y ese momento ocurre
     * **dentro** del `handle()`. Lo que se mira ahí es la organización activa;
     * lo que se mira después es que no quede ninguna, porque una cola
     * compartida procesa jobs de dos negocios seguidos.
     */
    public function test_it_runs_with_its_tenant_and_clears_it_afterwards(): void
    {
        $media = $this->uploadedMedia();

        $this->withoutOrganization();
        $context = app(TenantContext::class);

        $seen = null;

        Media::saved(function () use ($context, &$seen): void {
            $seen ??= $context->hasOrganization() ? $context->organizationId() : null;
        });

        $this->jobFor($media)->handle(app(FileManipulator::class));

        $this->assertSame((int) $this->organization->getKey(), $seen, 'El job corrió sin su organización fijada.');
        $this->assertFalse($context->hasOrganization(), 'El job dejó el tenant puesto.');
    }

    /**
     * Y lo limpia **también cuando lanza**: sin eso, un fallo convirtiendo la
     * foto de un negocio contaminaría el job siguiente, y el segundo fallo no
     * se parecería en nada al primero.
     */
    public function test_it_clears_the_tenant_when_the_conversion_throws(): void
    {
        $media = $this->uploadedMedia();

        $this->withoutOrganization();
        $context = app(TenantContext::class);

        // El fallo se provoca dentro del trabajo, que es donde ocurriría de
        // verdad: un disco lleno, un archivo corrupto o un worker sin memoria.
        Media::saved(function (): void {
            throw new RuntimeException('el worker se quedó sin memoria');
        });

        try {
            $this->jobFor($media)->handle(app(FileManipulator::class));
            $this->fail('Se esperaba que el job lanzara.');
        } catch (RuntimeException $exception) {
            $this->assertSame('el worker se quedó sin memoria', $exception->getMessage());
        }

        $this->assertFalse($context->hasOrganization());
    }

    /**
     * Si el payload trae una organización que ya no existe, el job falla al
     * resolverla y no llega a tocar nada.
     */
    public function test_an_unknown_organization_fails_to_resolve(): void
    {
        $media = $this->uploadedMedia();

        $media->setCustomProperty(TenantPathGenerator::ORGANIZATION_PROPERTY, 9_999)->save();

        $this->withoutOrganization();

        $this->expectException(ModelNotFoundException::class);

        $this->jobFor($media)->handle(app(FileManipulator::class));
    }

    /**
     * SP-1 y SP-6 · **la conversión que falla no deja el producto
     * inservible**: la imagen queda marcada para revisión y sigue sirviéndose
     * con su original. Un catálogo que se apaga cuando cae un *worker* es peor
     * que una foto sin optimizar.
     */
    public function test_a_failure_marks_the_image_for_review_without_breaking_it(): void
    {
        $media = $this->uploadedMedia();

        $this->jobFor($media)->failed(new RuntimeException('conversión fallida'));

        $media->refresh();

        $this->assertTrue(ImageSet::needsReview($media));
        $this->assertNotSame('', ImageSet::of($media)->thumb);
    }

    /**
     * Un medio subido, con su organización declarada como lo hace el
     * controlador.
     */
    private function uploadedMedia(): Media
    {
        return $this->withinOrganization($this->organization, function (): Media {
            $product = Product::factory()->create();

            return $product
                ->addMedia($this->image())
                ->withCustomProperties([
                    TenantPathGenerator::ORGANIZATION_PROPERTY => $this->organization->getKey(),
                ])
                ->toMediaCollection(Product::IMAGES);
        });
    }

    /**
     * El job real, con las conversiones que le tocan a ese medio.
     *
     * Sin dobles ni subclases: lo que se prueba es lo que el *worker* va a
     * ejecutar, y el momento observable —cuando medialibrary guarda el
     * `Media`— ocurre dentro.
     */
    private function jobFor(Media $media): TranscodeProductImage
    {
        return new TranscodeProductImage(ConversionCollection::createForMedia($media), $media);
    }

    private function image(): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-foto.png';

        imagepng($image, $path);

        return new UploadedFile($path, 'foto.png', 'image/png', null, true);
    }
}
