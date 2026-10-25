<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\SyncImageOrderAction;
use App\Domain\Catalog\ImageSet;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SyncImageOrderActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([TranscodeProductImage::class]);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_orders_the_gallery_from_one(): void
    {
        $product = $this->productWith(3);
        $ids = $this->idsOf($product);

        $this->sync()->handle($product, [
            ['id' => $ids[2]],
            ['id' => $ids[0]],
            ['id' => $ids[1]],
        ], $this->actor);

        $this->assertSame([$ids[2], $ids[0], $ids[1]], $this->idsOf($product));
        $this->assertSame([1, 2, 3], $this->positionsOf($product));
    }

    public function test_the_last_order_wins(): void
    {
        $product = $this->productWith(3);
        $ids = $this->idsOf($product);

        $this->sync()->handle($product, [['id' => $ids[2]], ['id' => $ids[1]], ['id' => $ids[0]]], $this->actor);
        $this->sync()->handle($product, [['id' => $ids[1]], ['id' => $ids[0]], ['id' => $ids[2]]], $this->actor);

        $this->assertSame([$ids[1], $ids[0], $ids[2]], $this->idsOf($product));
    }

    public function test_what_is_missing_from_the_list_goes_last(): void
    {
        $product = $this->productWith(3);
        $ids = $this->idsOf($product);

        $this->sync()->handle($product, [['id' => $ids[2]]], $this->actor);

        $this->assertSame([$ids[2], $ids[0], $ids[1]], $this->idsOf($product));
        $this->assertSame([1, 2, 3], $this->positionsOf($product));
    }

    public function test_a_repeated_identifier_only_counts_once(): void
    {
        $product = $this->productWith(2);
        $ids = $this->idsOf($product);

        $this->sync()->handle($product, [['id' => $ids[1]], ['id' => $ids[1]], ['id' => $ids[0]]], $this->actor);

        $this->assertSame([$ids[1], $ids[0]], $this->idsOf($product));
        $this->assertSame([1, 2], $this->positionsOf($product));
    }

    public function test_an_image_of_another_organization_is_rejected(): void
    {
        $product = $this->productWith(2);

        $foreign = $this->withinOrganization($this->neighbourOrganization(), function (): Media {
            $theirs = Product::factory()->create();

            return $this->attach($theirs, 'ajena.png');
        });

        $this->actingAsOrganization($this->organization);

        $this->expectException(CrossTenantRelationException::class);

        $this->sync()->handle($product, [['id' => (int) $foreign->getKey()]], $this->actor);
    }

    public function test_an_image_of_another_product_is_rejected(): void
    {
        $product = $this->productWith(1);
        $another = $this->productWith(1, 'B-');

        $foreign = $another->getMedia(Product::IMAGES)->sole();

        $this->expectException(CrossTenantRelationException::class);

        $this->sync()->handle($product, [['id' => (int) $foreign->getKey()]], $this->actor);
    }

    public function test_it_saves_the_alternative_text(): void
    {
        $product = $this->productWith(1);
        $id = $this->idsOf($product)[0];

        $this->sync()->handle($product, [['id' => $id, 'alt' => '  Taladro visto de frente  ']], $this->actor);

        $this->assertSame('Taladro visto de frente', ImageSet::of($this->mediaOf($product)[0])->alt);
    }

    public function test_clearing_the_alternative_text_restores_the_default(): void
    {
        $product = $this->productWith(1, 'A-', 'Taladro Bosch');
        $id = $this->idsOf($product)[0];

        $this->sync()->handle($product, [['id' => $id, 'alt' => 'Uno propio']], $this->actor);
        $this->sync()->handle($product, [['id' => $id, 'alt' => '']], $this->actor);

        $this->assertSame('Taladro Bosch — imagen 1', ImageSet::of($this->mediaOf($product)[0])->alt);
    }

    public function test_an_empty_list_only_compacts(): void
    {
        $product = $this->productWith(2);

        $this->mediaOf($product)[0]->forceFill(['order_column' => 40])->save();

        $this->sync()->handle($product, [], $this->actor);

        $this->assertSame([1, 2], $this->positionsOf($product));
    }

    public function test_a_member_may_order_the_gallery(): void
    {
        $product = $this->productWith(2);
        $member = User::factory()->create();

        $this->sync()->handle($product, [], $member);

        $this->assertSame([1, 2], $this->positionsOf($product));
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $product = $this->productWith(1);

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->sync()->handle($product, [], $foreignOwner);
    }

    private function productWith(int $count, string $skuPrefix = 'A-', string $name = 'Taladro Bosch'): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'sku' => $skuPrefix.uniqid(),
            'slug' => $skuPrefix.uniqid(),
        ]);

        for ($number = 1; $number <= $count; $number++) {
            $this->attach($product, "foto-{$number}.png");
        }

        return $product->refresh();
    }

    private function attach(Product $product, string $name): Media
    {
        $position = $product->getMedia(Product::IMAGES)->count() + 1;

        return $product
            ->addMedia($this->image($name))
            ->usingName(ImageSet::defaultAlt($product->name, $position))
            ->withCustomProperties([
                TenantPathGenerator::ORGANIZATION_PROPERTY => $product->organization_id,
            ])
            ->toMediaCollection(Product::IMAGES);
    }

    private function image(string $name): \Illuminate\Http\UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

        imagepng($image, $path);

        return new \Illuminate\Http\UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * @return list<Media>
     */
    private function mediaOf(Product $product): array
    {
        return ($product->fresh() ?? $product)->getMedia(Product::IMAGES)->values()->all();
    }

    /**
     * @return list<int>
     */
    private function idsOf(Product $product): array
    {
        return array_map(fn (Media $media): int => (int) $media->getKey(), $this->mediaOf($product));
    }

    /**
     * @return list<int>
     */
    private function positionsOf(Product $product): array
    {
        return array_map(fn (Media $media): int => (int) $media->order_column, $this->mediaOf($product));
    }

    private function sync(): SyncImageOrderAction
    {
        return app(SyncImageOrderAction::class);
    }
}
