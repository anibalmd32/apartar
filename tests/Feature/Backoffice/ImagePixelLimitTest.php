<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Ai\Agents\ProductCopywriter;
use App\Jobs\TranscodeProductImage;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\Support\ImageUploads;
use Tests\TestCase;

final class ImagePixelLimitTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const MESSAGE = 'La imagen pasa de 60 megapíxeles. Tómala con la resolución normal de la cámara o redúcela antes de subirla.';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([TranscodeProductImage::class]);

        config(['ai.providers.deepseek.key' => 'clave-de-prueba', 'ai.daily_limit_per_organization' => 30]);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($this->organization);
        $this->withoutOrganization();

        $this->withHeaders(['Accept' => 'application/json']);
    }

    public function test_a_product_created_with_a_giant_photo_is_rejected_before_saving(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->productPayload(['images' => [$this->giant()]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0' => self::MESSAGE]);

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_adding_a_giant_photo_to_a_product_is_rejected_before_saving(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/productos/{$product->getKey()}/imagenes", ['images' => [$this->giant()]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0' => self::MESSAGE]);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_ai_does_not_receive_a_giant_photo(): void
    {
        ProductCopywriter::fake();

        $this->actingAs($this->owner())
            ->post('/backoffice/productos/ia/sugerencia', ['mode' => 'suggest', 'name' => 'Silla', 'images' => [$this->giant()]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0' => self::MESSAGE]);

        ProductCopywriter::assertNeverPrompted();
    }

    public function test_a_giant_catalog_cover_is_rejected_before_saving(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/catalogos/{$catalog->getKey()}", ['_method' => 'put', 'name' => $catalog->name, 'cover' => $this->giant()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cover' => self::MESSAGE]);

        $this->assertNull($catalog->refresh()->cover_path);
    }

    public function test_a_fifty_megapixel_phone_photo_still_goes_in(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/productos/{$product->getKey()}/imagenes", ['images' => [$this->photo(8160, 6144)]])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->inTenant(fn () => $product->fresh()?->getMedia(Product::IMAGES)) ?? []);
    }

    private function giant(): UploadedFile
    {
        return $this->photo(8000, 8000);
    }

    private function photo(int $width, int $height): UploadedFile
    {
        return ImageUploads::real('foto.png', ImageUploads::pngHeader($width, $height));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
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
