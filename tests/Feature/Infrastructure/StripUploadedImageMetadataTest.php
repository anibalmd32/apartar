<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\Support\ImageUploads;
use Tests\TestCase;

final class StripUploadedImageMetadataTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([TranscodeProductImage::class]);

        $organization = Organization::factory()->create();

        $this->media = $this->withinOrganization($organization, fn (): Media => Product::factory()->create()
            ->addMedia(ImageUploads::real('silla.jpg', ImageUploads::jpegWithMetadata()))
            ->withCustomProperties([TenantPathGenerator::ORGANIZATION_PROPERTY => $organization->getKey()])
            ->toMediaCollection(Product::IMAGES));

        $this->withoutOrganization();

        Storage::disk('public')->put('logos/7/logo.png', ImageUploads::pngWithMetadata());
        Storage::disk('public')->put('catalogos/7/portada.webp', ImageUploads::webpWithMetadata());
    }

    public function test_limpia_los_originales_de_producto_los_logos_y_las_portadas_ya_subidos(): void
    {
        $this->artisan('uploads:strip-metadata')->assertSuccessful();

        $original = (string) Storage::disk('public')->get($this->media->getPathRelativeToRoot());

        $this->assertSame([], ImageUploads::personalTraces($original));
        $this->assertSame([], ImageUploads::personalTraces((string) Storage::disk('public')->get('logos/7/logo.png')));
        $this->assertSame([], ImageUploads::personalTraces((string) Storage::disk('public')->get('catalogos/7/portada.webp')));
        $this->assertSame(strlen($original), (int) $this->media->refresh()->size);

        $this->artisan('uploads:strip-metadata')
            ->expectsTable(['Revisadas', 'Limpiadas', 'Fallidas'], [[3, 0, 0]])
            ->assertSuccessful();
    }

    public function test_en_simulacion_cuenta_lo_que_limpiaria_sin_tocar_nada(): void
    {
        $this->artisan('uploads:strip-metadata', ['--dry-run' => true])
            ->expectsTable(['Revisadas', 'Con metadatos', 'Fallidas'], [[3, 3, 0]])
            ->assertSuccessful();

        $this->assertContains('GPS', ImageUploads::personalTraces(
            (string) Storage::disk('public')->get($this->media->getPathRelativeToRoot()),
        ));
    }

    public function test_un_archivo_danado_se_reporta_sin_frenar_a_los_demas(): void
    {
        Storage::disk('public')->put('logos/8/roto.jpg', "\xFF\xD8\xFF\xE1\x40\x00Exif");

        $this->artisan('uploads:strip-metadata')
            ->expectsOutputToContain('logos/8/roto.jpg')
            ->assertFailed();

        $this->assertSame([], ImageUploads::personalTraces((string) Storage::disk('public')->get('catalogos/7/portada.webp')));
    }
}
