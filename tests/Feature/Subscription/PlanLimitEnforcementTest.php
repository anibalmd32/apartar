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
use App\Jobs\TranscodeProductImage;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Los doce puntos de aplicación de `REQ-SUB-004` RB-3 (`T-067`), uno por
 * test, más cómo responde cada rechazo.
 *
 * | Punto | Dónde se prueba |
 * |---|---|
 * | 1 · invitar o reactivar (`users`) | aquí, y `TeamInvitationTest` / `LimitEnforcerConcurrencyTest` |
 * | 2 · publicar catálogo (`catalogs`) | aquí (HTTP), `PublishCatalogActionTest` (SP-3) y `PublishCatalogConcurrencyTest` (SP-1) |
 * | 3 · crear producto (`products`), y restaurarlo | aquí |
 * | 4 · añadir producto a un catálogo (`max_products_per_catalog`) | aquí |
 * | 5 · activar apartado (`active_layaways`) | `CreateLayawayActionTest` (mensaje neutro y venta perdida) y `ProductShowTest` (RB-4) |
 * | 6 · subir imagen (`storage_mb`); el comprobante, nunca | aquí, y `ReceiptCreateTest` |
 * | 7 · crear cuenta de cobro (`payment_accounts`) | `UpsertPaymentAccountActionTest`, `PaymentAccountPageTest` |
 * | 8 · logo y color (`branding`) | `UpdateOrganizationActionTest`: se guarda siempre, se aplica según el plan |
 * | 9 a 12 · exportar, Web Push, avisar a suscriptores, analítica | aquí, por `plan-feature` (`EnforcePlanLimit`); sus rutas llegan con `T-070`, `T-083`, `T-078`, `T-085` |
 */
final class PlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

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

        // Free con techos pequeños, y un Pro público que los levanta: es el
        // plan que cada rechazo tiene que nombrar.
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

    // ------------------------------------------------------------------
    // 1 · users
    // ------------------------------------------------------------------

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

    /**
     * Desde un formulario de Inertia, el rechazo **vuelve al formulario** con
     * el mensaje en `errors.plan` y el cuerpo en la sesión, para el aviso del
     * layout (`UpgradePrompt`). Un JSON aquí se pintaría como página de error.
     */
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

    /** Y por API, el `422` con el mismo cuerpo. */
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

    // ------------------------------------------------------------------
    // 2 · catalogs
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // 3 · products
    // ------------------------------------------------------------------

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

    /** Restaurar un archivado vuelve a ocupar sitio: con el cupo agotado, no se puede. */
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

    // ------------------------------------------------------------------
    // 4 · max_products_per_catalog
    // ------------------------------------------------------------------

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

    /**
     * RB-6 · un catálogo que ya pasaba del techo —el negocio bajó de plan— se
     * reordena y se vacía; lo único que no puede es crecer.
     */
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

    // ------------------------------------------------------------------
    // 6 · storage_mb
    // ------------------------------------------------------------------

    /**
     * Una imagen que no cabe en el almacenamiento del plan no se adjunta, y
     * el formulario vuelve con el motivo. (El comprobante de un cliente, en
     * cambio, se acepta siempre: `ReceiptCreateTest`.)
     */
    public function test_point_6_an_image_over_the_storage_quota_is_not_attached(): void
    {
        $product = Product::factory()->create();
        $this->withoutOrganization();

        // Un PNG de más de 1 MB: el techo de este plan es 1 MB y la medida
        // redondea hacia arriba, así que ocuparía 2.
        $this->actingAs($this->owner)
            ->from("/backoffice/productos/{$product->getKey()}/editar")
            ->post("/backoffice/productos/{$product->getKey()}/imagenes", ['images' => [$this->heavyImage()]])
            ->assertSessionHasErrors('plan')
            ->assertSessionHas('planLimit', fn (array $body): bool => $body['limit']['metric'] === 'storage_mb');

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => $product->fresh()?->getMedia(Product::IMAGES)->count() ?? 0));
    }

    /**
     * El almacenamiento es derivado y su contador puede ir por detrás —otra
     * subida que acaba de confirmar y aún no ha vuelto a medir—: la
     * comprobación **recuenta con el contador cogido** antes de decidir. Con
     * el contador diciendo 0 y 1 MB ya en el disco, no cabe nada más.
     */
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

    /**
     * Los MB que añade un archivo se redondean **sobre el total**, no sumando
     * dos redondeos: con 1,4 MB ocupados de 2, una imagen de unos KB cabe
     * (antes: 2 + 1 = 3, rechazada). Y con varias, las que caben se quedan
     * aunque la siguiente no.
     */
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

        // La pequeña entró (cabía en el mismo MB); la pesada, no.
        $this->assertSame(['una.png'], $this->withinOrganization($this->organization, fn (): array => Media::query()
            ->where('model_id', $product->getKey())->where('file_name', '!=', 'previa.png')->pluck('file_name')->all()));
    }

    /**
     * RB-6 · un catálogo por encima del techo puede reordenarse y vaciarse,
     * pero **no recibir uno nuevo**, ni siquiera cambiándolo por otro sin
     * crecer.
     */
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

    /** El plan que se ofrece es el **más barato** que lo admite, no el primero con más sitio. */
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

    /**
     * Una visita **de Inertia de verdad** —con su cabecera— vuelve al
     * formulario, y el cuerpo del rechazo llega a las props de la página
     * siguiente, que es donde lo pinta `UpgradePrompt`.
     */
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

    /** RB-8 · con la suscripción suspendida, el formulario vuelve con el motivo a la vista. */
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

    // ------------------------------------------------------------------
    // 9 a 12 · funciones de pago, por la ruta
    // ------------------------------------------------------------------

    /**
     * SP-3 · una función que el plan no incluye responde **`403` con el
     * motivo y el plan que la trae**, nunca `404`; con el plan que la
     * incluye, la ruta pasa. Las cuatro de RB-3 que no tienen ruta todavía se
     * prueban con una de atrezo que declara el mismo middleware que llevarán.
     */
    public function test_points_9_to_12_a_paid_feature_answers_403_with_the_plan_that_has_it(): void
    {
        foreach ([PlanFeature::ExcelExport, PlanFeature::WebPush, PlanFeature::SubscriberDigest, PlanFeature::FullAnalytics] as $feature) {
            Route::middleware(['web', 'auth', 'tenant', 'plan-feature:'.$feature->value])
                ->get('/backoffice/_atrezo/'.$feature->value, fn (): string => 'dentro');
        }

        $this->withoutOrganization();

        foreach ([PlanFeature::ExcelExport, PlanFeature::WebPush, PlanFeature::SubscriberDigest, PlanFeature::FullAnalytics] as $feature) {
            $this->actingAs($this->owner)
                ->getJson('/backoffice/_atrezo/'.$feature->value)
                ->assertForbidden()
                ->assertJsonPath('feature.feature', $feature->value)
                ->assertJsonPath('feature.minimumPlanName', 'Pro')
                ->assertJsonPath('message', fn (string $message): bool => str_contains($message, mb_strtolower(mb_substr($feature->label(), 0, 1)).mb_substr($feature->label(), 1)));
        }

        $this->withinOrganization($this->organization, fn () => $this->plan->forceFill(['features' => PlanFeature::only(PlanFeature::cases())])->save());

        foreach ([PlanFeature::ExcelExport, PlanFeature::WebPush, PlanFeature::SubscriberDigest, PlanFeature::FullAnalytics] as $feature) {
            $this->actingAs($this->owner)->get('/backoffice/_atrezo/'.$feature->value)->assertOk()->assertSee('dentro');
        }
    }

    /**
     * Y una **visita de Inertia** a la página de una función que falta no
     * recibe un JSON —que pintaría como error—: vuelve con el motivo.
     */
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

    /** Una clave mal escrita en la ruta revienta en vez de dejarla abierta a todos los planes. */
    public function test_a_route_with_an_unknown_feature_fails_closed(): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'plan-feature:excel'])->get('/backoffice/_atrezo/mal', fn (): string => 'dentro');
        $this->withoutExceptionHandling();
        $this->withoutOrganization();

        $this->expectException(InvalidArgumentException::class);

        $this->actingAs($this->owner)->get('/backoffice/_atrezo/mal');
    }

    // ------------------------------------------------------------------
    // RB-8, SP-5
    // ------------------------------------------------------------------

    /** RB-8 · con la suscripción suspendida, tampoco se compone un catálogo que crece. */
    public function test_a_suspended_subscription_cannot_grow_a_catalog(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended->value]);
        $catalog = Catalog::factory()->create();

        $this->expectException(CreationSuspendedException::class);

        app(SyncCatalogProductsAction::class)->handle($catalog, [Product::factory()->create()->getKey()], $this->owner);
    }

    /** SP-5 · sin techo por catálogo, no se bloquea nunca. */
    public function test_a_null_ceiling_per_catalog_never_blocks(): void
    {
        $this->plan->forceFill(['max_products_per_catalog' => null])->save();
        $catalog = Catalog::factory()->create();

        app(SyncCatalogProductsAction::class)->handle($catalog, Product::factory()->count(5)->create()->modelKeys(), $this->owner);

        $this->assertSame(5, $catalog->products()->count());
    }

    // ------------------------------------------------------------------

    /** Un PNG pequeño de verdad. */
    private function image(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;
        imagepng(imagecreatetruecolor(4, 4), $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** Una fila de `media` ya en el disco, de ese tamaño. */
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

    /**
     * Un PNG real de algo más de 1 MB: ruido, que no se comprime.
     */
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
