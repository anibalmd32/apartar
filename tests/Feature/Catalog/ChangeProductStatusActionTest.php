<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ChangeProductStatusAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\IncompleteProductException;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\TranscodeProductImage;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ChangeProductStatusActionTest extends TestCase
{
    use RefreshDatabase;

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
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_archiving_frees_a_products_slot_and_restoring_takes_it_back(): void
    {
        $product = $this->publishable();
        $this->recalculateUsage();
        $this->assertSame(1, $this->productsCounter());

        $this->changer()->publish($product, $this->actor);
        $this->assertSame(1, $this->productsCounter());

        $this->changer()->archive($product, $this->actor);
        $this->assertSame(0, $this->productsCounter());

        app(ChangeProductStatusAction::class)->archive($product, $this->actor);
        $this->assertSame(0, $this->productsCounter());

        app(ChangeProductStatusAction::class)->restore($product, $this->actor);
        $this->assertSame(1, $this->productsCounter());

        app(ChangeProductStatusAction::class)->restore($product, $this->actor);
        $this->assertSame(1, $this->productsCounter());
    }

    public function test_the_matrix_accepts_the_five_legitimate_steps(): void
    {
        [$draft, $toArchive, $published, $publishedToArchive, $archived] = [
            $this->publishable(),
            Product::factory()->create(),
            Product::factory()->published()->create(),
            Product::factory()->published()->create(),
            Product::factory()->archived()->create(),
        ];

        $status = $this->changer();

        $this->assertSame(ProductStatus::Published, $status->publish($draft, $this->actor)->status);
        $this->assertSame(ProductStatus::Archived, $status->archive($toArchive, $this->actor)->status);
        $this->assertSame(ProductStatus::Draft, $status->unpublish($published, $this->actor)->status);
        $this->assertSame(ProductStatus::Archived, $status->archive($publishedToArchive, $this->actor)->status);
        $this->assertSame(ProductStatus::Draft, $status->restore($archived, $this->actor)->status);
    }

    public function test_an_archived_product_cannot_be_published_directly(): void
    {
        $product = $this->publishable();

        $this->changer()->archive($product, $this->actor);

        try {
            $this->changer()->publish($product->refresh(), $this->actor);

            $this->fail('Publicar un archivado tenía que rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertStringContainsString('restáuralo primero', $exception->getMessage());
        }

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
    }

    public function test_repeating_an_applied_step_changes_nothing(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        $publishedAt = $product->refresh()->published_at;

        $this->travel(2)->hours();

        $this->changer()->publish($product, $this->actor);

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertTrue($publishedAt?->equalTo($product->published_at));
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_archiving_twice_is_idempotent_and_writes_one_entry(): void
    {
        $product = Product::factory()->published()->create();

        $this->changer()->archive($product, $this->actor);
        $this->changer()->archive($product, $this->actor);

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_publishing_without_images_is_refused(): void
    {
        $this->assertMissingToPublish($this->publishable(withImage: false), 'images', 'al menos una foto');
    }

    public function test_publishing_without_a_category_is_refused(): void
    {
        $product = $this->publishable();
        $product->forceFill(['category_id' => null])->save();

        $this->assertMissingToPublish($product, 'category_id', 'una categoría');
    }

    public function test_publishing_without_a_description_is_refused(): void
    {
        $product = $this->publishable();
        $product->forceFill(['description' => '   '])->save();

        $this->assertMissingToPublish($product, 'description', 'la descripción');
    }

    public function test_publishing_with_incoherent_financing_terms_is_refused(): void
    {
        $product = $this->publishable();

        $product->forceFill(['down_payment_cents' => 30_000, 'price_cents' => 25_000])->save();

        $this->assertMissingToPublish($product, 'down_payment', 'las condiciones de pago');
    }

    public function test_the_refusal_names_everything_that_is_missing_at_once(): void
    {
        $product = $this->publishable(withImage: false);
        $product->forceFill(['category_id' => null, 'description' => null])->save();

        try {
            $this->changer()->publish($product, $this->actor);

            $this->fail('Publicar un producto incompleto tenía que rechazarse.');
        } catch (IncompleteProductException $exception) {
            $this->assertSame(
                ['images', 'category_id', 'description'],
                array_keys($exception->missing),
            );

            $this->assertStringContainsString('al menos una foto, una categoría y la descripción', $exception->getMessage());
            $this->assertSame(422, IncompleteProductException::STATUS);
        }

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
    }

    public function test_a_complete_product_publishes(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertTrue($product->status->isVisibleInCatalog());
        $this->assertSame(1, Product::query()->published()->count());
    }

    public function test_publishing_a_product_that_is_in_no_catalog_is_not_an_error(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertSame(0, $product->catalogs()->count());
        $this->assertTrue($product->status->isVisibleInCatalog());
    }

    public function test_publishing_stamps_the_date_now(): void
    {
        $this->freezeTime();

        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);

        $this->assertSame(
            now()->toDateTimeString(),
            $product->refresh()->published_at?->toDateTimeString(),
        );

        $this->assertFalse($product->isScheduled());
        $this->assertSame(1, Product::query()->published()->count());
    }

    public function test_publishing_with_a_future_date_schedules_it(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor, now()->addWeek());

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertTrue($product->isScheduled());
        $this->assertSame(0, Product::query()->published()->count());

        $this->travel(8)->days();

        $this->assertSame(1, Product::query()->published()->count());
        $this->assertFalse($product->refresh()->isScheduled());
    }

    public function test_unpublishing_clears_the_publication_date(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor, now()->addWeek());
        $this->changer()->unpublish($product->refresh(), $this->actor);

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
        $this->assertNull($product->published_at);
        $this->assertFalse($product->isScheduled());
    }

    public function test_restoring_an_archived_product_leaves_it_in_draft(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);
        $this->changer()->archive($product->refresh(), $this->actor);
        $this->changer()->restore($product->refresh(), $this->actor);

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
        $this->assertNull($product->published_at);
        $this->assertFalse($product->status->isVisibleInCatalog());
        $this->assertSame(0, Product::query()->published()->count());
    }

    public function test_restoring_does_not_have_to_recompose_catalogs(): void
    {
        $catalog = Catalog::factory()->published()->create();
        $product = $this->publishable();

        $catalog->products()->attach($product, ['position' => 1]);

        $this->changer()->archive($product, $this->actor);
        $this->changer()->restore($product->refresh(), $this->actor);

        $this->assertSame(1, $catalog->products()->count());
        $this->assertSame(1, $product->refresh()->catalogs()->count());
    }

    public function test_it_archives_a_published_product(): void
    {
        $product = Product::factory()->published()->create();

        $this->changer()->archive($product, $this->actor);

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
        $this->assertFalse($product->status->isVisibleInCatalog());
        $this->assertFalse($product->status->canBeReserved());
    }

    public function test_archiving_does_not_delete_the_row(): void
    {
        $product = Product::factory()->published()->create();

        $this->changer()->archive($product, $this->actor);

        $this->assertNotNull(Product::query()->find($product->getKey()));
        $this->assertNull($product->refresh()->deleted_at);
    }

    public function test_archiving_keeps_the_product_in_its_catalogs(): void
    {
        $catalog = Catalog::factory()->published()->create();
        $product = Product::factory()->published()->create();

        $catalog->products()->attach($product, ['position' => 1]);

        $this->changer()->archive($product, $this->actor);

        $this->assertSame(1, $catalog->products()->count());
        $this->assertSame(1, $product->refresh()->catalogs()->count());
    }

    public function test_archiving_is_audited(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Taladro Bosch']);

        $this->changer()->archive($product, $this->actor);

        $entry = AuditEntry::query()->sole();

        $this->assertSame(AuditEvent::ProductStatusChanged->value, $entry->event);
        $this->assertStringContainsString('Taladro Bosch', $entry->description);
        $this->assertSame('published', $entry->properties['changed']['status']['before'] ?? null);
        $this->assertSame('archived', $entry->properties['changed']['status']['after'] ?? null);
    }

    public function test_every_transition_writes_the_same_event(): void
    {
        $product = $this->publishable();

        $this->changer()->publish($product, $this->actor);
        $this->changer()->unpublish($product->refresh(), $this->actor);
        $this->changer()->archive($product->refresh(), $this->actor);

        $events = AuditEntry::query()->pluck('event')->all();

        $this->assertSame([
            AuditEvent::ProductStatusChanged->value,
            AuditEvent::ProductStatusChanged->value,
            AuditEvent::ProductStatusChanged->value,
        ], $events);

        $changed = AuditEntry::query()->orderBy('id')->firstOrFail()->properties['changed'] ?? [];

        $this->assertArrayHasKey('published_at', $changed);
        $this->assertNull($changed['published_at']['before']);
        $this->assertNotNull($changed['published_at']['after']);
    }

    public function test_a_member_may_change_the_status(): void
    {
        $product = Product::factory()->published()->create();
        $member = User::factory()->create();

        $this->changer()->archive($product, $member);

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $product = Product::factory()->published()->create();

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->changer()->archive($product, $foreignOwner);
    }

    public function test_publishing_from_another_organization_is_not_authorised(): void
    {
        $product = $this->publishable();

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->changer()->publish($product, $foreignOwner);
    }

    private function productsCounter(): int
    {
        return (int) UsageCounter::query()->where('metric', UsageMetric::Products)->value('value');
    }

    private function assertMissingToPublish(Product $product, string $field, string $label): void
    {
        try {
            $this->changer()->publish($product, $this->actor);

            $this->fail(sprintf('Publicar sin «%s» tenía que rechazarse.', $label));
        } catch (IncompleteProductException $exception) {
            $this->assertSame([$field], array_keys($exception->missing));
            $this->assertStringContainsString($label, $exception->getMessage());
            $this->assertTrue($exception->product->is($product));
        }

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
    }

    private function publishable(bool $withImage = true): Product
    {
        $product = Product::factory()
            ->inCategory(Category::factory()->create())
            ->create(['description' => 'Taladro percutor de 750 W con maletín.']);

        if ($withImage) {
            $product
                ->addMedia($this->image())
                ->withCustomProperties([
                    TenantPathGenerator::ORGANIZATION_PROPERTY => $this->organization->getKey(),
                ])
                ->toMediaCollection(Product::IMAGES);
        }

        return $product->refresh();
    }

    private function image(): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-foto.png';

        imagepng(imagecreatetruecolor(4, 4), $path);

        return new UploadedFile($path, 'foto.png', 'image/png', null, true);
    }

    private function changer(): ChangeProductStatusAction
    {
        $this->recalculateUsage();

        return app(ChangeProductStatusAction::class);
    }
}
