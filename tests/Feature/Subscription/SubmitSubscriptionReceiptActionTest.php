<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Payment\SubmitReceiptAction;
use App\Actions\Subscription\SubmitSubscriptionReceiptAction;
use App\Actions\Subscription\SubmitSubscriptionReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Models\UsageCounter;
use App\Models\User;
use App\Queries\Platform\SubscriptionReceiptDuplicates;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BillsSubscriptions;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

final class SubmitSubscriptionReceiptActionTest extends TestCase
{
    use BillsSubscriptions, CreatesLayaways, RefreshDatabase, UploadsReceiptFiles;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
        $this->setUpBilling();
    }

    public function test_the_receipt_waits_for_the_platform_and_does_not_count_as_storage(): void
    {
        $invoice = $this->invoiceFor();
        $before = $this->storage();

        $receipt = $this->submit($invoice, $this->receiptImage());

        $this->assertSame(ReceiptStatus::PendingReview, $receipt->status);
        $this->assertSame(InvoiceStatus::UnderReview, $invoice->fresh()?->status);
        $this->assertStringStartsWith(SubscriptionReceipt::DIRECTORY.'/'.$this->billedOrganization->getKey().'/', $receipt->file_path);
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);

        $this->billed(fn () => SubscriptionReceipt::query()->update(['file_size' => 6 * 1024 * 1024]));
        $this->recalculateUsage($this->billedOrganization);
        $this->assertSame($before, $this->storage());
    }

    public function test_a_duplicate_is_blocked_with_the_same_rule_as_a_customer_receipt(): void
    {
        $first = $this->receiptImage();
        $this->submit($this->invoiceFor(), $first);
        $second = $this->invoiceFor();

        foreach ([
            'file' => fn () => $this->submit($second, $this->sameFileAs($first), reference: '99999'),
            'reference' => fn () => $this->submit($second, $this->receiptImage(70, 50)),
        ] as $field => $attempt) {
            try {
                $attempt();
                $this->fail("El duplicado por {$field} tenía que bloquearse.");
            } catch (DuplicateReceiptException $exception) {
                $this->assertSame($field, $exception->field());
            }
        }

        $this->assertCount(1, Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_the_duplicate_rule_is_the_same_collaborator_for_both_tables(): void
    {
        $hash = hash('sha256', 'el-mismo-archivo');

        $this->billed(function () use ($hash): void {
            $this->subscribeWithoutLimits();
            Receipt::factory()->create(['layaway_id' => $this->layawayIn($this->billedOrganization), 'file_hash' => $hash, 'bank' => 'Banesco', 'reference' => '0001']);
            SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $this->invoiceFor()->getKey(), 'file_hash' => $hash, 'bank' => 'Banesco', 'reference' => '0001']);
        });

        $cases = [
            [$hash, null, null, DuplicateCriterion::FileHash],
            [hash('sha256', 'otro'), 'Banesco', '0001', DuplicateCriterion::BankReference],
            [hash('sha256', 'otro'), 'Mercantil', '0001', null],
            [hash('sha256', 'otro'), null, null, null],
        ];

        foreach ($cases as [$fileHash, $bank, $reference, $expected]) {
            $this->billed(function () use ($fileHash, $bank, $reference, $expected): void {
                $this->assertSame($expected, app(SubmitReceiptAction::class)->duplicateOf($fileHash, $bank, $reference));
                $this->assertSame($expected, app(SubscriptionReceiptDuplicates::class)->criterionFor($fileHash, $bank, $reference));
            });
        }

        $platform = (string) file_get_contents(app_path('Queries/Platform/SubscriptionReceiptDuplicates.php'));
        $action = (string) file_get_contents(app_path('Actions/Subscription/SubmitSubscriptionReceiptAction.php'));
        $this->assertStringContainsString('DuplicateReceipts::criterionFor(', $platform);
        $this->assertStringContainsString('$this->duplicates->criterionFor(', $action);
        $this->assertStringNotContainsString("where('file_hash'", $platform.$action, 'La regla no se reimplementa.');
    }

    public function test_a_receipt_already_used_by_another_business_is_a_duplicate(): void
    {
        $file = $this->receiptImage();
        $this->submit($this->invoiceFor(), $file);

        $neighbour = $this->createNeighbourOrganization();
        $theirOwner = $this->withinOrganization($neighbour, fn (): User => User::factory()->owner()->create());
        $theirInvoice = $this->withinOrganization($neighbour, function (): SubscriptionInvoice {
            Subscription::factory()->forPlan($this->paidPlan)->paid()->create();

            return SubscriptionInvoice::factory()->forPlan($this->paidPlan)->create();
        });

        foreach ([['file' => $this->sameFileAs($file), 'reference' => '7777', 'field' => 'file'], ['file' => $this->receiptImage(80, 60), 'reference' => '0123456789', 'field' => 'reference']] as $case) {
            try {
                $this->withinOrganization($neighbour, fn () => app(SubmitSubscriptionReceiptAction::class)->handle(new SubmitSubscriptionReceiptData(
                    invoice: $theirInvoice,
                    method: PaymentMethod::PagoMovil,
                    bank: 'Banesco',
                    reference: $case['reference'],
                    paidAt: CarbonImmutable::now(),
                    declaredAmount: '15.00',
                    declaredCurrency: Currency::USD,
                    file: $case['file'],
                ), $theirOwner));
                $this->fail('El mismo pago no paga dos suscripciones.');
            } catch (DuplicateReceiptException $exception) {
                $this->assertSame($case['field'], $exception->field());
            }
        }
    }

    public function test_a_failure_after_the_file_is_stored_removes_it(): void
    {
        SubscriptionReceipt::creating(function (): void {
            throw new \RuntimeException('falla la fila');
        });

        try {
            $this->submit($this->invoiceFor(), $this->receiptImage());
            $this->fail('La fila tenía que fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('falla la fila', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_a_paid_or_void_invoice_takes_no_more_receipts(): void
    {
        foreach ([InvoiceStatus::Paid, InvoiceStatus::Void] as $status) {
            try {
                $this->submit($this->invoiceFor(['status' => $status]), $this->receiptImage(), reference: (string) random_int(100000, 999999));
                $this->fail("Una factura {$status->value} no se paga.");
            } catch (InvoiceNotPayableException $exception) {
                $this->assertSame($status, $exception->status);
            }
        }

        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_a_member_cannot_pay_the_subscription(): void
    {
        $member = $this->billed(fn (): User => User::factory()->create(['role' => OrganizationRole::Member]));

        $this->expectException(AuthorizationException::class);

        $this->submit($this->invoiceFor(), $this->receiptImage(), actor: $member);
    }

    public function test_a_file_that_is_not_a_receipt_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->submit($this->invoiceFor(), $this->receiptUpload('comprobante.pdf', 'esto no es un pdf'));
    }

    private function submit(SubscriptionInvoice $invoice, UploadedFile $file, string $reference = '0123456789', ?User $actor = null): SubscriptionReceipt
    {
        return $this->billed(fn (): SubscriptionReceipt => app(SubmitSubscriptionReceiptAction::class)->handle(new SubmitSubscriptionReceiptData(
            invoice: $invoice,
            method: PaymentMethod::PagoMovil,
            bank: 'Banesco',
            reference: $reference,
            paidAt: CarbonImmutable::now(),
            declaredAmount: '637.50',
            declaredCurrency: Currency::VES,
            file: $file,
        ), $actor ?? $this->billedOwner));
    }

    private function storage(): int
    {
        return (int) $this->billed(fn (): mixed => UsageCounter::query()->where('metric', UsageMetric::StorageMb)->value('value'));
    }
}
