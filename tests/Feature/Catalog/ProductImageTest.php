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

final class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Bus::fake([TranscodeProductImage::class]);

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
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

    public function test_the_stored_path_includes_the_organization(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertSessionHasNoErrors();

        $media = $this->imagesOf($product)->sole();

        $this->assertStringContainsString(
            sprintf('organizations/%d/media/%s/', $this->organization->getKey(), $media->getKey()),
            $media->getPath(),
        );

        Storage::disk('public')->assertExists(
            sprintf('organizations/%d/media/%s/%s', $this->organization->getKey(), $media->getKey(), $media->file_name),
        );
    }

    public function test_two_organizations_do_not_share_a_directory(): void
    {
        $neighbour = $this->neighbourOrganization();

        $mine = $this->uploadTo($this->organization);
        $theirs = $this->uploadTo($neighbour);

        $this->assertStringContainsString('organizations/'.$this->organization->getKey().'/', $mine->getPath());
        $this->assertStringContainsString('organizations/'.$neighbour->getKey().'/', $theirs->getPath());
        $this->assertNotSame(dirname($mine->getPath()), dirname($theirs->getPath()));
    }

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

    public function test_a_file_over_eight_megabytes_is_rejected(): void
    {
        $product = $this->product();

        $heavy = UploadedFile::fake()->create('pesada.png', 9 * 1024, 'image/png');

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$heavy]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->imagesOf($product)->count());
    }

    public function test_a_file_that_is_not_an_image_is_rejected(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->fileWith('malicioso.png', '<?php echo 1;')]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->imagesOf($product)->count());
    }

    public function test_the_conversions_are_queued(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner())
            ->post($this->url($product), ['images' => [$this->image('foto.png')]])
            ->assertSessionHasNoErrors();

        Bus::assertDispatched(TranscodeProductImage::class);
    }

    public function test_an_image_without_conversions_serves_the_original(): void
    {
        $media = $this->uploadTo($this->organization);

        $set = ImageSet::of($media);

        $this->assertSame($media->getUrl(), $set->thumb);
        $this->assertSame($media->getUrl(), $set->card);
        $this->assertSame($media->getUrl(), $set->zoom);
    }

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

    public function test_deleting_the_only_image_of_a_published_product_is_rejected(): void
    {
        $media = $this->uploadTo($this->organization, ['status' => 'published']);
        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->actingAs($this->owner())
            ->delete("/backoffice/productos/{$product->getKey()}/imagenes/{$media->getKey()}")
            ->assertSessionHasErrors('images');

        $this->assertSame(1, $this->imagesOf($product)->count());
    }

    public function test_deleting_the_only_image_of_a_draft_is_allowed(): void
    {
        $media = $this->uploadTo($this->organization);
        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->actingAs($this->owner())
            ->delete("/backoffice/productos/{$product->getKey()}/imagenes/{$media->getKey()}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->imagesOf($product)->count());
    }

    public function test_the_product_knows_whether_it_has_images(): void
    {
        $product = $this->product();

        $this->assertFalse($product->hasImages());

        $this->actingAs($this->owner())->post($this->url($product), ['images' => [$this->image('foto.png')]]);

        $this->assertTrue($this->inTenant(fn (): bool => Product::query()->sole()->hasImages()));
    }

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
     * @param  array<string, mixed>  $attributes
     */
    private function uploadTo(Organization $organization, array $attributes = []): Media
    {
        $this->subscribeWithoutLimits($organization);

        $product = $this->withinOrganization(
            $organization,
            fn (): Product => Product::factory()->create($attributes),
        );

        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

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
