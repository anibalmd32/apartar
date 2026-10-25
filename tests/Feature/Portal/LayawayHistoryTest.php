<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Domain\Settings\SettingKey;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Settings;
use App\Support\SignedFileUrl;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

final class LayawayHistoryTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private Layaway $layaway;

    private Customer $customer;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->staff = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['name' => 'Revisora Secreta']));
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);

        $this->layaway = $this->layawayIn($this->organization);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->approve($this->receiptFor($this->layaway, attributes: ['declared_amount' => '50.00', 'declared_currency' => Currency::USD]), '50.00', Currency::USD);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_payments_keep_the_rate_they_were_settled_with(): void
    {
        $this->approve($this->receiptFor($this->layaway, attributes: ['declared_amount' => '2550.00', 'declared_currency' => Currency::VES]), '2550.00', Currency::VES);

        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '60.00000000', 'published_at' => CarbonImmutable::now()->addMinute()]);
        $this->withinOrganization($this->organization, function (): void {
            OrganizationSetting::query()->where('key', SettingKey::ExchangeRateSource)->delete();
            OrganizationSetting::factory()->forKey(SettingKey::ExchangeRateSource, RateSource::Parallel->value)->create();
            app(Settings::class)->forget($this->organization);
        });

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 2)
            ->where('payments.0.amountUsd.usd', '$60,00')
            ->where('payments.0.amountUsd.ves', null)
            ->where('payments.0.declaredAmount', 'Bs. 2.550,00')
            ->where('payments.0.rateApplied', '42.50000000')
            ->where('payments.0.rateSource', 'bcv')
            ->where('payments.0.appliedTo', [1, 2])
            ->where('payments.0.registeredByBusiness', false)
            ->where('payments.0.downPayment', null)
            ->where('payments.1.declaredAmount', '$50,00')
            ->where('payments.1.downPayment', '$50,00')
            ->where('payments.1.rateApplied', null)
            ->where('payments.1.appliedTo', []));
    }

    public function test_a_payment_registered_by_the_business_is_labelled_with_its_note(): void
    {
        $this->withinOrganization($this->organization, fn () => app(PaymentApplier::class)->apply(
            $this->layaway, new Money(3_000), null, '30.00', Currency::USD, null, $this->staff, 'Pagó en efectivo en la tienda.',
        ));

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.registeredByBusiness', true)
            ->where('payments.0.note', 'Pagó en efectivo en la tienda.')
            ->where('payments.0.method', 'Registrado por el negocio')
            ->where('payments.1.note', null)
            ->has('receipts', 1));
    }

    public function test_receipts_show_their_reason_their_chain_and_their_account(): void
    {
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create(['bank' => 'Banesco', 'identifier' => '0414-1234567']));
        $rejected = $this->receiptFor($this->layaway, ReceiptStatus::Rejected, [
            'created_at' => CarbonImmutable::parse('2026-09-05 10:00:00'),
            'rejection_reason_code' => RejectionReasonCode::ReferenceNotFound,
            'rejection_reason' => 'La referencia no coincide con el banco.',
            'reviewed_by' => $this->staff->getKey(),
        ]);
        $retry = $this->receiptFor($this->layaway, attributes: [
            'created_at' => CarbonImmutable::parse('2026-09-07 09:00:00'),
            'replaces_receipt_id' => $rejected->getKey(),
            'payment_account_id' => $account->getKey(),
        ]);

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('receipts', 3)
            ->where('receipts.1.status', 'pending_review')
            ->where('receipts.1.statusLabel', 'En revisión desde el 07/09')
            ->where('receipts.1.replacesReceiptId', pathinfo($rejected->file_path, PATHINFO_FILENAME))
            ->where('receipts.1.paidToAccount', 'Pago móvil · Banesco · 0414-1234567')
            ->where('receipts.1.id', pathinfo($retry->file_path, PATHINFO_FILENAME))
            ->where('receipts.2.status', 'rejected')
            ->where('receipts.2.rejectionReason', 'La referencia no coincide con el banco.'));
    }

    public function test_the_file_opens_with_a_five_minute_signed_url(): void
    {
        $this->show()->assertInertia(function (AssertableInertia $page): void {
            $url = (string) $page->toArray()['props']['receipts'][0]['fileUrl'];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $this->assertStringContainsString('/mi-cuenta/comprobantes/', $url);
            $this->assertSame(CarbonImmutable::now()->addMinutes(SignedFileUrl::CUSTOMER_TTL_MINUTES)->getTimestamp(), (int) ($query['expires'] ?? 0));
        });
    }

    public function test_the_history_exposes_no_reviewer_and_no_internal_ids(): void
    {
        $receipt = $this->withinOrganization($this->organization, fn (): Receipt => Receipt::query()->firstOrFail());
        $json = (string) json_encode($this->show()->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        $props = $this->show()->viewData('page')['props'];

        $this->assertStringNotContainsString('Revisora Secreta', $json);
        $this->assertStringNotContainsString('account_id', $json);
        $this->assertStringNotContainsString('reviewed_by', $json);
        $this->assertNotSame($receipt->getKey(), $props['receipts'][0]['id']);
        $this->assertArrayNotHasKey('id', $props['payments'][0]);
        $this->assertNull($props['auth']['user']);
    }

    public function test_the_history_is_of_this_layaway_only(): void
    {
        $other = $this->layawayIn($this->organization);
        $this->approve($this->receiptFor($other, attributes: ['declared_amount' => '50.00', 'declared_currency' => Currency::USD]), '50.00', Currency::USD);
        $decoy = $this->receiptFor($other, attributes: ['reference' => '9999999999']);

        $props = $this->show()->viewData('page')['props'];

        $this->assertCount(1, $props['payments']);
        $this->assertCount(1, $props['receipts']);
        $this->assertStringNotContainsString(pathinfo($decoy->file_path, PATHINFO_FILENAME), (string) json_encode($props));
        $this->assertStringNotContainsString('9999999999', (string) json_encode($props));
    }

    public function test_the_note_of_an_approval_stays_with_the_business(): void
    {
        $this->withinOrganization($this->organization, fn () => app(ApproveReceiptAction::class)->handle(
            $this->receiptFor($this->layaway), new ApproveReceiptData('30.00', Currency::USD, null, 'Cliente moroso, ojo.'), $this->staff,
        ));

        $props = $this->show()->viewData('page')['props'];

        $this->assertNull($props['payments'][0]['note']);
        $this->assertStringNotContainsString('Cliente moroso', (string) json_encode($props, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_status_label_and_reason_at_their_edges(): void
    {
        $this->receiptFor($this->layaway, attributes: ['created_at' => CarbonImmutable::now()->subHours(47)]);
        $this->receiptFor($this->layaway, attributes: ['created_at' => CarbonImmutable::now()->subHours(48)->subSecond()]);
        $this->receiptFor($this->layaway, ReceiptStatus::Rejected, [
            'created_at' => CarbonImmutable::now()->subDays(5),
            'rejection_reason_code' => RejectionReasonCode::LayawayCancelled,
            'rejection_reason' => null,
        ]);

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('receipts.0.statusLabel', 'Aprobado')
            ->where('receipts.1.statusLabel', 'En revisión')
            ->where('receipts.2.statusLabel', 'En revisión desde el 08/09')
            ->where('receipts.3.statusLabel', 'Rechazado')
            ->where('receipts.3.rejectionReason', RejectionReasonCode::LayawayCancelled->label()));
    }

    private function approve(Receipt $receipt, string $amount, Currency $currency): void
    {
        $this->withinOrganization($this->organization, fn () => app(ApproveReceiptAction::class)->handle(
            $receipt, new ApproveReceiptData($amount, $currency, null, null), $this->staff,
        ));
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function show(): \Illuminate\Testing\TestResponse
    {
        $this->withoutOrganization();

        return $this->actingAs($this->customer, 'customer')->get(route('portal.layaways.show', ['code' => $this->layaway->code]))->assertOk();
    }
}
