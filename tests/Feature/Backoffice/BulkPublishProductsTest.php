<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Jobs\TranscodeProductImage;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Media\TenantPathGenerator;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class BulkPublishProductsTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const URL = '/backoffice/productos/publicar-varios';

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

    public function test_it_publishes_the_drafts_and_flashes_a_summary(): void
    {
        [$taladro, $mochila] = $this->inTenant(fn (): array => [
            $this->publishable('Taladro'),
            $this->publishable('Mochila', withImage: false),
        ]);

        $this->actingAs($this->owner())
            ->from('/backoffice/productos?status=draft')
            ->post(self::URL, ['ids' => [$taladro->getKey(), $mochila->getKey()]])
            ->assertRedirect('/backoffice/productos?status=draft')
            ->assertSessionHas(
                'status',
                'Publicaste 1 producto. 1 no se pudo publicar: «Mochila» (le falta: al menos una foto).',
            );

        $this->assertSame(ProductStatus::Published, $taladro->refresh()->status);
        $this->assertSame(ProductStatus::Draft, $mochila->refresh()->status);
    }

    public function test_foreign_ids_do_not_reveal_or_touch_anything(): void
    {
        $neighbour = $this->neighbourOrganization();
        $foreign = $this->withinOrganization($neighbour, fn (): Product => Product::factory()->create());

        $this->actingAs($this->owner())
            ->from('/backoffice/productos')
            ->post(self::URL, ['ids' => [$foreign->getKey()]])
            ->assertRedirect('/backoffice/productos')
            ->assertSessionHas('status', 'No se publicó ningún producto. 1 ya no estaba en borrador y se quedó como estaba.');

        $this->assertSame(
            ProductStatus::Draft,
            $this->withinOrganization($neighbour, fn (): ProductStatus => $foreign->refresh()->status),
        );
    }

    public function test_the_ids_are_validated(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->from('/backoffice/productos')->post(self::URL, [])->assertSessionHasErrors('ids');
        $this->actingAs($owner)->from('/backoffice/productos')->post(self::URL, ['ids' => ['uno']])->assertSessionHasErrors('ids.0');
        $this->actingAs($owner)
            ->from('/backoffice/productos')
            ->post(self::URL, ['ids' => range(1, 101)])
            ->assertSessionHasErrors(['ids' => 'Puedes publicar hasta 100 productos de una vez.']);
    }

    public function test_a_member_may_publish_in_bulk(): void
    {
        $product = $this->inTenant(fn (): Product => $this->publishable('Taladro'));
        $member = $this->inTenant(fn (): User => User::factory()->create());

        $this->actingAs($member)->from('/backoffice/productos')->post(self::URL, ['ids' => [$product->getKey()]])->assertRedirect();

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
    }

    public function test_a_suspended_organization_cannot_publish_in_bulk(): void
    {
        $product = $this->inTenant(fn (): Product => $this->publishable('Taladro'));

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner())->post(self::URL, ['ids' => [$product->getKey()]])->assertForbidden();

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->post(self::URL, ['ids' => [1]])->assertRedirect();
    }

    private function publishable(string $name, bool $withImage = true): Product
    {
        $product = Product::factory()
            ->inCategory(Category::factory()->create())
            ->create(['name' => $name, 'description' => 'Una descripción que vende.']);

        if ($withImage) {
            $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-foto.png';
            imagepng(imagecreatetruecolor(4, 4), $path);

            $product
                ->addMedia(new UploadedFile($path, 'foto.png', 'image/png', null, true))
                ->withCustomProperties([TenantPathGenerator::ORGANIZATION_PROPERTY => $this->organization->getKey()])
                ->toMediaCollection(Product::IMAGES);
        }

        return $product->refresh();
    }

    private function owner(): User
    {
        return $this->inTenant(fn (): User => User::factory()->owner()->create());
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
