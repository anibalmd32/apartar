<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\AttachProductImagesAction;
use App\Domain\Catalog\ImageSet;
use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\Support\ImageUploads;
use Tests\TestCase;

final class AttachProductImagesActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([TranscodeProductImage::class]);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();
    }

    public function test_it_stores_every_file_in_order_under_the_organization(): void
    {
        $product = Product::factory()->create(['name' => 'Silla Eames']);

        app(AttachProductImagesAction::class)->handle($product, [$this->image('una.png'), $this->image('dos.png')]);

        $images = ($product->fresh() ?? $product)->getMedia(Product::IMAGES);

        $this->assertSame(['una.png', 'dos.png'], $images->map(fn (Media $media): string => $media->file_name)->all());
        $this->assertSame(
            ['Silla Eames — imagen 1', 'Silla Eames — imagen 2'],
            $images->map(fn (Media $media): string => ImageSet::of($media)->alt)->all(),
        );
        $this->assertSame(
            (int) $this->organization->getKey(),
            $images->first()?->getCustomProperty(TenantPathGenerator::ORGANIZATION_PROPERTY),
        );

        Bus::assertDispatched(TranscodeProductImage::class);
    }

    public function test_it_moves_the_storage_counter(): void
    {
        $product = Product::factory()->create();

        app(AttachProductImagesAction::class)->handle($product, [$this->image('una.png')]);

        $this->assertSame(1, (int) DB::table('usage_counters')
            ->where('organization_id', $this->organization->getKey())
            ->where('metric', 'storage_mb')
            ->value('value'));
    }

    public function test_the_stored_original_loses_the_photos_metadata_but_keeps_its_orientation(): void
    {
        $product = Product::factory()->create();

        app(AttachProductImagesAction::class)->handle($product, [ImageUploads::real('foto.jpg', ImageUploads::jpegWithMetadata(6))]);

        $media = ($product->fresh() ?? $product)->getFirstMedia(Product::IMAGES);
        $this->assertInstanceOf(Media::class, $media);

        $stored = (string) Storage::disk('public')->get($media->getPathRelativeToRoot());
        $path = (string) tempnam(sys_get_temp_dir(), 'apartar-exif-');
        file_put_contents($path, $stored);
        $exif = @exif_read_data($path);
        unlink($path);

        $this->assertSame([], ImageUploads::personalTraces($stored));
        $this->assertSame(6, is_array($exif) ? ($exif['Orientation'] ?? null) : null);
        $this->assertSame(strlen($stored), (int) $media->size);
    }

    private function image(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
