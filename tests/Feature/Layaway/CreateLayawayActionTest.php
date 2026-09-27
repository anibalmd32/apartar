<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\CreateLayawayAction;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Catalog\CatalogUnavailableException;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Customer\TermsConsent;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\NotAcceptingLayawaysException;
use App\Domain\Money\Money;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantAlreadySetException;
use App\Models\Catalog;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Support\LayawayCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\AssertsStockLockOrder;
use Tests\TestCase;

/**
 * La transacción que crea un apartado (`REQ-LAY-001` salvo su superficie, y
 * `REQ-LAY-002` entero), contra la base de datos.
 *
 * Se llama a la acción como la llamará el formulario de `T-045b`: **sin tenant
 * fijado**, con el catálogo y el producto ya resueltos, para que se vea que la
 * organización sale del catálogo (RB-1). Lo que necesita dos conexiones —que
 * el bloqueo del producto serialice de verdad, y dos envíos simultáneos con la
 * misma clave— está en `tests/Feature/Concurrency/CreateLayawayConcurrencyTest`.
 */
final class CreateLayawayActionTest extends TestCase
{
    use AssertsStockLockOrder, RefreshDatabase;

    private Organization $organization;

    private Catalog $catalog;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-27 10:00:00');

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        [$this->catalog, $this->product] = $this->withinOrganization($this->organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create(['max_active_layaways' => 5]))->create();

            $catalog = Catalog::factory()->published()->create();
            $product = Product::factory()->published()->create([
                'name' => 'Nevera Mabe 14 pies',
                'sku' => 'NEV-14',
                'price_cents' => 20_000,
                'down_payment_cents' => 5_000,
                'installments_count' => 5,
                'frequency_days' => 15,
                'stock' => 3,
            ]);
            $catalog->products()->attach($product, ['position' => 1]);

            return [$catalog, $product];
        });

        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        LayawayCode::generateUsing(null);
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // El camino feliz
    // -------------------------------------------------------------------

    /**
     * Ficha, apartado, reserva y cupo, en una transacción y con la secuencia
     * de [05 §5.1].
     */
    public function test_it_creates_the_customer_the_layaway_the_reservation_and_the_usage(): void
    {
        $layaway = $this->create(quantity: 2);

        $this->assertTrue($layaway->wasRecentlyCreated);
        $this->assertTrue(LayawayCode::isWellFormed($layaway->code));

        $row = DB::table('layaways')->where('id', $layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $this->organization->getKey(), (int) $row->organization_id);
        $this->assertSame((int) $this->catalog->getKey(), (int) $row->catalog_id);
        $this->assertSame((int) $this->product->getKey(), (int) $row->product_id);
        $this->assertSame(LayawayStatus::PendingDownPayment->value, $row->status);
        $this->assertSame(2, (int) $row->quantity);
        $this->assertSame(40_000, (int) $row->total_cents);
        $this->assertSame(0, (int) $row->paid_cents);
        $this->assertSame(40_000, (int) $row->remaining_cents);
        $this->assertSame('Nevera Mabe 14 pies', $row->product_name_snapshot);
        $this->assertSame('NEV-14', $row->product_sku_snapshot);

        // RB-7 · 72 h por defecto para la inicial.
        $this->assertSame('2026-09-30 10:00:00', $row->expires_at);

        // La ficha, con su consentimiento.
        $customer = DB::table('customers')->where('id', $row->customer_id)->first();
        $this->assertNotNull($customer);
        $this->assertSame('V12345678', $customer->document_number);
        $this->assertSame('190.202.10.5', $customer->terms_ip_address);

        // La reserva y el cupo.
        $this->assertSame(1, $this->stock());
        $this->assertSame(1, $this->usage());

        // RB-8 · sin cronograma: se genera al activar (`T-046`).
        $this->assertSame(0, DB::table('installments')->count());
    }

    /**
     * RB-1 · la organización sale del catálogo, y no hay otra forma de decirla:
     * `CreateLayawayData` no tiene el campo.
     */
    public function test_the_layaway_inherits_the_organization_of_the_catalog(): void
    {
        $layaway = $this->create();

        $this->assertSame((int) $this->organization->getKey(), (int) DB::table('layaways')->where('id', $layaway->getKey())->value('organization_id'));

        $fields = array_map(
            fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(CreateLayawayData::class, '__construct'))->getParameters(),
        );

        $this->assertSame([], array_values(array_filter($fields, fn (string $field): bool => str_contains(strtolower($field), 'organization'))));
    }

    /**
     * Si hay fijada **otra** organización —un middleware que resolvió mal—, se
     * lanza en vez de crear el apartado en la que no es.
     */
    public function test_another_tenant_already_set_refuses_instead_of_crossing(): void
    {
        $neighbour = $this->neighbourOrganization();

        try {
            $this->withinOrganization($neighbour, fn (): Layaway => $this->create());
            $this->fail('Se creó un apartado con otra organización fijada.');
        } catch (TenantAlreadySetException) {
        }

        $this->assertNothingPersisted();
    }

    // -------------------------------------------------------------------
    // Las precondiciones de RB-6
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function closedCatalogs(): array
    {
        return [
            'despublicado' => [['published_at' => null]],
            'caducado' => [['published_at' => '2026-08-01 00:00:00', 'expires_at' => '2026-09-26 00:00:00']],
        ];
    }

    /**
     * RB-6.1, SP-3 · un catálogo que ya no admite apartados: `422` con una
     * frase, porque el cliente lo estaba viendo.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('closedCatalogs')]
    public function test_a_catalog_that_no_longer_accepts_layaways_is_refused(array $attributes): void
    {
        DB::table('catalogs')->where('id', $this->catalog->getKey())->update($attributes);

        $this->expectCreationToFail(CatalogUnavailableException::class, catalog: $this->catalog->fresh());
    }

    /**
     * RB-6.2, SP-4 · el producto tiene que estar **en este catálogo**: el de
     * otro catálogo del mismo negocio no, y el de otro negocio tampoco.
     */
    public function test_a_product_of_another_catalog_is_refused(): void
    {
        $other = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->published()->create(['stock' => 5]));
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->published()->create()->products()->attach($other, ['position' => 1]));

        $this->expectCreationToFail(ProductNotAvailableException::class, product: $other);
    }

    public function test_a_product_of_another_organization_is_refused(): void
    {
        $neighbours = $this->withinOrganization($this->neighbourOrganization(), fn (): Product => Product::factory()->published()->create(['stock' => 5]));

        $this->expectCreationToFail(ProductNotAvailableException::class, product: $neighbours);
    }

    /**
     * SP-4 · un producto archivado mientras el formulario estaba abierto.
     */
    public function test_a_product_archived_meanwhile_is_refused(): void
    {
        DB::table('products')->where('id', $this->product->getKey())->update(['status' => ProductStatus::Archived->value]);

        $this->expectCreationToFail(ProductNotAvailableException::class);
    }

    /**
     * RB-6.3, SP-1 · **stock insuficiente no persiste nada**: ni ficha, ni
     * apartado, ni reserva, ni cupo. Y el rechazo dice cuántas quedan.
     */
    public function test_insufficient_stock_persists_nothing(): void
    {
        try {
            $this->create(quantity: 4);
            $this->fail('Se apartaron cuatro unidades de tres.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(3, $exception->available);
        }

        $this->assertNothingPersisted();
    }

    /**
     * RB-6.4, SP-2 · el cupo agotado da un mensaje **neutro**: ni el plan, ni
     * el techo. El motivo real viaja dentro, para el negocio.
     */
    public function test_an_exhausted_plan_gives_a_neutral_message(): void
    {
        $this->setUsage(5);

        try {
            $this->create();
            $this->fail('Se creó un apartado por encima del techo del plan.');
        } catch (NotAcceptingLayawaysException $exception) {
            $this->assertSame('Este negocio no puede recibir nuevos apartados en este momento.', $exception->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('plan', $exception->getMessage());
            $this->assertInstanceOf(PlanLimitReachedException::class, $exception->getPrevious());
            $this->assertSame(UsageMetric::ActiveLayaways, $exception->getPrevious()->metric);
        }

        $this->assertNothingPersisted(usage: 5);
    }

    /**
     * El cupo va **después** del stock: sin stock y sin cupo, lo que se le
     * dice al cliente es lo del stock, que es lo que puede resolver.
     */
    public function test_the_stock_is_checked_before_the_plan(): void
    {
        $this->setUsage(5);

        $this->expectCreationToFail(InsufficientStockException::class, quantity: 4, usage: 5);
    }

    /**
     * RB-6.5, SP-5 · la organización suspendida se resuelve como un catálogo
     * que ya no está disponible.
     */
    public function test_a_suspended_organization_is_resolved_as_an_unavailable_catalog(): void
    {
        DB::table('organizations')->where('id', $this->organization->getKey())->update(['status' => 'suspended', 'suspended_at' => now()]);

        $this->expectCreationToFail(CatalogUnavailableException::class);
    }

    /**
     * Una organización dada de baja —borrada lógicamente— tampoco recibe
     * apartados, y el cliente oye lo mismo que con una suspendida.
     */
    public function test_a_deleted_organization_is_resolved_as_an_unavailable_catalog(): void
    {
        DB::table('organizations')->where('id', $this->organization->getKey())->update(['deleted_at' => now()]);

        $this->expectCreationToFail(CatalogUnavailableException::class);
    }

    // -------------------------------------------------------------------
    // RB-12 · idempotencia
    // -------------------------------------------------------------------

    /**
     * El cliente pulsa dos veces: el segundo envío devuelve el **mismo**
     * apartado, sin reservar otra unidad ni gastar otro cupo ni tocar la ficha.
     */
    public function test_a_second_submission_with_the_same_key_returns_the_same_layaway(): void
    {
        $key = (string) Str::uuid();

        $first = $this->create(key: $key);
        $second = $this->create(key: $key, phone: '0424-7654321');

        $this->assertSame($first->code, $second->code);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertFalse($second->wasRecentlyCreated, 'T-045b usa wasRecentlyCreated para no mandar el correo dos veces.');

        $this->assertSame(1, DB::table('layaways')->count());
        $this->assertSame(2, $this->stock());
        $this->assertSame(1, $this->usage());
        $this->assertSame('+584141234567', DB::table('customers')->value('phone'), 'El segundo envío sobrescribió la ficha.');
    }

    /**
     * Y con la **última unidad**: el segundo envío no es un `422` de stock,
     * es el mismo apartado.
     */
    public function test_a_second_submission_for_the_last_unit_is_not_a_stock_error(): void
    {
        DB::table('products')->where('id', $this->product->getKey())->update(['stock' => 1]);
        $key = (string) Str::uuid();

        $first = $this->create(key: $key);
        $second = $this->create(key: $key);

        $this->assertSame($first->code, $second->code);
        $this->assertSame(0, $this->stock());
    }

    /**
     * La clave es por organización: la misma en otro negocio es otro apartado.
     */
    public function test_the_same_key_in_another_organization_is_another_layaway(): void
    {
        $key = (string) Str::uuid();
        $ours = $this->create(key: $key);

        $neighbour = $this->neighbourOrganization();
        [$catalog, $product] = $this->withinOrganization($neighbour, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create())->create();
            $catalog = Catalog::factory()->published()->create();
            $product = Product::factory()->published()->create(['stock' => 2]);
            $catalog->products()->attach($product, ['position' => 1]);

            return [$catalog, $product];
        });
        $this->withoutOrganization();

        $theirs = $this->create(key: $key, catalog: $catalog, product: $product);

        $this->assertNotSame($ours->getKey(), $theirs->getKey());
        $this->assertTrue($theirs->wasRecentlyCreated);
    }

    // -------------------------------------------------------------------
    // RB-9 · el código
    // -------------------------------------------------------------------

    public function test_the_code_has_no_ambiguous_characters(): void
    {
        foreach (range(1, 200) as $ignored) {
            $code = LayawayCode::generate();

            $this->assertTrue(LayawayCode::isWellFormed($code), $code);
            $this->assertSame(0, preg_match('/[01OI]/', substr($code, 3)), $code);
        }

        $this->assertSame(32, strlen(LayawayCode::ALPHABET));
        $this->assertSame(32, count(array_unique(str_split(LayawayCode::ALPHABET))));
    }

    /**
     * Único **global**: el código de un apartado de otro negocio también
     * choca, y se reintenta con otro.
     */
    public function test_a_code_collision_is_retried_even_across_organizations(): void
    {
        $taken = 'AP-TAKEN222';
        $this->neighbourLayawayWithCode($taken);

        $sequence = [$taken, 'AP-FRESH333'];
        LayawayCode::generateUsing(function () use (&$sequence): string {
            return array_shift($sequence) ?? throw new LogicException('Se pidieron más códigos de la cuenta.');
        });

        $layaway = $this->create();

        $this->assertSame('AP-FRESH333', $layaway->code);
    }

    /**
     * SP-12 · **rollback completo**. Cuando el fallo llega después de resolver
     * la ficha —aquí, cinco colisiones seguidas del código—, no queda nada:
     * ni la ficha nueva, ni el apartado, ni la reserva, ni el cupo.
     */
    public function test_a_failure_after_the_customer_was_resolved_rolls_everything_back(): void
    {
        $this->neighbourLayawayWithCode('AP-TAKEN222');
        LayawayCode::generateUsing(fn (): string => 'AP-TAKEN222');

        try {
            $this->create();
            $this->fail('Se creó un apartado sin código libre.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('código de apartado libre', $exception->getMessage());
        }

        $this->assertNothingPersisted();
    }

    // -------------------------------------------------------------------
    // El cliente
    // -------------------------------------------------------------------

    /**
     * `REQ-IDN-001` RB-3, SP-7 · sin correo funciona.
     */
    public function test_it_works_without_an_email(): void
    {
        $layaway = $this->create(email: null);

        $this->assertNull(DB::table('customers')->where('id', $layaway->customer_id)->value('email'));
    }

    /**
     * Y un cliente que vuelve sin escribir el correo **no pierde** el que
     * tenía (corrección de RB-4 de `REQ-IDN-001`).
     */
    public function test_a_returning_customer_without_email_keeps_it(): void
    {
        $this->create(email: 'maria@ejemplo.test');
        $this->create(email: null);

        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame('maria@ejemplo.test', DB::table('customers')->value('email'));
    }

    // -------------------------------------------------------------------
    // REQ-LAY-002 · las condiciones congeladas
    // -------------------------------------------------------------------

    /**
     * RB-4 · el caso central del requisito: crear, **subir el precio,
     * renombrar y archivar** el producto, y el apartado sigue diciendo lo
     * mismo. Es la promesa del producto: el cliente paga lo que aceptó.
     */
    public function test_raising_the_price_and_archiving_the_product_does_not_alter_the_layaway(): void
    {
        $layaway = $this->create(quantity: 2);

        DB::table('products')->where('id', $this->product->getKey())->update([
            'name' => 'Nevera Mabe 14 pies (modelo 2027)',
            'sku' => 'NEV-14-2027',
            'price_cents' => 25_000,
            'down_payment_cents' => 8_000,
            'installments_count' => 10,
            'frequency_days' => 30,
            'status' => ProductStatus::Archived->value,
        ]);

        $fresh = $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->findOrFail($layaway->getKey()));
        $terms = $fresh->terms();

        $this->assertEquals(new Money(20_000), $terms->unitPrice);
        $this->assertEquals(new Money(5_000), $terms->downPayment);
        $this->assertSame(2, $terms->quantity);
        $this->assertSame(5, $terms->installmentsCount);
        $this->assertSame(15, $terms->frequencyDays);
        $this->assertEquals(new Money(40_000), $terms->total());
        $this->assertEquals(new Money(10_000), $terms->totalDownPayment());
        $this->assertEquals(new Money(30_000), $terms->financedAmount());
        $this->assertEquals(new Money(40_000), $fresh->total_cents);
        $this->assertEquals(new Money(40_000), $fresh->remaining_cents);
        $this->assertSame('Nevera Mabe 14 pies', $fresh->product_name_snapshot);
        $this->assertSame('NEV-14', $fresh->product_sku_snapshot);
    }

    /**
     * RB-3 · dos unidades no son diez cuotas: son las mismas cinco por el
     * doble. Y el reparto sale del total, nunca de multiplicar un reparto.
     */
    public function test_installments_and_frequency_are_not_multiplied_by_the_quantity(): void
    {
        $layaway = $this->create(quantity: 2);

        $this->assertSame(5, $layaway->installments_count);
        $this->assertSame(15, $layaway->frequency_days);
        $this->assertEquals(new Money(20_000), $layaway->unit_price_cents);
        $this->assertEquals(new Money(5_000), $layaway->down_payment_cents);

        $plan = $layaway->terms()->financingPlan();
        $this->assertNotNull($plan);
        $this->assertSame(5, $plan->installmentsCount);
        $this->assertEquals(new Money(6_000), $plan->installmentAmount());
    }

    /**
     * RB-8 · ninguna acción modifica el snapshot después: guardarlo cambiado
     * lanza, columna a columna.
     */
    public function test_the_frozen_terms_cannot_be_rewritten(): void
    {
        $layaway = $this->create();

        $changes = [
            'unit_price_cents' => 1,
            'down_payment_cents' => 1,
            'quantity' => 3,
            'installments_count' => 12,
            'frequency_days' => 7,
            'total_cents' => 1,
            'product_name_snapshot' => 'Otro nombre',
            'product_sku_snapshot' => 'OTRO',
            'code' => 'AP-OTRO2222',
        ];

        foreach ($changes as $column => $value) {
            $this->withinOrganization($this->organization, function () use ($layaway, $column, $value): void {
                $model = Layaway::query()->findOrFail($layaway->getKey());

                try {
                    $model->forceFill([$column => $value])->save();
                    $this->fail("Se reescribió {$column} de un apartado.");
                } catch (LogicException $exception) {
                    $this->assertStringContainsString($column, $exception->getMessage());
                }
            });
        }
    }

    // -------------------------------------------------------------------
    // El orden de bloqueo
    // -------------------------------------------------------------------

    /**
     * `REQ-LAY-003` RB-8 · la primera fila del tenant que la transacción
     * bloquea o escribe es la del producto. Con un cliente que ya existía,
     * para que `ResolveCustomerAction` tome también su bloqueo y escriba.
     */
    public function test_the_product_is_locked_before_any_other_row(): void
    {
        $this->create(phone: '0414-1234567');

        $this->assertLocksTheProductFirst(fn (): Layaway => $this->create(phone: '0424-7654321'), 'CreateLayawayAction');
    }

    // -------------------------------------------------------------------
    // Lo aplazado, en rojo el día que llegue
    // -------------------------------------------------------------------

    /**
     * RB-4 · con sesión de cuenta, la ficha se vincula (`T-077`) y queda
     * suscrita al catálogo (`T-075`). `Account` es de H6.
     */
    public function test_the_account_link_is_still_pending(): void
    {
        $this->assertFileDoesNotExistAnywhere(['Account.php', 'LinkAccountAction.php', 'SubscribeToCatalogAction.php'],
            'Llegó la cuenta de consumidor (H6): añade ?Account a CreateLayawayData y, en CreateLayawayAction, llama a '
            .'LinkAccountAction y SubscribeToCatalogAction donde está el comentario de RB-4; después borra este caso.',
        );
    }

    /**
     * RB-11 · `layaway_created` es de `T-084`.
     */
    public function test_the_layaway_created_telemetry_is_still_pending(): void
    {
        $this->assertFileDoesNotExistAnywhere(['TelemetryEventName.php'],
            'El bus de telemetría ya existe (T-084): emite layaway_created al confirmar CreateLayawayAction, con '
            .'catalog_id, organization_id y el origen, y borra este caso.',
        );
    }

    // -------------------------------------------------------------------

    private function create(
        int $quantity = 1,
        ?string $key = null,
        string $phone = '0414-1234567',
        ?string $email = 'maria@ejemplo.test',
        ?Catalog $catalog = null,
        ?Product $product = null,
    ): Layaway {
        return app(CreateLayawayAction::class)->handle($this->data($quantity, $key, $phone, $email, $catalog, $product));
    }

    private function data(
        int $quantity = 1,
        ?string $key = null,
        string $phone = '0414-1234567',
        ?string $email = 'maria@ejemplo.test',
        ?Catalog $catalog = null,
        ?Product $product = null,
    ): CreateLayawayData {
        return new CreateLayawayData(
            catalog: $catalog ?? $this->catalog,
            product: $product ?? $this->product,
            quantity: $quantity,
            contact: CustomerContactData::fromInput('V-12.345.678', 'María González', $phone, $email),
            consent: TermsConsent::acceptedNowFrom('190.202.10.5'),
            idempotencyKey: $key ?? (string) Str::uuid(),
        );
    }

    /**
     * @param  class-string<\Throwable>  $exception
     */
    private function expectCreationToFail(
        string $exception,
        int $quantity = 1,
        ?Catalog $catalog = null,
        ?Product $product = null,
        int $usage = 0,
    ): void {
        try {
            $this->create(quantity: $quantity, catalog: $catalog, product: $product);
            $this->fail("Se creó el apartado y se esperaba {$exception}.");
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown, $thrown->getMessage());
        }

        $this->assertNothingPersisted(usage: $usage);
    }

    private function assertNothingPersisted(int $usage = 0): void
    {
        $this->assertSame(0, DB::table('layaways')->where('organization_id', $this->organization->getKey())->count(), 'Quedó un apartado.');
        $this->assertSame(0, DB::table('customers')->where('organization_id', $this->organization->getKey())->count(), 'Quedó una ficha.');
        $this->assertSame(3, $this->stock(), 'Quedó stock reservado.');
        $this->assertSame($usage, $this->usage(), 'Se movió el cupo del plan.');
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', $this->product->getKey())->value('stock');
    }

    private function usage(): int
    {
        return (int) DB::table('usage_counters')
            ->where('organization_id', $this->organization->getKey())
            ->where('metric', UsageMetric::ActiveLayaways->value)
            ->value('value');
    }

    private function setUsage(int $value): void
    {
        $this->withinOrganization($this->organization, fn () => UsageCounter::query()->updateOrCreate(
            ['metric' => UsageMetric::ActiveLayaways],
            ['value' => $value, 'computed_at' => now()],
        ));
    }

    private function neighbourLayawayWithCode(string $code): void
    {
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($neighbour, function () use ($code): void {
            $product = Product::factory()->create();
            $customerId = DB::table('customers')->insertGetId([
                'organization_id' => app(\App\Domain\Tenancy\TenantContext::class)->organizationId(),
                'document_number' => 'V99999999',
                'document_kind' => 'cedula',
                'full_name' => 'Cliente del vecino',
                'phone' => '+584141112233',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            (new Layaway)->forceFill([
                'code' => $code,
                'idempotency_key' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'unit_price_cents' => 1_000,
                'down_payment_cents' => 100,
                'installments_count' => 3,
                'frequency_days' => 15,
                'product_name_snapshot' => $product->name,
                'product_sku_snapshot' => $product->sku,
                'total_cents' => 1_000,
                'remaining_cents' => 1_000,
                'status' => LayawayStatus::PendingDownPayment,
            ])->save();
        });

        $this->withoutOrganization();
    }

    /**
     * @param  list<string>  $files
     */
    private function assertFileDoesNotExistAnywhere(array $files, string $message): void
    {
        $found = Finder::create()->files()->in([app_path(), resource_path('js')])->name($files);

        $this->assertSame(0, iterator_count($found), $message);
    }
}
