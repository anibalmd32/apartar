<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Category;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\User;
use App\Support\Settings;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Los productos por HTTP (`REQ-BOF-020`).
 *
 * Lo que la prueba de la acción no ve: las seis rutas, qué llega a las props
 * y **dónde aterriza cada `422`**. La matriz de las seis reglas de coherencia
 * está en `CoherentFinancingTermsTest`, que es donde caben sus bordes; aquí se
 * comprueba que el rechazo llega **al campo** que hay que corregir, que es lo
 * que el gate pide y lo único que solo se ve atravesando el `FormRequest`.
 */
final class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * RB-9 · el listado trae los productos con su categoría y **en cuántos
     * catálogos está cada uno** (`REQ-CAT-007` RB-6).
     */
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

    /**
     * RB-9 · los filtros acotan, y viajan en la URL.
     */
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

    /**
     * **RB-4 · los defaults de la organización llegan al formulario**, y no
     * son constantes: cada negocio fija los suyos una vez.
     */
    public function test_the_form_carries_the_organization_defaults(): void
    {
        $this->inTenant(function (): void {
            // Por el modelo y no con un `update()` crudo: `value` es JSON y su
            // cast vive en el modelo. Y `forget()` después, porque `Settings`
            // cachea por organización y la escritura de verdad la invalida
            // desde su acción.
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
                ->where('defaults.frequencyDays', 30));
    }

    /**
     * **RB-7 · la previsualización llega calculada por el dominio**, con las
     * cuotas ya repartidas y **ya formateadas**: el `.tsx` no recibe ninguna
     * cifra con la que pueda dividir.
     */
    public function test_the_form_carries_the_plan_computed_by_the_domain(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear?price=250.00&down_payment=50.00&installments_count=5&frequency_days=15')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plan.installmentsCount', 5)
                ->where('plan.frequencyDays', 15)
                ->where('plan.downPayment.usd', '$50.00')
                ->where('plan.installmentAmount.usd', '$40.00')
                ->where('plan.hasUnevenLastInstallment', false)
                ->where('plan.financedAmount.usd', '$200.00'));
    }

    /**
     * Y el residuo va a la última cuota, que es lo que la pantalla dice con
     * esas palabras en vez de enseñar una media que no se cobra.
     */
    public function test_the_plan_puts_the_remainder_in_the_last_installment(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear?price=100.00&down_payment=0.00&installments_count=3&frequency_days=15')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plan.installmentAmount.usd', '$33.33')
                ->where('plan.lastInstallmentAmount.usd', '$33.34')
                ->where('plan.hasUnevenLastInstallment', true));
    }

    /**
     * Con términos imposibles no se pinta un plan inventado: es el estado
     * normal de un formulario a medio escribir.
     */
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

    /**
     * SP-1 y SP-2 · los dos rechazos cruzados llegan **al campo que hay que
     * corregir**, no al precio.
     */
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

    /**
     * **SP-8 · más de dos decimales se rechaza, no se redondea.** Redondear en
     * silencio es decidir por el negocio el precio de lo que vende.
     */
    public function test_an_amount_with_more_than_two_decimals_is_rejected(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload(['price' => '19.999']))
            ->assertSessionHasErrors('price');

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    /**
     * SP-4 · el mismo SKU dos veces en la misma organización es `422`.
     */
    public function test_a_duplicate_sku_in_the_same_organization_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/backoffice/productos', $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload(['name' => 'Otro']))
            ->assertSessionHasErrors('sku');
    }

    /**
     * SP-5 · editar el enlace de un producto publicado es `422`, en su campo.
     */
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

    /**
     * RB-10 · archivar deja el producto fuera de la vista sin borrarlo.
     */
    public function test_archiving_a_product_works_from_the_listing(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->published()->create());
        $this->recalculateUsage($this->organization);

        $this->actingAs($this->owner())
            ->post("/backoffice/productos/{$product->getKey()}/archivar")
            ->assertRedirect('/backoffice/productos');

        $this->assertSame(ProductStatus::Archived, $product->refresh()->status);
    }

    /**
     * `REQ-BOF-021` RB-8 · la ficha técnica se guarda **con el producto**, en
     * la misma petición, y vuelve al formulario en su orden.
     */
    public function test_the_specification_sheet_is_saved_with_the_product(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload([
                'specifications' => [
                    ['group' => 'Motor', 'label' => 'Potencia', 'value' => '750 W'],
                    ['group' => '', 'label' => 'Material', 'value' => 'Acero'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $product = $this->inTenant(fn (): Product => Product::query()->sole());

        $this->actingAs($owner)
            ->get("/backoffice/productos/{$product->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('product.specifications', 2)
                ->where('product.specifications.0.label', 'Potencia')
                ->where('product.specifications.0.group', 'Motor')
                ->where('product.specifications.1.label', 'Material')
                // La sección vacía llega como `null` y no como cadena: de otro
                // modo, guardar sin tocar nada crearía una sección sin nombre.
                ->where('product.specifications.1.group', null));
    }

    /**
     * SP-1 · la fila sin característica se rechaza **señalando su índice**, que
     * es lo que hace utilizable un formulario de cincuenta filas.
     */
    public function test_a_row_without_a_label_is_rejected_with_its_index(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload([
                'specifications' => [
                    ['group' => '', 'label' => 'Material', 'value' => 'Acero'],
                    ['group' => '', 'label' => '', 'value' => 'Sin característica'],
                ],
            ]))
            ->assertSessionHasErrors('specifications.1.label');

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    /**
     * RB-2 · los dos topes de columna, cada uno en su campo.
     */
    public function test_a_row_over_the_column_limits_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload([
                'specifications' => [['group' => '', 'label' => str_repeat('a', 61), 'value' => 'Acero']],
            ]))
            ->assertSessionHasErrors('specifications.0.label');

        $this->actingAs($owner)
            ->post('/backoffice/productos', $this->payload([
                'specifications' => [['group' => '', 'label' => 'Material', 'value' => str_repeat('a', 501)]],
            ]))
            ->assertSessionHasErrors('specifications.0.value');
    }

    /**
     * RB-3 y SP-2 · 51 filas se rechazan. El tope es **técnico**: no pasa por
     * el plan y no se desbloquea pagando.
     */
    public function test_more_than_fifty_rows_are_rejected(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/productos', $this->payload([
                'specifications' => $this->specificationRows(51),
            ]))
            ->assertSessionHasErrors('specifications');

        $this->assertSame(0, $this->inTenant(fn (): int => Product::query()->count()));
    }

    /**
     * SP-3 · guardar con cero filas **borra la ficha entera** y no falla.
     */
    public function test_saving_with_zero_rows_clears_the_sheet(): void
    {
        $product = $this->inTenant(function (): Product {
            $product = Product::factory()->create(['sku' => 'BOSCH-01', 'slug' => 'taladro-bosch']);

            ProductSpecification::factory()->forProduct($product)->create(['label' => 'Material', 'value' => 'Acero']);

            return $product;
        });

        $this->actingAs($this->owner())
            ->put("/backoffice/productos/{$product->getKey()}", $this->payload(['specifications' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->inTenant(fn (): int => ProductSpecification::query()->count()));
    }

    /**
     * RB-6 · el formulario trae las secciones que el negocio ya usa, para
     * autocompletar.
     */
    public function test_the_form_carries_the_group_suggestions(): void
    {
        $this->inTenant(function (): void {
            $product = Product::factory()->create();

            ProductSpecification::factory()->forProduct($product)->create(['group' => 'Motor']);
            ProductSpecification::factory()->forProduct($product)->create(['group' => 'Acabado']);
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('specificationGroups', ['Acabado', 'Motor']));
    }

    /**
     * SP-11 · el producto de otra organización da `404`, no `403`: no se
     * confirma que exista.
     */
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

    /**
     * De los dos roles: el inventario es operación diaria.
     */
    public function test_a_member_manages_products(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)->get('/backoffice/productos')->assertOk();
        $this->actingAs($member)->post('/backoffice/productos', $this->payload())->assertSessionHasNoErrors();
    }

    /**
     * **RB-11 · con la organización suspendida no se crean ni se editan
     * productos.** Lo que `REQ-ORG-008` RB-3 conserva es cobrar y aprobar
     * comprobantes; el inventario espera.
     */
    public function test_a_suspended_organization_cannot_create_or_edit_products(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create(['sku' => 'BOSCH-01']));

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $owner = $this->owner();

        $this->actingAs($owner)->get('/backoffice/productos/crear')->assertForbidden();
        $this->actingAs($owner)->post('/backoffice/productos', $this->payload())->assertForbidden();
        $this->actingAs($owner)->put("/backoffice/productos/{$product->getKey()}", $this->payload())->assertForbidden();
    }

    /**
     * El formulario de edición devuelve los importes **en dólares con dos
     * decimales**, que es como se escriben y como se vuelven a enviar: sin
     * esto, editar obligaría a traducir céntimos en el `.tsx`.
     */
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

    /**
     * **`REQ-CAT-007` SP-3 · publicar incompleto llega como `422` con la lista
     * de lo que falta.**
     *
     * Y llega dos veces: el resumen bajo `status`, que es lo que pinta el
     * listado desde el que se pulsó, y el detalle en cada campo, que es lo que
     * lee el formulario al abrir el producto para arreglarlo. Las dos formas
     * salen de la misma lista, así que no pueden discrepar.
     */
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

    /**
     * SP-4 · desde el archivo solo se vuelve a **borrador**, y el rechazo de
     * publicar un archivado atraviesa la ruta como `422`.
     */
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

    /**
     * Despublicar desde el listado devuelve el producto a borrador y lo saca
     * de la consulta pública en el acto.
     */
    public function test_unpublishing_a_product_works_from_the_listing(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->published()->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/productos/{$product->getKey()}/despublicar")
            ->assertRedirect('/backoffice/productos');

        $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
        $this->assertNull($product->published_at);
    }

    /**
     * **SP-5 · el listado marca «Programado», y desde cuándo.**
     *
     * Las dos cosas llegan resueltas por el servidor: comparar relojes en el
     * cliente daría un sello distinto según la hora del navegador —y quien
     * manda sobre `published_at <= now()` es la consulta—, y formatear la
     * fecha allí la pintaría en la zona horaria de quien mira en vez de en la
     * del negocio.
     */
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
                // El listado va por `id` descendente: el publicado entró después.
                ->where('rows.0.name', 'Publicado')
                ->where('rows.0.scheduledFor', null)
                ->where('rows.1.name', 'Programado')
                ->where('rows.1.scheduledFor', fn (mixed $date): bool => is_string($date) && $date !== ''));
    }

    /**
     * RB-11 · con la organización suspendida el escaparate espera. Publicar es
     * moverlo, así que las cuatro transiciones quedan fuera igual que el resto
     * del inventario.
     */
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

    /**
     * SP-11 · las transiciones de un producto ajeno dan `404`, no `403`: no se
     * confirma que exista.
     */
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
     * Filas de ficha técnica tal como las manda el formulario.
     *
     * @return list<array<string, string>>
     */
    private function specificationRows(int $count): array
    {
        $rows = [];

        for ($number = 1; $number <= $count; $number++) {
            $rows[] = ['group' => '', 'label' => 'Característica '.$number, 'value' => 'Valor '.$number];
        }

        return $rows;
    }

    /**
     * Fija un ajuste de la organización del escenario.
     *
     * Se **crea** la fila en vez de actualizarla: las diez las siembra el
     * registro (`REQ-ORG-001` RB-1) y una organización hecha con su factory no
     * pasa por ahí, así que hasta ahora respondía con el `defaultValue()` de
     * la clave. Es justamente lo que hace útil este test: sin fila, los dos
     * valores del formulario serían los del enum y no los del negocio.
     */
    private function setting(SettingKey $key, mixed $value): void
    {
        OrganizationSetting::factory()->forKey($key, $value)->create();
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    /**
     * Siembra y lee dentro de la organización del escenario.
     *
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
