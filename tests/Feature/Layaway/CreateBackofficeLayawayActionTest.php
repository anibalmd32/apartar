<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\BackofficeLayawayData;
use App\Actions\Layaway\CreateBackofficeLayawayAction;
use App\Actions\Layaway\CreatedLayaway;
use App\Actions\Layaway\ExpireLayawaysAction;
use App\Actions\Notification\DispatchDueRemindersAction;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Customer\TermsConsent;
use App\Domain\Layaway\DownPaymentWindow;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Notification\NotificationType;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\CustomerMessageNotification;
use App\Notifications\LayawayConfirmationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsStockLockOrder;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CreateBackofficeLayawayActionTest extends TestCase
{
    use AssertsStockLockOrder, ReusesMigratedDatabase;

    private const NOTE = 'Efectivo recibido en tienda por Ana al apartar.';

    private Organization $organization;

    private Product $product;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-27 10:00:00');
        Notification::fake();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        [$this->product, $this->member] = $this->withinOrganization($this->organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create(['max_active_layaways' => 5]))->create();

            return [
                Product::factory()->published()->create([
                    'name' => 'Nevera Mabe 14 pies',
                    'sku' => 'NEV-14',
                    'price_cents' => 20_000,
                    'down_payment_cents' => 5_000,
                    'installments_count' => 5,
                    'frequency_days' => 15,
                    'stock' => 3,
                ]),
                User::factory()->create(['name' => 'Ana Pérez']),
            ];
        });

        $this->actingAsOrganization($this->organization);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_new_customer_is_created_with_the_consent_declared_by_the_member(): void
    {
        $created = $this->create(quantity: 2);
        $layaway = $created->layaway;
        $customer = Customer::query()->findOrFail($layaway->customer_id);

        $this->assertTrue($created->created);
        $this->assertSame(LayawayStatus::PendingDownPayment, $layaway->status);
        $this->assertNull($layaway->catalog_id);
        $this->assertSame((int) $this->member->getKey(), $layaway->created_by_user_id);
        $this->assertSame([40_000, 10_000, 2], [$layaway->total_cents->cents, $layaway->terms()->totalDownPayment()->cents, $layaway->quantity]);
        $this->assertSame('2026-09-30 10:00:00', $layaway->expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame((int) $this->member->getKey(), $customer->terms_declared_by_user_id);
        $this->assertSame('190.202.10.5', $customer->terms_ip_address);
        $this->assertSame('2026-09-27 10:00:00', $customer->terms_accepted_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, Product::query()->findOrFail($this->product->getKey())->stock);
        $this->assertSame(1, (int) UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'));
    }

    public function test_the_creation_is_audited_with_its_origin(): void
    {
        $layaway = $this->create()->layaway;

        $entry = AuditEntry::query()->ofEvent(AuditEvent::LayawayCreated)->sole();
        $changed = $entry->properties?->toArray()['changed'] ?? [];

        $this->assertSame((int) $this->member->getKey(), (int) $entry->causer_id);
        $this->assertSame($layaway->code, $changed['code']['after'] ?? null);
        $this->assertTrue($changed['customer_created']['after'] ?? null);
        $this->assertTrue($changed['terms_declared_by_business']['after'] ?? null);
        $this->assertSame('plazo del negocio (72 h)', $changed['down_payment_window']['after'] ?? null);
        $this->assertSame('apartado creado desde el backoffice', $changed['origin']['after'] ?? null);
        $this->assertSame('Apartado creado desde el backoffice: '.$layaway->code, $entry->description);
    }

    public function test_an_existing_customer_keeps_their_contact_data_and_consent(): void
    {
        $existing = $this->create(document: 'V-12.345.678', email: 'maria@ejemplo.test')->layaway;
        DB::table('customers')->where('id', $existing->customer_id)->update(['terms_declared_by_user_id' => null, 'terms_ip_address' => '10.0.0.1']);
        $before = (array) DB::table('customers')->where('id', $existing->customer_id)->first();
        CarbonImmutable::setTestNow('2026-09-28 10:00:00');

        $byPicker = $this->create(customer: Customer::query()->findOrFail($existing->customer_id))->layaway;
        $byDocument = $this->create(document: 'V-12.345.678', name: 'Otro Nombre', phone: '0424-7654321', email: 'otro@ejemplo.test')->layaway;

        $this->assertSame([$existing->customer_id, $existing->customer_id], [$byPicker->customer_id, $byDocument->customer_id]);
        $this->assertEquals($before, (array) DB::table('customers')->where('id', $existing->customer_id)->first());
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, AuditEntry::query()->ofEvent(AuditEvent::CustomerContactUpdated)->count());
    }

    public function test_a_product_without_down_payment_is_born_active_and_confirmed_by_mail(): void
    {
        $product = $this->productWith(downPayment: 0);

        $layaway = $this->create(product: $product, window: DownPaymentWindow::hours(5))->layaway;

        $this->assertSame(LayawayStatus::Active, $layaway->status);
        $this->assertNull($layaway->expires_at);
        $this->assertSame(4, $layaway->installments()->count());
        Notification::assertSentOnDemand(
            LayawayConfirmationNotification::class,
            fn (LayawayConfirmationNotification $mail): bool => ! $mail->requiresDownPayment && $mail->code === $layaway->code,
        );
    }

    public function test_a_custom_window_in_hours_or_days_sets_the_expiry(): void
    {
        $hours = $this->create(window: DownPaymentWindow::hours(6))->layaway;
        $days = $this->create(window: DownPaymentWindow::days(3), document: 'V-23456789')->layaway;

        $this->assertSame('2026-09-27 16:00:00', $hours->expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 10:00:00', $days->expires_at?->format('Y-m-d H:i:s'));
    }

    public function test_without_expiry_it_waits_for_the_down_payment_forever(): void
    {
        $layaway = $this->create(window: DownPaymentWindow::withoutExpiry())->layaway;

        $this->assertSame(LayawayStatus::PendingDownPayment, $layaway->status);
        $this->assertNull($layaway->expires_at);

        $this->assertSame(0, app(ExpireLayawaysAction::class)->handle(CarbonImmutable::now()->addDays(120))->expired);

        foreach ([1, 12, 47, 71] as $hours) {
            $this->assertSame(0, app(DispatchDueRemindersAction::class)->handle($this->organization, CarbonImmutable::now()->addHours($hours), installments: false)['sent']);
        }

        $this->assertSame(LayawayStatus::PendingDownPayment, $layaway->fresh()?->status);
        $this->assertSame(0, DB::table('notification_dispatches')->where('type', NotificationType::DownPaymentReminder->value)->count());
        Notification::assertSentOnDemand(
            LayawayConfirmationNotification::class,
            fn (LayawayConfirmationNotification $mail): bool => $mail->requiresDownPayment && $mail->expiresAt === '',
        );
    }

    public function test_a_payment_covering_the_down_payment_activates_it_in_the_same_creation(): void
    {
        $created = $this->create(payment: $this->payment('80.00'));
        $layaway = $created->layaway->fresh();

        $this->assertSame(LayawayStatus::Active, $layaway?->status);
        $this->assertSame(8_000, $layaway?->paid_cents->cents);
        $this->assertSame((int) $this->member->getKey(), (int) $created->payment?->registered_by);
        Notification::assertSentOnDemand(
            LayawayConfirmationNotification::class,
            fn (LayawayConfirmationNotification $mail): bool => $mail->downPaymentPaid,
        );
    }

    public function test_a_payment_at_creation_is_announced_only_by_the_confirmation(): void
    {
        $layaway = $this->create(payment: $this->payment('80.00'))->layaway;

        Notification::assertSentOnDemandTimes(LayawayConfirmationNotification::class, 1);
        Notification::assertSentOnDemand(
            LayawayConfirmationNotification::class,
            fn (LayawayConfirmationNotification $mail): bool => $mail->code === $layaway->code && $mail->downPaymentPaid && ! $mail->fullyPaid,
        );
        Notification::assertSentTimes(CustomerMessageNotification::class, 0);
    }

    public function test_a_payment_of_the_whole_total_completes_it_and_the_confirmation_says_so(): void
    {
        $layaway = $this->create(payment: $this->payment('200.00'))->layaway->fresh();

        $this->assertSame(LayawayStatus::Completed, $layaway?->status);
        Notification::assertSentOnDemand(LayawayConfirmationNotification::class, fn (LayawayConfirmationNotification $mail): bool => $mail->fullyPaid);
        Notification::assertSentTimes(CustomerMessageNotification::class, 0);
    }

    public function test_without_an_email_the_customer_is_still_told_of_the_payment_and_the_activation(): void
    {
        $customer = Customer::query()->findOrFail($this->create(email: null)->layaway->customer_id);
        $customer->updatePushSubscription('https://push.test/maria', 'k', 't', 'aes128gcm');

        $this->create(customer: $customer, payment: $this->payment('80.00'));

        Notification::assertSentOnDemandTimes(LayawayConfirmationNotification::class, 0);
        $this->assertSame(
            [NotificationType::LayawayActivated, NotificationType::PaymentCredited],
            collect(Notification::sent($customer, CustomerMessageNotification::class))->map(fn (CustomerMessageNotification $notice): NotificationType => $notice->type)->sortBy(fn (NotificationType $type): string => $type->value)->values()->all(),
        );
    }

    public function test_a_later_payment_of_a_backoffice_layaway_is_announced_as_usual(): void
    {
        $layaway = $this->create()->layaway;

        app(RegisterManualPaymentAction::class)->handle($layaway, $this->payment('50.00'), $this->member);

        $customer = Customer::query()->findOrFail($layaway->customer_id);
        Notification::assertSentTo($customer, CustomerMessageNotification::class, fn (CustomerMessageNotification $notice): bool => $notice->type === NotificationType::PaymentCredited);
        Notification::assertSentTo($customer, CustomerMessageNotification::class, fn (CustomerMessageNotification $notice): bool => $notice->type === NotificationType::LayawayActivated);
    }

    public function test_a_payment_below_the_down_payment_rolls_the_whole_creation_back(): void
    {
        try {
            $this->create(payment: $this->payment('30.00'));
            $this->fail('Se creó el apartado con un pago por debajo de la inicial.');
        } catch (PaymentNotApplicableException) {
        }

        $this->assertNothingPersisted();
        Notification::assertNothingSent();
    }

    public function test_a_second_submission_with_the_same_key_returns_the_same_layaway_and_payment(): void
    {
        $key = (string) Str::uuid();
        $payment = $this->payment('50.00', $key);

        $first = $this->create(key: $key, payment: $payment);
        $second = $this->create(key: $key, payment: $payment);

        $this->assertFalse($second->created);
        $this->assertTrue($first->layaway->is($second->layaway));
        $this->assertTrue($first->payment?->is($second->payment));
        $this->assertSame(1, Layaway::query()->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, AuditEntry::query()->ofEvent(AuditEvent::LayawayCreated)->count());
    }

    public function test_an_unpublished_product_is_refused(): void
    {
        DB::table('products')->where('id', $this->product->getKey())->update(['status' => ProductStatus::Draft->value]);

        $this->expectException(ProductNotAvailableException::class);

        $this->create();
    }

    public function test_more_than_the_stock_is_refused(): void
    {
        try {
            $this->create(quantity: 4);
            $this->fail('Se apartó más que el stock.');
        } catch (InsufficientStockException) {
        }

        $this->assertNothingPersisted();
    }

    public function test_the_member_sees_the_real_plan_limit(): void
    {
        UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => 5, 'computed_at' => now()]);

        try {
            $this->create();
            $this->fail('Se creó un apartado por encima del techo del plan.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertStringContainsStringIgnoringCase('plan', $exception->getMessage());
        }

        $this->assertNothingPersisted(usage: 5);
    }

    public function test_a_suspended_organization_cannot_create(): void
    {
        DB::table('organizations')->where('id', $this->organization->getKey())->update(['status' => 'suspended', 'suspended_at' => now()]);

        $this->expectException(CreationSuspendedException::class);

        $this->create();
    }

    public function test_a_customer_of_another_organization_is_refused(): void
    {
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): Customer => Customer::factory()->create());

        try {
            $this->create(customer: $foreign);
            $this->fail('Se apartó para la ficha de otro negocio.');
        } catch (CrossTenantRelationException) {
        }

        $this->assertNothingPersisted();
    }

    public function test_a_disabled_member_cannot_create(): void
    {
        $this->member->forceFill(['disabled_at' => now()])->save();

        $this->expectException(AuthorizationException::class);

        $this->create();
    }

    public function test_the_product_is_locked_before_any_other_row(): void
    {
        $this->create();

        $this->assertLocksTheProductFirst(fn (): CreatedLayaway => $this->create(document: 'V-23456789'), 'CreateBackofficeLayawayAction');
    }

    public function test_it_records_the_telemetry_with_its_backoffice_source(): void
    {
        $layaway = $this->create()->layaway;

        $row = DB::table('telemetry_events')->where('event_name', 'layaway_created')->sole();

        $this->assertSame((int) $layaway->getKey(), (int) $row->subject_id);
        $this->assertNull($row->catalog_id);
        $this->assertSame('backoffice', json_decode((string) $row->payload, true)['source'] ?? null);
    }

    private function create(
        int $quantity = 1,
        ?string $key = null,
        ?Customer $customer = null,
        string $document = 'V-12345678',
        string $name = 'María González',
        string $phone = '0414-1234567',
        ?string $email = 'maria@ejemplo.test',
        ?Product $product = null,
        ?DownPaymentWindow $window = null,
        ?ManualPaymentData $payment = null,
    ): CreatedLayaway {
        return app(CreateBackofficeLayawayAction::class)->handle(new BackofficeLayawayData(
            product: $product ?? $this->product,
            quantity: $quantity,
            customer: $customer,
            contact: $customer === null ? CustomerContactData::fromInput($document, $name, $phone, $email) : null,
            consent: $customer === null ? TermsConsent::acceptedNowFrom('190.202.10.5') : null,
            window: $window ?? DownPaymentWindow::organizationDefault(),
            idempotencyKey: $key ?? $payment->idempotencyKey ?? (string) Str::uuid(),
            payment: $payment,
        ), $this->member);
    }

    private function payment(string $amount, ?string $key = null): ManualPaymentData
    {
        return new ManualPaymentData(
            PaymentMethod::EfectivoUsd,
            null,
            $amount,
            Currency::USD,
            null,
            CarbonImmutable::now(),
            self::NOTE,
            $key ?? (string) Str::uuid(),
        );
    }

    private function productWith(int $downPayment): Product
    {
        return Product::factory()->published()->create([
            'price_cents' => 20_000,
            'down_payment_cents' => $downPayment,
            'installments_count' => 4,
            'frequency_days' => 15,
            'stock' => 3,
        ]);
    }

    private function assertNothingPersisted(int $usage = 0): void
    {
        $this->assertSame(0, Layaway::query()->count(), 'Quedó un apartado.');
        $this->assertSame(0, Customer::query()->count(), 'Quedó una ficha.');
        $this->assertSame(0, DB::table('payments')->count(), 'Quedó un pago.');
        $this->assertSame(3, Product::query()->findOrFail($this->product->getKey())->stock, 'Quedó stock reservado.');
        $this->assertSame($usage, (int) UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'), 'Se movió el cupo del plan.');
    }
}
