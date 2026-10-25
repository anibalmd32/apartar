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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TranscodeProductImageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
    }

    public function test_it_takes_its_organization_from_the_media(): void
    {
        $media = $this->uploadedMedia();

        $this->assertSame((int) $this->organization->getKey(), $this->jobFor($media)->tenantOrganizationId());
    }

    public function test_a_media_without_its_organization_fails_loudly(): void
    {
        $media = $this->uploadedMedia();

        $media->forgetCustomProperty(TenantPathGenerator::ORGANIZATION_PROPERTY);
        $media->save();

        $this->expectException(RuntimeException::class);

        $this->jobFor($media)->tenantOrganizationId();
    }

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

    public function test_it_clears_the_tenant_when_the_conversion_throws(): void
    {
        $media = $this->uploadedMedia();

        $this->withoutOrganization();
        $context = app(TenantContext::class);

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

    public function test_an_unknown_organization_fails_to_resolve(): void
    {
        $media = $this->uploadedMedia();

        $media->setCustomProperty(TenantPathGenerator::ORGANIZATION_PROPERTY, 9_999)->save();

        $this->withoutOrganization();

        $this->expectException(ModelNotFoundException::class);

        $this->jobFor($media)->handle(app(FileManipulator::class));
    }

    public function test_a_failure_marks_the_image_for_review_without_breaking_it(): void
    {
        $media = $this->uploadedMedia();

        $this->jobFor($media)->failed(new RuntimeException('conversión fallida'));

        $media->refresh();

        $this->assertTrue(ImageSet::needsReview($media));
        $this->assertNotSame('', ImageSet::of($media)->thumb);
    }

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
