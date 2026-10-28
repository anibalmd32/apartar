<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\User;
use App\Support\Settings;
use Closure;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProductPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($this->organization);

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_the_listing_carries_the_products_with_their_catalog_count(): void
    {
        $this->inTenant(function (): void {
            $category = Category::factory()->named('Herramientas')->create();

            Product::factory()->inCategory($category)->published()->create(['name' => 'Taladro']);
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/productos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/products/Index')
                ->has('rows', 1)
                ->where('rows.0.name', 'Taladro')
                ->where('rows.0.categoryName', 'Herramientas')
                ->where('rows.0.catalogsCount', 0)
                ->has('statuses', 3));
    }

    public function test_the_listing_filters_by_status_and_search(): void
    {
        $this->inTenant(function (): void {
            Product::factory()->published()->create(['name' => 'Taladro', 'sku' => 'BOSCH-01']);
            Product::factory()->create(['name' => 'Sierra', 'sku' => 'MAK-02']);
        });

        $owner = $this->owner();

        $this->actingAs($owner)
            ->get('/backoffice/productos?status=published')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Taladro'));

        $this->actingAs($owner)
            ->get('/backoffice/productos?q=MAK')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Sierra'));
    }

    public function test_the_form_carries_the_organization_defaults(): void
    {
        $this->inTenant(function (): void {
            $this->setting(SettingKey::DefaultInstallmentsCount, 6);
            $this->setting(SettingKey::DefaultFrequencyDays, 30);

            app(Settings::class)->forget();
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/products/Form')
                ->where('product', null)
                ->where('defaults.installmentsCount', 6)
                ->where('defaults.frequencyDays', 30)
                ->where('defaults.skuSequence', 1));
    }

    public function test_the_form_carries_the_plan_computed_by_the_domain(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear?price=250.00&down_payment=50.00&installments_count=5&frequency_days=15')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plan.installmentsCount', 5)
                ->where('plan.frequencyDays', 15)
                ->where('plan.downPayment.usd', '$50,00')
                ->where('plan.installmentAmount.usd', '$40,00')
                ->where('plan.hasUnevenLastInstallment', false)
                ->where('plan.financedAmount.usd', '$200,00'));
    }

    public function test_the_plan_puts_the_remainder_in_the_last_installment(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear?price=100.00&down_payment=0.00&installments_count=3&frequency_days=15')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plan.installmentAmount.usd', '$33,33')
                ->where('plan.lastInstallmentAmount.usd', '$33,34')
                ->where('plan.hasUnevenLastInstallment', true));
    }

    public function test_impossible_terms_carry_no_plan(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear?price=100.00&down_payment=100.00&installments_count=3&frequency_days=15')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('plan', null));
    }

    public function test_creating_a_product_lands_on_its_form_as_a_draft(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload())
            ->assertSessionHasNoErrors();

        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame(25_000, $product->price_cents->cents);
        $this->assertSame('taladro-bosch', $product->slug);
    }

    public function test_the_cross_field_rules_land_on_their_own_field(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload(['down_payment' => '250.00']))
            ->assertSessionHasErrors('down_payment');

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload(['compare_at_price' => '250.00', 'sku' => 'OTRO']))
            ->assertSessionHasErrors('compare_at_price');

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    public function test_an_amount_with_more_than_two_decimals_is_rejected(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload(['price' => '19.999']))
            ->assertSessionHasErrors('price');

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    public function test_a_duplicate_sku_in_the_same_organization_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/backoffice/productos', $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload(['name' => 'Otro']))
            ->assertSessionHasErrors('sku');
    }

    public function test_editing_the_slug_of_a_published_product_is_rejected(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->published()->create([
            'sku' => 'BOSCH-01',
            'slug' => 'taladro-bosch',
        ]));

        $this->actingAs($this->owner())
            ->put("/backoffice/productos/{$product->getKey()}", $this->payload(['slug' => 'otro-enlace']))
            ->assertSessionHasErrors('slug');

        $this->assertSame('taladro-bosch', $product->refresh()->slug);
    }

    public function test_archiving_a_product_works_from_the_listing(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->published()->create());
        $this->recalculateUsage($this->organization);

        $this->actingAs($this->owner())
            ->post("/backoffice/productos/{$product->getKey()}/archivar")
            ->assertRedirect('/backoffice/productos');

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
    }

    public function test_the_characteristics_are_saved_in_order_with_the_product(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload([
                'specifications' => ['  Potencia de   750 W ', '', 'Cuerpo de acero'],
            ]))
            ->assertSessionHasNoErrors();

        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->actingAs($owner)
            ->get("/backoffice/productos/{$product->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.specifications', ['Potencia de 750 W', 'Cuerpo de acero']));
    }

    public function test_a_repeated_characteristic_is_rejected_with_its_index(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload([
                'specifications' => ['Cuerpo de acero', 'Potencia de 750 W', 'cuerpo  de ACERO'],
            ]))
            ->assertSessionHasErrors(['specifications.2' => 'Esta característica ya está en la lista. Quítala o escribe otra distinta.'])
            ->assertSessionDoesntHaveErrors(['specifications.0', 'specifications.1']);

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    public function test_a_characteristic_over_the_length_limit_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload([
                'specifications' => ['Cuerpo de acero', str_repeat('a', 201)],
            ]))
            ->assertSessionHasErrors('specifications.1');

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload([
                'specifications' => [str_repeat('a', 200)],
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_characteristic_must_be_text(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload([
                'specifications' => [['label' => 'Material', 'value' => 'Acero']],
            ]))
            ->assertSessionHasErrors('specifications.0');
    }

    public function test_more_than_thirty_characteristics_are_rejected(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload([
                'specifications' => $this->specificationRows(31),
            ]))
            ->assertSessionHasErrors('specifications');

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    public function test_empty_rows_do_not_count_toward_the_limit(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload([
                'specifications' => [...$this->specificationRows(30), '', '   '],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(30, $this->inTenant(fn (): int => ProductSpecification::query()->count()));
    }

    public function test_saving_with_zero_rows_clears_the_sheet(): void
    {
        $product = $this->inTenant(function (): Product {
            $product = Product::factory()->create(['sku' => 'BOSCH-01', 'slug' => 'taladro-bosch']);

            ProductSpecification::factory()->forProduct($product)->create(['text' => 'Cuerpo de acero']);

            return $product;
        });

        $this->actingAs($this->owner())
            ->put("/backoffice/productos/{$product->getKey()}", $this->payload(['specifications' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->inTenant(fn (): int => ProductSpecification::query()->count()));
    }

    public function test_the_form_no_longer_carries_group_suggestions(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('specificationGroups'));
    }

    public function test_a_product_of_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Product => Product::factory()->create(),
        );

        $owner = $this->owner();

        $this->actingAs($owner)->get("/backoffice/productos/{$foreign->getKey()}/editar")->assertNotFound();
        $this->actingAs($owner)->put("/backoffice/productos/{$foreign->getKey()}", $this->payload())->assertNotFound();
        $this->actingAs($owner)->post("/backoffice/productos/{$foreign->getKey()}/archivar")->assertNotFound();
    }

    public function test_a_member_manages_products(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)->get('/backoffice/productos')->assertOk();
        $this->actingAs($member)->post('/backoffice/productos', $this->payload())->assertSessionHasNoErrors();
    }

    public function test_a_suspended_organization_cannot_create_or_edit_products(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create(['sku' => 'BOSCH-01']));

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $owner = $this->owner();

        $this->actingAs($owner)->get('/backoffice/productos/crear')->assertForbidden();
        $this->actingAs($owner)->post('/backoffice/productos', $this->payload())->assertForbidden();
        $this->actingAs($owner)->put("/backoffice/productos/{$product->getKey()}", $this->payload())->assertForbidden();
    }

    public function test_the_edit_form_returns_the_amounts_as_decimals(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create([
            'price_cents' => 25_000,
            'down_payment_cents' => 5_000,
        ]));

        $this->actingAs($this->owner())
            ->get("/backoffice/productos/{$product->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.price', '250.00')
                ->where('product.downPayment', '50.00')
                ->where('product.compareAtPrice', null)
                ->where('product.slugIsLocked', false));
    }

    public function test_publishing_an_incomplete_product_lands_the_missing_list_on_its_fields(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create(['description' => null]));

        $this->actingAs($this->owner())
            ->from('/backoffice/productos')
            ->post("/backoffice/productos/{$product->getKey()}/publicar")
            ->assertRedirect('/backoffice/productos')
            ->assertSessionHasErrors(['status', 'images', 'category_id', 'description']);

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
    }

    public function test_restoring_leaves_a_draft_and_publishing_an_archived_product_is_refused(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->archived()->create());

        $owner = $this->owner();

        $this->actingAs($owner)
            ->from('/backoffice/productos')
            ->post("/backoffice/productos/{$product->getKey()}/publicar")
            ->assertSessionHasErrors('status');

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);

        $this->actingAs($owner)
            ->post("/backoffice/productos/{$product->getKey()}/restaurar")
            ->assertRedirect('/backoffice/productos');

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
    }

    public function test_unpublishing_a_product_works_from_the_listing(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->published()->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/productos/{$product->getKey()}/despublicar")
            ->assertRedirect('/backoffice/productos');

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
        $this->assertNull($product->published_at);
    }

    public function test_the_listing_counts_each_status_filters_the_scheduled_and_carries_the_plan(): void
    {
        $this->inTenant(function (): void {
            Product::factory()->scheduled()->create(['name' => 'Programado']);
            Product::factory()->published()->create(['name' => 'Publicado', 'down_payment_cents' => 5_000, 'installments_count' => 4, 'frequency_days' => 15]);
            Product::factory()->create(['name' => 'Borrador']);
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statusCounts', ['all' => 3, 'draft' => 1, 'published' => 2, 'archived' => 0, 'scheduled' => 1])
                ->where('rows.1.name', 'Publicado')
                ->where('rows.1.plan.downPayment.cents', 5_000)
                ->where('rows.1.plan.installments', 4)
                ->where('rows.1.plan.frequencyDays', 15)
                ->where('rows.1.thumbUrl', null));

        $this->get('/backoffice/productos?scheduled=1')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 1)
            ->where('rows.0.name', 'Programado'));
    }

    public function test_the_form_links_to_the_public_page_only_when_it_is_visible(): void
    {
        [$visible, $hidden] = $this->inTenant(function (): array {
            $open = Catalog::factory()->published()->create();
            $draft = Catalog::factory()->create();
            $visible = Product::factory()->published()->create();
            $hidden = Product::factory()->published()->create();
            $open->products()->attach($visible->getKey(), ['position' => 0]);
            $draft->products()->attach($hidden->getKey(), ['position' => 0]);

            return [$visible->load('catalogs'), $hidden];
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/productos/'.$visible->getKey().'/editar')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.publicUrl', route('catalog.product', [
                'catalog' => $visible->catalogs->first()?->slug,
                'product' => $visible->slug,
            ])));

        $this->get('/backoffice/productos/'.$hidden->getKey().'/editar')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.publicUrl', null));
    }

    public function test_the_listing_marks_a_scheduled_product_with_its_date(): void
    {
        $this->inTenant(function (): void {
            Product::factory()->scheduled()->create(['name' => 'Programado']);
            Product::factory()->published()->create(['name' => 'Publicado']);
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('rows.0.name', 'Publicado')
                ->where('rows.0.scheduledFor', null)
                ->where('rows.1.name', 'Programado')
                ->where('rows.1.scheduledFor', fn (mixed $date): bool => is_string($date) && $date !== ''));
    }

    public function test_a_suspended_organization_cannot_change_a_product_status(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->published()->create());

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $owner = $this->owner();

        $this->actingAs($owner)->post("/backoffice/productos/{$product->getKey()}/despublicar")->assertForbidden();
        $this->actingAs($owner)->post("/backoffice/productos/{$product->getKey()}/archivar")->assertForbidden();
        $this->actingAs($owner)->post("/backoffice/productos/{$product->getKey()}/restaurar")->assertForbidden();
        $this->actingAs($owner)->post("/backoffice/productos/{$product->getKey()}/publicar")->assertForbidden();
    }

    public function test_the_transitions_of_a_foreign_product_are_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Product => Product::factory()->published()->create(),
        );

        $owner = $this->owner();

        $this->actingAs($owner)->post("/backoffice/productos/{$foreign->getKey()}/publicar")->assertNotFound();
        $this->actingAs($owner)->post("/backoffice/productos/{$foreign->getKey()}/despublicar")->assertNotFound();
        $this->actingAs($owner)->post("/backoffice/productos/{$foreign->getKey()}/restaurar")->assertNotFound();
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

    /**
     * @return list<string>
     */
    private function specificationRows(int $count): array
    {
        $rows = [];

        for ($number = 1; $number <= $count; $number++) {
            $rows[] = 'Característica '.$number;
        }

        return $rows;
    }

    private function setting(SettingKey $key, mixed $value): void
    {
        OrganizationSetting::factory()->forKey($key, $value)->create();
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
