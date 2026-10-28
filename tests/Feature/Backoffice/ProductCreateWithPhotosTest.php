<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Http\Requests\Backoffice\StoreProductImagesRequest;
use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProductCreateWithPhotosTest extends TestCase
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
        $this->subscribeWithoutLimits($this->organization);
        $this->withoutOrganization();
    }

    public function test_the_photos_picked_before_creating_are_stored_on_the_new_product(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload(['images' => [$this->image('frente.png'), $this->image('lado.png')]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $images = $this->inTenant(fn () => Product::query()->sole()->getMedia(Product::IMAGES));

        $this->assertSame(['frente.png', 'lado.png'], $images->map(fn (Media $media): string => $media->file_name)->all());
        $this->assertStringContainsString('organizations/'.$this->organization->getKey().'/', (string) $images->first()?->getPath());

        Bus::assertDispatched(TranscodeProductImage::class);
    }

    public function test_creating_without_photos_still_works(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->inTenant(fn (): bool => Product::query()->sole()->hasImages()));
    }

    public function test_a_file_that_is_not_an_image_rejects_the_whole_product(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'.png';
        file_put_contents($path, '<?php echo 1;');

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload(['images' => [new UploadedFile($path, 'malo.png', 'image/png', null, true)]]))
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    public function test_more_photos_than_a_product_admits_are_rejected(): void
    {
        $files = [];

        for ($number = 0; $number <= StoreProductImagesRequest::MAX_IMAGES; $number++) {
            $files[] = $this->image("foto-{$number}.png");
        }

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload(['images' => $files]))
            ->assertSessionHasErrors(['images' => 'Un producto admite 10 imágenes.']);
    }

    public function test_editing_ignores_photos_sent_with_the_form(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create(['sku' => 'BOSCH-01']));

        $this->actingAs($this->owner())
            ->put("/backoffice/productos/{$product->getKey()}", $this->payload(['images' => [$this->image('foto.png')]]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->inTenant(fn (): bool => ($product->fresh() ?? $product)->hasImages()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Taladro Bosch',
            'sku' => 'BOSCH-01',
            'description' => 'Un taladro.',
            'price' => '250.00',
            'compare_at_price' => '',
            'down_payment' => '50.00',
            'installments_count' => 4,
            'frequency_days' => 15,
            'stock' => 10,
        ], $overrides);
    }

    private function image(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

        imagepng($image, $path);

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
