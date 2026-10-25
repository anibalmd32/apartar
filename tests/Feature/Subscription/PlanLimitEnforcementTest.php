<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Actions\Organization\EnableTeamMemberAction;
use App\Actions\Product\ChangeProductStatusAction;
use App\Actions\Product\ProductData;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Money\Money;
use App\Domain\Subscription\CatalogProductLimitReachedException;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\GenerateExport;
use App\Jobs\TranscodeProductImage;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PlanLimitEnforcementTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        Bus::fake([TranscodeProductImage::class]);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);

        $this->plan = Plan::factory()->create([
            'code' => 'free-t067', 'name' => 'Free', 'position' => 1,
            'max_users' => 1, 'max_catalogs' => 1, 'max_products' => 1, 'max_products_per_catalog' => 2,
            'max_storage_mb' => 1, 'max_payment_accounts' => 1, 'max_active_layaways' => 1,
        ]);
        Plan::factory()->unlimited()->withEveryFeature()->create(['code' => 'pro-t067', 'name' => 'Pro', 'position' => 2]);

        Subscription::factory()->forPlan($this->plan)->create();
        $this->owner = User::factory()->owner()->create();
        $this->recalculateUsage();
    }

    public function test_point_1_reenabling_into_a_full_plan_names_the_plan_that_unlocks_it(): void
    {
        $member = User::factory()->disabled()->create();
        $this->recalculateUsage();

        try {
            app(EnableTeamMemberAction::class)->handle($member, $this->owner);
            $this->fail('Se reactivó a alguien con el plan lleno.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Users, $exception->metric);
            $this->assertSame('pro-t067', $exception->minimumPlan?->code);
        }
    }

    public function test_point_1_an_inertia_form_goes_back_with_the_reason(): void
    {
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->from('/backoffice/equipo')
            ->post('/backoffice/equipo', ['name' => 'Luis', 'email' => 'luis@ejemplo.test', 'role' => 'member'])
            ->assertRedirect('/backoffice/equipo')
            ->assertSessionHasErrors('plan')
            ->assertSessionHas('planLimit', fn (array $body): bool => $body['limit']['metric'] === 'users'
                && $body['limit']['minimumPlanName'] === 'Pro'
                && $body['upgradeUrl'] === '/backoffice/suscripcion'
                && str_contains($body['limit']['suggestion'], 'revocar una invitación'));
    }

    public function test_point_1_the_api_answers_422_with_the_body(): void
    {
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->postJson('/backoffice/equipo', ['name' => 'Luis', 'email' => 'luis@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('errors.plan.0', fn (string $message): bool => str_contains($message, 'Pro permite'))
            ->assertJsonPath('limit.metric', 'users')
            ->assertJsonPath('limit.minimumPlan', 'pro-t067');
    }

    public function test_point_2_publishing_a_second_catalog_through_the_screen_goes_back_with_the_reason(): void
    {
        PaymentAccount::factory()->create();
        $first = $this->catalogWithAProduct();
        $second = $this->catalogWithAProduct();
        $first->forceFill(['published_at' => now()])->save();
        $this->recalculateUsage();
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->from('/backoffice/catalogos')
            ->post("/backoffice/catalogos/{$second->getKey()}/publicar", ['is_listed' => true])
            ->assertRedirect('/backoffice/catalogos')
            ->assertSessionHasErrors('plan')
            ->assertSessionHas('planLimit', fn (array $body): bool => $body['limit']['metric'] === 'catalogs'
                && str_contains($body['limit']['suggestion'], 'despublicar otro catálogo'));

        $this->assertNull($second->refresh()->published_at);
    }

    public function test_point_3_creating_a_product_over_the_ceiling_is_rejected_before_writing_anything(): void
    {
        app(UpsertProductAction::class)->handle($this->productData('Taladro', 'T-1'), $this->owner);

        try {
            app(UpsertProductAction::class)->handle($this->productData('Sierra', 'S-1'), $this->owner);
            $this->fail('Se creó un producto por encima del techo.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Products, $exception->metric);
        }

        $this->assertSame(['T-1'], Product::query()->pluck('sku')->all());
    }

    public function test_point_3_restoring_an_archived_product_needs_a_slot(): void
    {
        $archived = Product::factory()->archived()->create();
        Product::factory()->create();
        $this->recalculateUsage();

        try {
            app(ChangeProductStatusAction::class)->restore($archived, $this->owner);
            $this->fail('Se restauró un producto por encima del techo.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Products, $exception->metric);
        }

        $this->assertTrue($archived->refresh()->status->countsTowardPlanLimit() === false);
    }

    public function test_point_4_a_catalog_cannot_grow_past_its_ceiling(): void
    {
        $catalog = Catalog::factory()->create();
        $products = Product::factory()->count(3)->create();

        app(SyncCatalogProductsAction::class)->handle($catalog, [$products[0]->getKey(), $products[1]->getKey()], $this->owner);

        try {
            app(SyncCatalogProductsAction::class)->handle($catalog, $products->modelKeys(), $this->owner);
            $this->fail('El catálogo creció por encima del tope del plan.');
        } catch (CatalogProductLimitReachedException $exception) {
            $this->assertSame(2, $exception->used);
            $this->assertSame(2, $exception->limit);
            $this->assertSame(3, $exception->requested);
            $this->assertSame('pro-t067', $exception->minimumPlan?->code);
            $this->assertSame('products_per_catalog', $exception->toArray()['metric']);
        }

        $this->assertSame(2, $catalog->products()->count());
    }

    public function test_point_4_an_over_the_ceiling_catalog_can_be_reordered_and_shrunk(): void
    {
        $catalog = Catalog::factory()->create();
        $products = Product::factory()->count(4)->create();
        $catalog->products()->attach(collect($products->modelKeys())->mapWithKeys(fn (int $id, int $index): array => [$id => ['position' => $index + 1]])->all());

        app(SyncCatalogProductsAction::class)->handle($catalog, array_reverse($products->modelKeys()), $this->owner);
        app(SyncCatalogProductsAction::class)->handle($catalog, array_slice($products->modelKeys(), 0, 3), $this->owner);

        $this->assertSame(3, $catalog->products()->count());
    }

    public function test_point_4_the_api_answers_422_with_the_plan_that_unlocks_it(): void
    {
        $catalog = Catalog::factory()->create();
        $products = Product::factory()->count(3)->create();
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->patchJson("/backoffice/catalogos/{$catalog->getKey()}/productos", ['products' => $products->modelKeys()])
            ->assertStatus(422)
            ->assertJsonPath('limit.metric', 'products_per_catalog')
            ->assertJsonPath('limit.minimumPlanName', 'Pro');
    }

    public function test_point_6_an_image_over_the_storage_quota_is_not_attached(): void
    {
        $product = Product::factory()->create();
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->from("/backoffice/productos/{$product->getKey()}/editar")
            ->post("/backoffice/productos/{$product->getKey()}/imagenes", ['images' => [$this->heavyImage()]])
            ->assertSessionHasErrors('plan')
            ->assertSessionHas('planLimit', fn (array $body): bool => $body['limit']['metric'] === 'storage_mb');

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => $product->fresh()?->getMedia(Product::IMAGES)->count() ?? 0));
    }

    public function test_point_6_the_storage_check_recounts_instead_of_trusting_a_stale_counter(): void
    {
        $product = Product::factory()->create();
        $this->mediaRow($product, 1024 * 1024);
        UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::StorageMb], ['value' => 0, 'computed_at' => now()]);
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->post("/backoffice/productos/{$product->getKey()}/imagenes", ['images' => [$this->image('chica.png')]])
            ->assertSessionHasErrors('plan');
    }

    public function test_point_6_rounding_is_on_the_total_and_what_fits_stays(): void
    {
        $this->plan->forceFill(['max_storage_mb' => 2])->save();
        $product = Product::factory()->create();
        $this->mediaRow($product, (int) (1.4 * 1024 * 1024));
        $this->recalculateUsage();
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->post("/backoffice/productos/{$product->getKey()}/imagenes", ['images' => [$this->image('una.png'), $this->heavyImage()]])
            ->assertSessionHasErrors('plan');

        $this->assertSame(['una.png'], $this->withinOrganization($this->organization, fn (): array => Media::query()
            ->where('model_id', $product->getKey())->where('file_name', '!=', 'previa.png')->pluck('file_name')->all()));
    }

    public function test_point_4_an_over_the_ceiling_catalog_cannot_swap_in_a_new_product(): void
    {
        $catalog = Catalog::factory()->create();
        $products = Product::factory()->count(4)->create();
        $catalog->products()->attach(collect($products->modelKeys())->mapWithKeys(fn (int $id, int $index): array => [$id => ['position' => $index + 1]])->all());
        $newcomer = Product::factory()->create();

        try {
            app(SyncCatalogProductsAction::class)->handle($catalog, [...array_slice($products->modelKeys(), 0, 2), $newcomer->getKey()], $this->owner);
            $this->fail('Entró un producto nuevo en un catálogo por encima del techo.');
        } catch (CatalogProductLimitReachedException $exception) {
            $this->assertSame(1, $exception->toArray()['excess']);
            $this->assertSame(3, $exception->toArray()['requested']);
        }

        $this->assertFalse($catalog->products()->whereKey($newcomer->getKey())->exists());
    }

    public function test_point_4_the_cheapest_plan_that_holds_it_is_offered(): void
    {
        Plan::query()->where('code', 'pro-t067')->update(['position' => 3]);
        Plan::factory()->create(['code' => 'mid-t067', 'name' => 'Medio', 'position' => 2, 'max_products_per_catalog' => 5]);
        $catalog = Catalog::factory()->create();

        try {
            app(SyncCatalogProductsAction::class)->handle($catalog, Product::factory()->count(4)->create()->modelKeys(), $this->owner);
            $this->fail('El catálogo creció por encima del tope.');
        } catch (CatalogProductLimitReachedException $exception) {
            $this->assertSame('mid-t067', $exception->minimumPlan?->code);
        }
    }

    public function test_an_inertia_visit_carries_the_rejection_to_the_next_page(): void
    {
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->from('/backoffice/equipo')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('/backoffice/equipo', ['name' => 'Luis', 'email' => 'luis@ejemplo.test', 'role' => 'member'])
            ->assertRedirect('/backoffice/equipo');

        $this->flushHeaders();

        $this->actingAs($this->owner)
            ->get('/backoffice/equipo')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('planLimit.limit.metric', 'users')
                ->where('planLimit.limit.minimumPlanName', 'Pro')
                ->where('errors.plan', fn (string $message): bool => str_contains($message, 'Pro permite')));
    }

    public function test_a_suspended_subscription_goes_back_with_a_visible_reason(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended->value]);
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->from('/backoffice/productos/crear')
            ->post('/backoffice/productos', ['name' => 'Taladro', 'sku' => 'T-9', 'price' => '10.00', 'down_payment' => '2.00', 'installments_count' => 2, 'frequency_days' => 15, 'stock' => 1])
            ->assertRedirect('/backoffice/productos/crear')
            ->assertSessionHasErrors('plan')
            ->assertSessionHas('planBlocked', fn (array $body): bool => str_contains($body['message'], 'suspendida'));
    }

    public function test_points_9_to_12_a_paid_feature_answers_403_with_the_plan_that_has_it(): void
    {
        $pending = [PlanFeature::WebPush, PlanFeature::SubscriberDigest, PlanFeature::FullAnalytics];

        foreach ($pending as $feature) {
            Route::middleware(['web', 'auth', 'tenant', 'plan-feature:'.$feature->value])
                ->get('/backoffice/_atrezo/'.$feature->value, fn (): string => 'dentro');
        }

        $this->withoutOrganization();
        Queue::fake();

        $this->actingAs($this->owner)
            ->postJson('/backoffice/exportaciones', ['type' => 'collections'])
            ->assertForbidden()
            ->assertJsonPath('feature.feature', PlanFeature::ExcelExport->value)
            ->assertJsonPath('feature.minimumPlanName', 'Pro')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'exportación a Excel'));

        foreach ($pending as $feature) {
            $this->actingAs($this->owner)
                ->getJson('/backoffice/_atrezo/'.$feature->value)
                ->assertForbidden()
                ->assertJsonPath('feature.feature', $feature->value)
                ->assertJsonPath('feature.minimumPlanName', 'Pro')
                ->assertJsonPath('message', fn (string $message): bool => str_contains($message, mb_strtolower(mb_substr($feature->label(), 0, 1)).mb_substr($feature->label(), 1)));
        }

        Queue::assertNothingPushed();
        $this->withinOrganization($this->organization, fn () => $this->plan->forceFill(['features' => PlanFeature::only(PlanFeature::cases())])->save());
        $this->withoutOrganization();

        $this->actingAs($this->owner)->postJson('/backoffice/exportaciones', ['type' => 'collections'])->assertStatus(202);
        Queue::assertPushed(GenerateExport::class);

        foreach ($pending as $feature) {
            $this->actingAs($this->owner)->get('/backoffice/_atrezo/'.$feature->value)->assertOk()->assertSee('dentro');
        }
    }

    public function test_an_inertia_visit_to_a_paid_page_goes_back_with_the_reason(): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'plan-feature:excel_export'])->get('/backoffice/_atrezo/excel', fn (): string => 'dentro');
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->from('/backoffice')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
            ->get('/backoffice/_atrezo/excel')
            ->assertRedirect('/backoffice')
            ->assertSessionHas('planFeature', fn (array $body): bool => $body['feature']['feature'] === 'excel_export');
    }

    public function test_a_route_with_an_unknown_feature_fails_closed(): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'plan-feature:excel'])->get('/backoffice/_atrezo/mal', fn (): string => 'dentro');
        $this->withoutExceptionHandling();
        $this->withoutOrganization();

        $this->expectException(InvalidArgumentException::class);

        $this->actingAs($this->owner)->get('/backoffice/_atrezo/mal');
    }

    public function test_a_suspended_subscription_cannot_grow_a_catalog(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended->value]);
        $catalog = Catalog::factory()->create();

        $this->expectException(CreationSuspendedException::class);

        app(SyncCatalogProductsAction::class)->handle($catalog, [Product::factory()->create()->getKey()], $this->owner);
    }

    public function test_a_null_ceiling_per_catalog_never_blocks(): void
    {
        $this->plan->forceFill(['max_products_per_catalog' => null])->save();
        $catalog = Catalog::factory()->create();

        app(SyncCatalogProductsAction::class)->handle($catalog, Product::factory()->count(5)->create()->modelKeys(), $this->owner);

        $this->assertSame(5, $catalog->products()->count());
    }

    private function image(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;
        imagepng(imagecreatetruecolor(4, 4), $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function mediaRow(Product $product, int $bytes): void
    {
        DB::table('media')->insert([
            'model_type' => $product->getMorphClass(),
            'model_id' => $product->getKey(),
            'uuid' => (string) Str::uuid(),
            'collection_name' => Product::IMAGES,
            'name' => 'previa',
            'file_name' => 'previa.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => $bytes,
            'manipulations' => '[]',
            'custom_properties' => json_encode(['organization_id' => $this->organization->getKey()]),
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'order_column' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function catalogWithAProduct(): Catalog
    {
        $catalog = Catalog::factory()->create();
        $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

        return $catalog;
    }

    private function productData(string $name, string $sku): ProductData
    {
        return new ProductData(
            name: $name,
            sku: $sku,
            slug: null,
            description: null,
            price: new Money(25_000),
            compareAtPrice: null,
            downPayment: new Money(5_000),
            installmentsCount: 4,
            frequencyDays: 15,
            stock: 3,
        );
    }

    private function heavyImage(): UploadedFile
    {
        $image = imagecreatetruecolor(700, 700);

        for ($x = 0; $x < 700; $x++) {
            for ($y = 0; $y < 700; $y++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }

        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-pesada.png';
        imagepng($image, $path, 0);

        return new UploadedFile($path, 'pesada.png', 'image/png', null, true);
    }
}
