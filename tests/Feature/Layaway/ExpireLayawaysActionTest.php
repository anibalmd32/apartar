<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\CreateLayawayAction;
use App\Actions\Layaway\CreateLayawayData;
use App\Actions\Layaway\ExpireLayawaysAction;
use App\Domain\Customer\TermsConsent;
use App\Domain\Layaway\ExpirationOutcome;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Events\Layaway\LayawayExpired;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\Catalog;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsStockLockOrder;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * `ExpireLayawaysAction` contra la base de datos (`REQ-LAY-006`).
 *
 * Se llama con el tenant fijado, como la llama el job por cada organización.
 * Lo que se afirma es lo que queda en las filas: el estado, el stock devuelto,
 * el cupo liberado y el evento, y lo que **no** se toca —un apartado con
 * comprobante en revisión, uno ya activo, uno con el plazo aún abierto—.
 */
final class ExpireLayawaysActionTest extends TestCase
{
    use AssertsStockLockOrder, CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    /** @var list<LayawayTransitioned> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = Organization::factory()->create();

        Event::listen(LayawayTransitioned::class, function (LayawayTransitioned $transition): void {
            $this->dispatched[] = $transition;
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-1 y RB-4: vencido y sin comprobantes, expira; la unidad vuelve al
     * stock, el cupo se libera y sale `LayawayExpired`.
     */
    public function test_a_pending_layaway_past_its_deadline_expires_and_gives_everything_back(): void
    {
        $product = $this->product(stock: 4);
        $layaway = $this->pendingUntil('2026-09-13 10:00:00', $product);
        $this->assertSame(3, $this->stockOf($product));
        $this->setCounter(2);

        $outcome = $this->expireAt('2026-09-13 10:05:00');

        $this->assertSame(['expired' => 1, 'held' => 0], $outcome->toArray());
        $this->assertSame('expired', $this->statusOf($layaway));
        $this->assertSame(4, $this->stockOf($product));
        $this->assertSame(1, $this->counter());
        $this->assertNotNull(DB::table('layaways')->where('id', $layaway->getKey())->value('stock_released_at'));
        $this->assertSame([LayawayExpired::class], array_map(fn (LayawayTransitioned $event): string => $event::class, $this->dispatched));
        $this->assertSame('2026-09-13 10:00:00', DB::table('layaways')->where('id', $layaway->getKey())->value('expires_at'), 'Expirar no mueve el plazo.');
    }

    /**
     * RB-1: el plazo es estricto. Al minuto exacto todavía no, un segundo
     * después sí.
     */
    public function test_the_deadline_itself_is_not_yet_expired(): void
    {
        $layaway = $this->pendingUntil('2026-09-13 10:00:00');

        $this->assertSame(0, $this->expireAt('2026-09-13 10:00:00')->expired);
        $this->assertSame('pending_down_payment', $this->statusOf($layaway));

        $this->assertSame(1, $this->expireAt('2026-09-13 10:00:01')->expired);
        $this->assertSame('expired', $this->statusOf($layaway));
    }

    /**
     * RB-2 y SP-1: con un comprobante en revisión no expira, aunque el plazo
     * haya pasado. Se cuenta como retenido para que el job lo diga.
     */
    public function test_a_receipt_under_review_holds_the_expiration(): void
    {
        $product = $this->product(stock: 4);
        $held = $this->pendingUntil('2026-09-13 09:59:00', $product);
        $free = $this->pendingUntil('2026-09-13 09:59:00', $product);

        $this->app->instance(LayawayReceipts::class, new class((int) $held->getKey()) extends LayawayReceipts
        {
            public function __construct(private readonly int $withReceipt) {}

            public function holdExpiration(Layaway $layaway): bool
            {
                return (int) $layaway->getKey() === $this->withReceipt;
            }
        });

        $outcome = $this->expireAt('2026-09-13 10:05:00');

        $this->assertSame(['expired' => 1, 'held' => 1], $outcome->toArray());
        $this->assertSame('pending_down_payment', $this->statusOf($held));
        $this->assertSame('expired', $this->statusOf($free));
        $this->assertSame(3, $this->stockOf($product), 'Solo vuelve la unidad del que expiró.');
    }

    /**
     * SP-6: uno ya activo con el plazo pasado es historia, no un candidato.
     * Y uno con el plazo abierto no se toca.
     */
    public function test_active_layaways_and_open_windows_are_left_alone(): void
    {
        $active = $this->pendingUntil('2026-09-11 10:00:00');
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($active));
        $open = $this->pendingUntil('2026-09-20 10:00:00');
        $this->dispatched = [];

        $outcome = $this->expireAt('2026-09-13 10:00:00');

        $this->assertSame(['expired' => 0, 'held' => 0], $outcome->toArray());
        $this->assertSame('active', $this->statusOf($active));
        $this->assertSame('pending_down_payment', $this->statusOf($open));
        $this->assertSame([], $this->dispatched);
    }

    /**
     * RB-4 y SP-4: dos pasadas no devuelven dos veces la unidad, ni liberan
     * dos veces el cupo, ni emiten dos eventos.
     */
    public function test_running_twice_gives_back_the_stock_only_once(): void
    {
        $product = $this->product(stock: 2);
        $this->pendingUntil('2026-09-12 10:00:00', $product);
        $this->setCounter(1);

        $this->expireAt('2026-09-13 10:00:00');
        $second = $this->expireAt('2026-09-13 11:00:00');

        $this->assertSame(0, $second->expired);
        $this->assertSame(2, $this->stockOf($product));
        $this->assertSame(0, $this->counter());
        $this->assertCount(1, $this->dispatched);
    }

    /**
     * RB-7 y SP-5: cada apartado expira con la ventana de **su** organización,
     * congelada al crearlo. Dos negocios, 72 h y 48 h, el mismo instante de
     * creación: a las 60 h solo ha expirado el de 48.
     */
    public function test_each_layaway_uses_the_window_of_its_own_organization(): void
    {
        $short = $this->neighbourOrganization();
        $this->withinOrganization($short, fn () => OrganizationSetting::factory()->forKey(SettingKey::DownPaymentWindowHours, 48)->create());

        $ours = $this->createThroughTheForm($this->organization);
        $theirs = $this->createThroughTheForm($short);

        // El ajuste cambia después: no afecta a los ya creados (SP-5).
        $this->withinOrganization($this->organization, fn () => OrganizationSetting::factory()->forKey(SettingKey::DownPaymentWindowHours, 24)->create());

        CarbonImmutable::setTestNow('2026-09-12 22:00:00');
        $this->withinOrganization($this->organization, fn () => app(ExpireLayawaysAction::class)->handle(CarbonImmutable::now()));
        $this->withinOrganization($short, fn () => app(ExpireLayawaysAction::class)->handle(CarbonImmutable::now()));

        $this->assertSame('pending_down_payment', $this->statusOf($ours));
        $this->assertSame('expired', $this->statusOf($theirs));
    }

    /**
     * RB-8: con la organización suspendida, expira igual. Un negocio que no
     * pagó su plan no retiene la mercancía de nadie.
     */
    public function test_it_expires_with_the_organization_suspended(): void
    {
        $layaway = $this->pendingUntil('2026-09-12 10:00:00');
        DB::table('organizations')->where('id', $this->organization->getKey())->update(['status' => OrganizationStatus::Suspended->value]);
        $this->organization->refresh();

        $this->assertSame(1, $this->expireAt('2026-09-13 10:00:00')->expired);
        $this->assertSame('expired', $this->statusOf($layaway));
    }

    /**
     * `REQ-LAY-003` RB-8: el producto se bloquea antes que el apartado. Es el
     * único test que ve un orden invertido: el abrazo no sale en la suite.
     */
    public function test_it_locks_the_product_before_the_layaway(): void
    {
        $this->pendingUntil('2026-09-12 10:00:00');

        $this->assertLocksTheProductFirst(
            fn () => $this->expireAt('2026-09-13 10:00:00'),
            'ExpireLayawaysAction',
        );
    }

    /**
     * RB-5: los avisos —12 h antes y al expirar— son de `T-083`, con el
     * registro de idempotencia de `REQ-NOT-002`. El día que exista el
     * despachador de recordatorios, este caso se pone rojo.
     */
    public function test_the_expiration_notices_are_still_pending(): void
    {
        $this->assertFileDoesNotExist(
            app_path('Actions/Notification/DispatchDueRemindersAction.php'),
            'DispatchDueRemindersAction ya existe (T-083): tiene que emitir down_payment_reminder 12 h antes de '
            .'expires_at (REQ-LAY-006 RB-5, REQ-NOT-002 RB-6) con su registro idempotente —ojo: installment_id es '
            .'NULL para este tipo, y un UNIQUE con NULL no deduplica en MySQL—, y el aviso al expirar va en el '
            .'listener de LayawayExpired. Hazlo y borra este caso.',
        );
    }

    private function expireAt(string $instant): ExpirationOutcome
    {
        return $this->withinOrganization(
            $this->organization,
            fn (): ExpirationOutcome => app(ExpireLayawaysAction::class)->handle(CarbonImmutable::parse($instant)),
        );
    }

    /**
     * Un apartado sembrado esperando la inicial, con ese plazo.
     */
    private function pendingUntil(string $expiresAt, ?Product $product = null): Layaway
    {
        $layaway = $this->layawayIn($this->organization, product: $product);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => $expiresAt]);

        return $layaway;
    }

    private function product(int $stock): Product
    {
        return $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create(['stock' => $stock]));
    }

    /**
     * Un apartado creado por el formulario de verdad, con la ventana de los
     * ajustes de su organización.
     */
    private function createThroughTheForm(Organization $organization): Layaway
    {
        [$catalog, $product] = $this->withinOrganization($organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create(['max_active_layaways' => 5]))->create();

            $catalog = Catalog::factory()->published()->create();
            $product = Product::factory()->published()->create(['stock' => 3]);
            $catalog->products()->attach($product, ['position' => 1]);

            return [$catalog, $product];
        });

        return app(CreateLayawayAction::class)->handle(new CreateLayawayData(
            catalog: $catalog,
            product: $product,
            quantity: 1,
            contact: CustomerContactData::fromInput('V-12.345.678', 'María González', '0414-1234567', null),
            consent: TermsConsent::acceptedNowFrom('190.202.10.5'),
            idempotencyKey: (string) Str::uuid(),
        ));
    }

    private function statusOf(Layaway $layaway): string
    {
        return (string) DB::table('layaways')->where('id', $layaway->getKey())->value('status');
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->getKey())->value('stock');
    }

    private function setCounter(int $value): void
    {
        $this->withinOrganization($this->organization, function () use ($value): void {
            UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => $value, 'computed_at' => now()]);
        });
    }

    private function counter(): int
    {
        return (int) $this->withinOrganization(
            $this->organization,
            fn () => UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'),
        );
    }
}
