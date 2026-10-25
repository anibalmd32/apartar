<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ProductData;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Money\Money;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UpsertProductActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_creates_a_draft_with_a_derived_slug(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro Bosch GSB'), $this->actor);

        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame('taladro-bosch-gsb', $product->slug);
        $this->assertSame(25_000, $product->price_cents->cents);
        $this->assertNull($product->published_at);
    }

    public function test_creating_takes_a_products_slot_and_editing_does_not(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro', sku: 'T-1'), $this->actor);
        $this->assertSame(1, $this->productsCounter());

        $this->upsert()->handle($this->data(name: 'Taladro percutor', sku: 'T-1'), $this->actor, $product);
        $this->assertSame(1, $this->productsCounter());

        try {
            $this->upsert()->handle($this->data(name: 'Otro', sku: 'T-1'), $this->actor);
            $this->fail('El SKU repetido debió rechazarse.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $this->productsCounter());
    }

    public function test_a_colliding_slug_gets_a_suffix(): void
    {
        $this->upsert()->handle($this->data(name: 'Taladro rojo', sku: 'A-1'), $this->actor);
        $second = $this->upsert()->handle($this->data(name: 'Taladro-rojo', sku: 'A-2'), $this->actor);

        $this->assertSame('taladro-rojo-2', $second->slug);
    }

    public function test_a_duplicate_sku_in_the_same_organization_is_rejected(): void
    {
        $this->upsert()->handle($this->data(sku: 'BOSCH-01'), $this->actor);

        try {
            $this->upsert()->handle($this->data(name: 'Otro', sku: 'BOSCH-01'), $this->actor);
            $this->fail('El SKU duplicado debería rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }

        $this->assertSame(1, Product::query()->count());
    }

    public function test_a_sku_held_by_a_trashed_product_is_still_taken(): void
    {
        $this->upsert()->handle($this->data(sku: 'BOSCH-01'), $this->actor)->delete();

        $this->expectException(ValidationException::class);

        $this->upsert()->handle($this->data(name: 'Otro', sku: 'BOSCH-01'), $this->actor);
    }

    public function test_renaming_keeps_the_slug_and_publishing_locks_it(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro'), $this->actor);

        $this->upsert()->handle($this->data(name: 'Taladro Bosch'), $this->actor, $product);

        $this->assertSame('Taladro Bosch', $product->refresh()->name);
        $this->assertSame('taladro', $product->slug);

        $this->upsert()->handle($this->data(name: 'Taladro Bosch', slug: 'taladro-bosch'), $this->actor, $product);

        $this->assertSame('taladro-bosch', $product->refresh()->slug);

        $product->forceFill(['status' => ProductStatus::Published])->save();

        try {
            $this->upsert()->handle($this->data(name: 'Taladro Bosch', slug: 'otro-enlace'), $this->actor, $product);
            $this->fail('El enlace de un producto publicado no debería poder cambiarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
        }

        $this->assertSame('taladro-bosch', $product->refresh()->slug);
    }

    public function test_resending_the_same_slug_on_a_published_product_is_not_a_change(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro'), $this->actor);
        $product->forceFill(['status' => ProductStatus::Published])->save();

        $this->upsert()->handle($this->data(name: 'Taladro Bosch', slug: 'taladro'), $this->actor, $product);

        $this->assertSame('Taladro Bosch', $product->refresh()->name);
    }

    public function test_a_category_written_on_the_fly_is_created_or_reused(): void
    {
        $first = $this->upsert()->handle($this->data(sku: 'A-1', categoryName: 'Herramientas'), $this->actor);
        $second = $this->upsert()->handle($this->data(name: 'Otro', sku: 'A-2', categoryName: 'herramientas'), $this->actor);

        $this->assertSame(1, Category::query()->count());
        $this->assertSame($first->category_id, $second->category_id);
        $this->assertSame('Herramientas', Category::query()->sole()->name);
    }

    public function test_tags_written_on_the_fly_are_created_or_reused(): void
    {
        $existing = Tag::factory()->named('Oferta')->create();

        $product = $this->upsert()->handle(
            $this->data(tagNames: ['oferta', 'Novedad', 'Novedad']),
            $this->actor,
        );

        $this->assertSame(2, Tag::query()->count());
        $this->assertEqualsCanonicalizing(
            ['Oferta', 'Novedad'],
            $product->tags()->pluck('name')->all(),
        );
        $this->assertTrue($product->tags()->whereKey($existing->getKey())->exists());
    }

    public function test_saving_replaces_the_tag_list(): void
    {
        $oferta = Tag::factory()->named('Oferta')->create();
        $novedad = Tag::factory()->named('Novedad')->create();

        $product = $this->upsert()->handle(
            $this->data(tagIds: [(int) $oferta->getKey(), (int) $novedad->getKey()]),
            $this->actor,
        );

        $this->upsert()->handle($this->data(tagIds: [(int) $novedad->getKey()]), $this->actor, $product);

        $this->assertSame(['Novedad'], $product->tags()->pluck('name')->all());
    }

    public function test_a_tag_of_another_organization_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(CrossTenantRelationException::class);

        $this->upsert()->handle($this->data(tagIds: [(int) $foreign->getKey()]), $this->actor);
    }

    public function test_a_category_of_another_organization_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Category => Category::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(CrossTenantRelationException::class);

        $this->upsert()->handle($this->data(categoryId: (int) $foreign->getKey()), $this->actor);
    }

    public function test_nothing_is_written_when_a_relation_crosses_the_border(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        try {
            $this->upsert()->handle(
                $this->data(categoryName: 'Herramientas', tagIds: [(int) $foreign->getKey()]),
                $this->actor,
            );
        } catch (CrossTenantRelationException) {
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Category::query()->count());
    }

    public function test_changing_the_payment_terms_is_audited(): void
    {
        $product = $this->upsert()->handle($this->data(), $this->actor);

        $this->upsert()->handle($this->data(price: '300.00'), $this->actor, $product);

        $entry = AuditEntry::query()->sole();

        $this->assertSame(AuditEvent::ProductPricingChanged->value, $entry->event);
        $this->assertSame(25_000, $entry->properties['changed']['price_cents']['before'] ?? null);
        $this->assertSame(30_000, $entry->properties['changed']['price_cents']['after'] ?? null);
    }

    public function test_creating_or_renaming_writes_no_audit_entry(): void
    {
        $product = $this->upsert()->handle($this->data(name: 'Taladro'), $this->actor);

        $this->upsert()->handle($this->data(name: 'Taladro Bosch'), $this->actor, $product);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_running_out_of_stock_does_not_change_the_status(): void
    {
        $product = $this->upsert()->handle($this->data(), $this->actor);
        $product->forceFill(['status' => ProductStatus::Published])->save();

        $this->upsert()->handle($this->data(stock: 0), $this->actor, $product);

        $this->assertSame(0, $product->refresh()->stock);
        $this->assertSame(ProductStatus::Published, $product->status);
    }

    public function test_a_member_may_create_products(): void
    {
        $member = User::factory()->create();

        $this->assertSame('Taladro', $this->upsert()->handle($this->data(name: 'Taladro'), $member)->name);
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->upsert()->handle($this->data(), $foreignOwner);
    }

    /**
     * @param  list<int>  $tagIds
     * @param  list<string>  $tagNames
     */
    private function data(
        string $name = 'Taladro Bosch',
        string $sku = 'BOSCH-01',
        ?string $slug = null,
        string $price = '250.00',
        string $downPayment = '50.00',
        int $stock = 10,
        ?int $categoryId = null,
        ?string $categoryName = null,
        array $tagIds = [],
        array $tagNames = [],
    ): ProductData {
        return new ProductData(
            name: $name,
            sku: $sku,
            slug: $slug,
            description: 'Un taladro.',
            price: Money::fromDecimalString($price),
            compareAtPrice: null,
            downPayment: Money::fromDecimalString($downPayment),
            installmentsCount: 4,
            frequencyDays: 15,
            stock: $stock,
            categoryId: $categoryId,
            categoryName: $categoryName,
            tagIds: $tagIds,
            tagNames: $tagNames,
        );
    }

    private function upsert(): UpsertProductAction
    {
        return app(UpsertProductAction::class);
    }

    private function productsCounter(): int
    {
        return (int) UsageCounter::query()->where('metric', UsageMetric::Products)->value('value');
    }
}
