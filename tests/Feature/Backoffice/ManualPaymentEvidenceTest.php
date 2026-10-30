<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\DuplicateReceipts;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Tenancy\OrganizationRole;
use App\Exports\PaymentsExport;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Support\SignedFileUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

final class ManualPaymentEvidenceTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase, UploadsReceiptFiles;

    private const NOTE = 'Pago móvil confirmado por teléfono con Ana.';

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'America/Caracas'));
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_form_knows_which_methods_carry_a_reference_and_the_file_limits(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->actingAs($this->member)->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('manualPayment.methods.0.value', 'pago_movil')
            ->where('manualPayment.methods.0.requiresReference', true)
            ->where('manualPayment.methods.4.value', 'efectivo_usd')
            ->where('manualPayment.methods.4.requiresReference', false)
            ->where('manualPayment.file.accept', ReceiptFile::ACCEPT)
            ->where('manualPayment.file.maxMb', ReceiptFile::maxSizeMb()));
    }

    public function test_a_referenced_method_asks_for_bank_and_reference_and_cash_refuses_them(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['bank' => '', 'reference' => '']))
            ->assertSessionHasErrors(['bank', 'reference']);
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['reference' => str_repeat('9', 101)]))
            ->assertSessionHasErrors('reference');
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['method' => 'efectivo_usd', 'currency' => 'USD', 'amount' => '50.00']))
            ->assertSessionHasErrors([
                'bank' => 'Un pago en efectivo no lleva banco ni referencia.',
                'reference' => 'Un pago en efectivo no lleva banco ni referencia.',
            ]);

        $this->assertSame(0, DB::table('payments')->count());

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['bank' => '0134']))->assertRedirect(route('layaways.show', $layaway));

        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->sole());
        $this->assertSame(['Banesco', '0123456789', null], [$payment->bank, $payment->reference, $payment->file_path]);
    }

    public function test_a_bank_and_reference_already_reported_or_recorded_is_refused(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->receiptFor($layaway, attributes: ['bank' => 'Banesco', 'reference' => '1111111111']);
        $this->withoutOrganization();
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['reference' => '1111111111']))
            ->assertSessionHasErrors(['reference' => DuplicateReceiptException::RECORDED_REFERENCE_MESSAGE]);

        $this->post(route('layaways.payments.store', $layaway), $this->payload())->assertRedirect(route('layaways.show', $layaway));
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['bank' => 'banesco', 'amount' => '1275.00']))
            ->assertSessionHasErrors(['reference' => DuplicateReceiptException::RECORDED_REFERENCE_MESSAGE]);
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['bank' => 'Mercantil', 'amount' => '1275.00']))
            ->assertRedirect(route('layaways.show', $layaway));

        $this->assertSame(2, DB::table('payments')->count());
    }

    public function test_the_customer_cannot_report_again_what_the_business_recorded(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $file = $this->receiptImage();
        $this->actingAs($this->member)->post(route('layaways.payments.store', $layaway), $this->payload(['file' => $file]))
            ->assertRedirect(route('layaways.show', $layaway));

        $this->withinOrganization($this->organization, function () use ($file): void {
            $this->assertSame(DuplicateCriterion::BankReference, DuplicateReceipts::inOrganization(null, 'Banesco', '0123456789'));
            $this->assertSame(DuplicateCriterion::FileHash, DuplicateReceipts::inOrganization(ReceiptFile::fingerprint($this->sameFileAs($file)), null, null));
        });
    }

    public function test_the_attached_proof_follows_the_limits_of_a_receipt(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['file' => $this->receiptUpload('notas.txt', 'no es un comprobante')]))
            ->assertSessionHasErrors('file');
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['file' => UploadedFile::fake()->create('pesado.pdf', ReceiptFile::maxSizeMb() * 1024 + 1, 'application/pdf')]))
            ->assertSessionHasErrors(['file' => sprintf('El comprobante pesa más de %d MB. Si es un PDF, sube mejor una captura.', ReceiptFile::maxSizeMb())]);

        $taken = $this->receiptImage();
        $this->receiptFor($layaway, attributes: ['file_hash' => hash_file('sha256', (string) $taken->getRealPath())]);
        $this->withoutOrganization();
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['file' => $taken]))
            ->assertSessionHasErrors(['file' => DuplicateReceiptException::RECORDED_FILE_MESSAGE]);

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_the_attached_proof_is_viewable_from_the_history_and_stays_out_of_the_inbox(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member)->post(route('layaways.payments.store', $layaway), $this->payload(['file' => $this->receiptPdf()]))
            ->assertRedirect(route('layaways.show', $layaway));
        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->sole());
        $this->withoutOrganization();

        $url = null;
        $this->get(route('layaways.show', $layaway))->assertInertia(function (AssertableInertia $page) use (&$url): void {
            $page->where('payments.0.registeredByBusiness', true)
                ->where('payments.0.bankReference', 'Banesco · ref. 0123456789')
                ->where('payments.0.attachmentUrl', function (string $value) use (&$url): bool {
                    $url = $value;

                    return str_contains($value, '/backoffice/pagos/');
                });
        });

        $response = $this->get((string) $url)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk(Receipt::DISK)->get((string) $payment->file_path), $response->streamedContent());

        $this->get(route(SignedFileUrl::BACKOFFICE_PAYMENT_ROUTE, ['payment' => $payment->getKey()]))->assertForbidden();

        $this->get(route('receipts.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teamMember.pendingReceipts', 0)
            ->where('statusCounts', ['pending_review' => 0, 'approved' => 0, 'rejected' => 0]));
        $this->assertSame(0, DB::table('receipts')->count());
    }

    public function test_only_the_business_and_the_customer_of_the_layaway_see_the_proof(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member)->post(route('layaways.payments.store', $layaway), $this->payload(['file' => $this->receiptPdf()]));
        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->sole());
        $owner = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
        $stranger = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layawayIn($this->organization)->customer_id));
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $policy = new PaymentPolicy;
        $this->withinOrganization($this->organization, function () use ($policy, $payment, $foreign, $owner, $stranger): void {
            foreach (OrganizationRole::cases() as $role) {
                $this->assertTrue($policy->viewAttachment(User::factory()->create(['role' => $role]), $payment), $role->value);
            }

            $this->assertFalse($policy->viewAttachment($foreign, $payment));
            $this->assertTrue($policy->viewAttachment($owner, $payment));
            $this->assertFalse($policy->viewAttachment($stranger, $payment));
            $this->assertFalse($policy->viewAttachment($this->member, Payment::query()->findOrFail($payment->getKey())->setAttribute('file_path', null)));
        });
        $this->withoutOrganization();

        $this->actingAs($owner, 'customer')->get(SignedFileUrl::forCustomerPayment($payment))->assertOk();
        $this->get(route('portal.layaways.show', ['code' => $layaway->code]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.attachmentUrl', fn (string $value): bool => str_contains($value, '/mi-cuenta/pagos/'.$payment->getKey().'/archivo')));
        $this->actingAs($stranger, 'customer')->get(SignedFileUrl::forCustomerPayment($payment))->assertForbidden();
    }

    public function test_a_double_submit_with_reference_and_proof_records_one_payment(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $file = $this->receiptImage();
        $payload = $this->payload(['file' => $file]);
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('layaways.payments.store', $layaway), $payload)->assertRedirect(route('layaways.show', $layaway));
        $this->withoutOrganization();
        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'file' => $this->sameFileAs($file)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('layaways.show', $layaway));

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertCount(1, Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_a_key_reused_with_another_reference_or_proof_says_it_recorded_another_payment(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $payload = $this->payload();
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));
        $message = 'Este formulario ya registró otro pago. Recarga la página para registrar uno nuevo.';

        $this->post(route('layaways.payments.store', $layaway), $payload)->assertRedirect(route('layaways.show', $layaway));
        $this->withoutOrganization();
        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'reference' => '9876543210'])
            ->assertSessionHasErrors(['amount' => $message]);
        $this->withoutOrganization();
        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'file' => $this->receiptPdf()])
            ->assertSessionHasErrors(['amount' => $message]);

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_a_failure_after_the_commit_keeps_the_proof_of_the_recorded_payment(): void
    {
        $layaway = $this->layawayIn($this->organization);
        Payment::created(fn () => DB::afterCommit(fn () => throw new RuntimeException('La cola no responde.')));

        $this->actingAs($this->member)->post(route('layaways.payments.store', $layaway), $this->payload(['file' => $this->receiptPdf()]))
            ->assertServerError();

        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->sole());
        Storage::disk(Receipt::DISK)->assertExists((string) $payment->file_path);
    }

    public function test_the_export_carries_the_bank_and_reference_of_a_recorded_payment(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member)->post(route('layaways.payments.store', $layaway), $this->payload());

        $rows = $this->withinOrganization($this->organization, fn (): array => iterator_to_array((new PaymentsExport([], 'America/Caracas'))->generator(), false));

        $this->assertSame(['Registro manual', 'Pago móvil', 'Banesco', '0123456789'], [$rows[0][6], $rows[0][7], $rows[0][9], $rows[0][10]]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'method' => 'pago_movil',
            'bank' => 'Banesco',
            'reference' => '0123456789',
            'amount' => '2125.00',
            'currency' => 'VES',
            'paid_at' => '2026-09-10',
            'note' => self::NOTE,
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }
}
