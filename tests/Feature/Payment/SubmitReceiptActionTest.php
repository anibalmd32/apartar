<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Payment\SubmitReceiptAction;
use App\Actions\Payment\SubmitReceiptData;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Events\ReceiptSubmitted;
use App\Jobs\CompressReceiptImage;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

final class SubmitReceiptActionTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase, UploadsReceiptFiles;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);

        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Tokyo']);
    }

    public function test_it_creates_the_receipt_pending_review_without_touching_the_balance(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->within(fn () => app(ActivateLayawayAction::class)->handle($layaway));
        $account = $this->within(fn (): PaymentAccount => PaymentAccount::factory()->create());
        $before = $this->balanceOf($layaway);
        $file = $this->receiptImage();

        $receipt = $this->submit($layaway, ['paymentAccount' => $account, 'note' => 'Pagué la segunda cuota.', 'file' => $file]);

        $this->assertSame($before, $this->balanceOf($layaway), 'Subir un comprobante movió el balance.');
        $this->assertSame(0, DB::table('payments')->count());

        $stored = $this->within(fn (): Receipt => Receipt::query()->findOrFail($receipt->getKey()));
        $this->assertSame(ReceiptStatus::PendingReview, $stored->status);
        $this->assertSame((int) $this->organization->getKey(), $stored->organization_id);
        $this->assertSame((int) $layaway->getKey(), $stored->layaway_id);
        $this->assertSame((int) $layaway->customer_id, $stored->customer_id);
        $this->assertSame((int) $account->getKey(), $stored->payment_account_id);
        $this->assertSame(PaymentMethod::PagoMovil, $stored->payment_method);
        $this->assertSame('Banesco', $stored->bank);
        $this->assertSame('0123456789', $stored->reference);
        $this->assertSame('850.00', $stored->declared_amount);
        $this->assertSame(Currency::VES, $stored->declared_currency);
        $this->assertSame('Pagué la segunda cuota.', $stored->note);
        $this->assertSame(hash_file('sha256', (string) $file->getRealPath()), $stored->file_hash);
        $this->assertSame('image/jpeg', $stored->file_mime);
        $this->assertSame(filesize((string) $file->getRealPath()), $stored->file_size);
        $this->assertNull($stored->reviewed_by);
    }

    public function test_the_payment_date_is_stored_as_the_day_it_is(): void
    {
        $receipt = $this->submit($this->layawayIn($this->organization), [
            'paidAt' => CarbonImmutable::parse('2026-10-05 00:30:00', 'Asia/Tokyo'),
        ]);

        $this->assertSame('2026-10-05', DB::table('receipts')->where('id', $receipt->getKey())->value('paid_at'));
    }

    public function test_the_file_is_stored_under_the_organization_with_a_uuid_name(): void
    {
        $file = $this->receiptImage(name: 'Mi pago de octubre.jpg');

        $receipt = $this->submit($this->layawayIn($this->organization), ['file' => $file]);

        $this->assertMatchesRegularExpression(
            '#^'.$this->organization->getKey().'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#',
            $receipt->file_path,
        );
        $this->assertStringNotContainsString('octubre', $receipt->file_path);
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
        $this->assertSame($receipt->file_hash, hash('sha256', (string) Storage::disk(Receipt::DISK)->get($receipt->file_path)));
    }

    public function test_the_extension_and_mime_come_from_the_content(): void
    {
        $receipt = $this->submit($this->layawayIn($this->organization), [
            'file' => $this->receiptImage(type: 'png', name: 'captura.jpg'),
        ]);

        $this->assertStringEndsWith('.png', $receipt->file_path);
        $this->assertSame('image/png', $receipt->file_mime);
    }

    public function test_a_pdf_is_stored_and_not_queued_for_compression(): void
    {
        Queue::fake();

        $receipt = $this->submit($this->layawayIn($this->organization), ['file' => $this->receiptPdf()]);

        $this->assertStringEndsWith('.pdf', $receipt->file_path);
        $this->assertSame('application/pdf', $receipt->file_mime);
        Queue::assertNotPushed(CompressReceiptImage::class);
    }

    public function test_a_file_that_is_not_a_receipt_type_never_reaches_the_disk(): void
    {
        $layaway = $this->layawayIn($this->organization);

        try {
            $this->submit($layaway, ['file' => $this->receiptUpload('captura.jpg', "\x7FELF\x02\x01\x01\x00".str_repeat("\x00", 56))]);
            $this->fail('Se guardó un ejecutable como comprobante.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no es un tipo de comprobante', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
        $this->assertSame(0, DB::table('receipts')->count());
    }

    public function test_the_same_file_in_the_same_organization_is_refused(): void
    {
        $file = $this->receiptImage();
        $first = $this->submit($this->layawayIn($this->organization), ['file' => $file]);

        $exception = $this->refused(fn () => $this->submit($this->layawayIn($this->organization), [
            'file' => $this->sameFileAs($file),
            'reference' => '9999999999',
        ]));

        $this->assertSame(DuplicateCriterion::FileHash, $exception->criterion);
        $this->assertSame('file', $exception->field());
        $this->assertSame([$first->file_path], Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_the_same_bank_and_reference_in_the_same_organization_is_refused(): void
    {
        $this->submit($this->layawayIn($this->organization));

        $exception = $this->refused(fn () => $this->submit($this->layawayIn($this->organization)));

        $this->assertSame(DuplicateCriterion::BankReference, $exception->criterion);
        $this->assertSame('reference', $exception->field());
        $this->assertCount(1, Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_a_rejected_receipt_still_blocks_its_file_and_its_reference(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $file = $this->receiptImage();
        $this->receiptFor($layaway, ReceiptStatus::Rejected, ['file_hash' => hash_file('sha256', (string) $file->getRealPath()), 'reference' => '1111111111']);
        $this->receiptFor($layaway, ReceiptStatus::Rejected, ['bank' => 'Banesco', 'reference' => '0123456789']);

        $byFile = $this->refused(fn () => $this->submit($layaway, ['file' => $file, 'reference' => '2222222222']));
        $byReference = $this->refused(fn () => $this->submit($layaway));

        $this->assertSame(DuplicateCriterion::FileHash, $byFile->criterion);
        $this->assertSame(DuplicateCriterion::BankReference, $byReference->criterion);
    }

    public function test_the_same_file_and_reference_in_another_organization_are_accepted(): void
    {
        $file = $this->receiptImage();
        $theirs = $this->submit($this->layawayIn($this->neighbourOrganization()), ['file' => $file]);

        $ours = $this->submit($this->layawayIn($this->organization), ['file' => $this->sameFileAs($file)]);

        $this->assertSame($theirs->file_hash, $ours->file_hash);
        $this->assertSame([$theirs->reference, $theirs->bank], [$ours->reference, $ours->bank]);
        $this->assertStringStartsWith($this->neighbourOrganization()->getKey().'/', $theirs->file_path);
        $this->assertStringStartsWith($this->organization->getKey().'/', $ours->file_path);
        $this->assertSame(2, DB::table('receipts')->count());
    }

    public function test_a_retry_is_chained_to_the_rejected_receipt_it_replaces(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $approved = $this->receiptFor($layaway, ReceiptStatus::Approved);
        $first = $this->submit($layaway, ['reference' => '1001']);
        $this->assertNull($first->replaces_receipt_id, 'Un comprobante nuevo sin un rechazo delante no sustituye a nada.');

        DB::table('receipts')->where('id', $first->getKey())->update(['status' => 'rejected', 'rejection_reason_code' => 'not_legible']);
        $this->receiptFor($this->layawayIn($this->organization), ReceiptStatus::Rejected);
        $retry = $this->submit($layaway, ['reference' => '1002']);
        $next = $this->submit($layaway, ['reference' => '1003']);

        $this->assertSame((int) $first->getKey(), $retry->replaces_receipt_id);
        $this->assertNull($next->replaces_receipt_id, 'Solo el primero después del rechazo es su reintento.');
        $this->assertNotSame((int) $approved->getKey(), $retry->replaces_receipt_id);
    }

    public function test_cash_receipts_without_reference_do_not_block_each_other(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $cash = ['method' => PaymentMethod::EfectivoUsd, 'bank' => null, 'reference' => null, 'declaredAmount' => '30.00', 'declaredCurrency' => Currency::USD];

        $first = $this->submit($layaway, $cash);
        $second = $this->submit($layaway, $cash);

        $this->assertNull($first->reference);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertCount(2, Storage::disk(Receipt::DISK)->allFiles());
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_layaway_is_refused(LayawayStatus $status): void
    {
        $layaway = $this->layawayIn($this->organization, $status);

        try {
            $this->submit($layaway);
            $this->fail("Un apartado {$status->value} admitió un comprobante.");
        } catch (PaymentNotApplicableException $exception) {
            $this->assertStringContainsString($status->label(), $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
        $this->assertSame(0, DB::table('receipts')->count());
    }

    /**
     * @return array<string, array{LayawayStatus}>
     */
    public static function terminalStatuses(): array
    {
        return [
            'completed' => [LayawayStatus::Completed],
            'cancelled' => [LayawayStatus::Cancelled],
            'expired' => [LayawayStatus::Expired],
        ];
    }

    public function test_an_active_and_an_overdue_layaway_accept_receipts(): void
    {
        $this->submit($this->layawayIn($this->organization, LayawayStatus::Active));
        $this->submit($this->layawayIn($this->organization, LayawayStatus::Overdue), ['reference' => '5555555555']);

        $this->assertSame(2, DB::table('receipts')->where('status', ReceiptStatus::PendingReview->value)->count());
    }

    public function test_a_failure_after_storing_the_file_removes_it(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $written = null;

        Queue::fake();

        Receipt::saving(function (Receipt $receipt) use (&$written): void {
            $written = $receipt->file_path;
            Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);

            throw new RuntimeException('Se cayó la base a mitad de la subida.');
        });

        try {
            $this->submit($layaway);
            $this->fail('La subida no falló.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Se cayó la base a mitad de la subida.', $exception->getMessage());
        }

        $this->assertNotNull($written, 'El fallo llegó antes de guardar el archivo: el test no prueba el rollback.');
        Storage::disk(Receipt::DISK)->assertMissing((string) $written);
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
        $this->assertSame(0, DB::table('receipts')->count());
        Queue::assertNothingPushed();
    }

    #[DataProvider('races')]
    public function test_a_race_lost_on_the_unique_index_is_a_duplicate(DuplicateCriterion $criterion): void
    {
        $layaway = $this->layawayIn($this->organization);
        $file = $this->receiptImage();
        $hash = hash_file('sha256', (string) $file->getRealPath());
        $rivalInserted = false;

        Receipt::saving(function () use ($layaway, $criterion, $hash, &$rivalInserted): void {
            if ($rivalInserted) {
                return;
            }

            $rivalInserted = true;

            $this->receiptFor($layaway, attributes: $criterion === DuplicateCriterion::FileHash
                ? ['file_hash' => $hash, 'reference' => '7777777777']
                : ['bank' => 'Banesco', 'reference' => '0123456789']);
        });

        try {
            $this->submit($layaway, ['file' => $file]);
            $this->fail('La carrera no chocó contra el único.');
        } catch (DuplicateReceiptException $exception) {
            $this->assertSame($criterion, $exception->criterion);
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        }

        $this->assertTrue($rivalInserted);
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles(), 'El archivo del perdedor se quedó en el disco.');
    }

    /**
     * @return array<string, array{DuplicateCriterion}>
     */
    public static function races(): array
    {
        return [
            'mismo archivo' => [DuplicateCriterion::FileHash],
            'misma referencia' => [DuplicateCriterion::BankReference],
        ];
    }

    public function test_it_is_accepted_with_the_storage_quota_exhausted(): void
    {
        $this->subscribe($this->organization);
        $this->within(function (): void {
            $ceiling = (int) app(LimitEnforcer::class)->plan()->max_storage_mb;
            UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::StorageMb], ['value' => $ceiling + 50, 'computed_at' => now()]);
            $this->assertSame(0, app(LimitEnforcer::class)->remaining(UsageMetric::StorageMb), 'El plan no está en su techo: el test no prueba nada.');
        });

        $receipt = $this->submit($this->layawayIn($this->organization));

        $this->assertSame(ReceiptStatus::PendingReview, $receipt->status);
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
    }

    public function test_it_works_with_the_organization_suspended(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $receipt = $this->submit($layaway);

        $this->assertSame(ReceiptStatus::PendingReview, $receipt->status);
    }

    public function test_the_compression_is_queued_after_the_commit(): void
    {
        Queue::fake();
        $layaway = $this->layawayIn($this->organization);

        $receipt = DB::transaction(function () use ($layaway): Receipt {
            $receipt = $this->submit($layaway);
            Queue::assertNothingPushed();

            return $receipt;
        });

        Queue::assertPushedOn('default', CompressReceiptImage::class, fn (CompressReceiptImage $job): bool => $job->receiptId === (int) $receipt->getKey()
            && $job->organizationId === (int) $this->organization->getKey());
    }

    public function test_nothing_is_queued_if_the_surrounding_transaction_rolls_back(): void
    {
        Queue::fake();
        $layaway = $this->layawayIn($this->organization);

        try {
            DB::transaction(function () use ($layaway): void {
                $this->submit($layaway);

                throw new RuntimeException('Algo falló después.');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNothingPushed();
    }

    public function test_a_failed_dispatch_after_the_commit_keeps_the_receipt_and_its_file(): void
    {
        $layaway = $this->layawayIn($this->organization);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('La cola no responde.'));

        $receipt = $this->submit($layaway);

        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
        $this->assertSame(1, DB::table('receipts')->where('id', $receipt->getKey())->count());
    }

    public function test_with_a_sync_queue_the_image_is_compressed_and_the_tenant_survives(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $layaway = $this->layawayIn($this->organization);
        $this->actingAsOrganization($this->organization);

        $receipt = $this->submit($layaway, ['file' => $this->receiptImage(2400, 1200)]);

        $this->assertSame((int) $this->organization->getKey(), app(TenantContext::class)->organizationId());
        $dimensions = getimagesizefromstring((string) Storage::disk(Receipt::DISK)->get($receipt->file_path));
        $this->assertNotFalse($dimensions);
        $this->assertSame([2000, 1000], [$dimensions[0], $dimensions[1]]);
    }

    public function test_the_inbox_is_told_after_the_commit(): void
    {
        Event::fake([ReceiptSubmitted::class]);
        $layaway = $this->layawayIn($this->organization);

        $receipt = DB::transaction(function () use ($layaway): Receipt {
            $receipt = $this->submit($layaway);
            Event::assertNotDispatched(ReceiptSubmitted::class);

            return $receipt;
        });

        Event::assertDispatchedTimes(ReceiptSubmitted::class, 1);
        Event::assertDispatched(ReceiptSubmitted::class, fn (ReceiptSubmitted $event): bool => $event->receiptId === (int) $receipt->getKey()
            && $event->organizationId === (int) $this->organization->getKey()
            && $event->layawayCode === $layaway->code
            && $event->declaredAmount === '850.00'
            && $event->declaredCurrency === 'VES');
    }

    public function test_the_inbox_is_not_told_of_a_receipt_that_does_not_exist(): void
    {
        Event::fake([ReceiptSubmitted::class]);
        $layaway = $this->layawayIn($this->organization);

        try {
            DB::transaction(function () use ($layaway): void {
                $this->submit($layaway);

                throw new RuntimeException('Algo falló después.');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(ReceiptSubmitted::class);

        $file = $this->receiptImage();
        $this->submit($layaway, ['file' => $file, 'reference' => '111']);
        Event::fake([ReceiptSubmitted::class]);
        $this->refused(fn () => $this->submit($layaway, ['file' => $this->sameFileAs($file), 'reference' => '222']));

        Event::assertNotDispatched(ReceiptSubmitted::class);
    }

    public function test_a_broadcast_failure_does_not_undo_the_receipt(): void
    {
        Exceptions::fake();
        $this->assertSame('sync', config('queue.default'));
        $layaway = $this->layawayIn($this->organization);
        $this->actingAsOrganization($this->organization);

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->once()->andThrow(new BroadcastException('Reverb no responde.'));
        app(BroadcastManager::class)->extend('failing', fn () => $broadcaster);
        config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);

        $receipt = $this->submit($layaway);

        $this->assertSame(1, DB::table('receipts')->where('id', $receipt->getKey())->where('status', 'pending_review')->count());
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
        Exceptions::assertReported(fn (BroadcastException $exception): bool => $exception->getMessage() === 'Reverb no responde.');
        $this->assertSame((int) $this->organization->getKey(), app(TenantContext::class)->organizationId());
    }

    public function test_a_queue_failure_while_announcing_does_not_undo_the_receipt(): void
    {
        Exceptions::fake();
        $layaway = $this->layawayIn($this->organization);

        $connection = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $connection->shouldReceive('pushOn')->once()->andThrow(new RuntimeException('Redis no responde.'));
        Queue::shouldReceive('connection')->andReturn($connection);

        $receipt = $this->submit($layaway, ['file' => $this->receiptPdf()]);

        $this->assertSame(1, DB::table('receipts')->where('id', $receipt->getKey())->count());
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Redis no responde.');
    }

    public function test_the_broadcast_is_queued_on_high(): void
    {
        Queue::fake();
        $layaway = $this->layawayIn($this->organization);

        $receipt = $this->submit($layaway);

        Queue::assertPushedOn('high', BroadcastEvent::class, function (BroadcastEvent $job) use ($receipt): bool {
            return $job->event instanceof ReceiptSubmitted && $job->event->receiptId === (int) $receipt->getKey();
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(Layaway $layaway, array $overrides = []): Receipt
    {
        $arguments = $overrides + [
            'layaway' => $layaway,
            'paymentAccount' => null,
            'method' => PaymentMethod::PagoMovil,
            'bank' => 'Banesco',
            'reference' => '0123456789',
            'paidAt' => CarbonImmutable::parse('2026-10-05', 'Asia/Tokyo'),
            'declaredAmount' => '850.00',
            'declaredCurrency' => Currency::VES,
            'note' => null,
            'file' => $overrides['file'] ?? $this->receiptImage(),
        ];

        /** @var Organization $organization */
        $organization = Organization::query()->findOrFail($layaway->organization_id);

        return $this->withinOrganization($organization, fn (): Receipt => app(SubmitReceiptAction::class)->handle(new SubmitReceiptData(...$arguments)));
    }

    /**
     * @param  callable(): mixed  $submission
     */
    private function refused(callable $submission): DuplicateReceiptException
    {
        try {
            $submission();
        } catch (DuplicateReceiptException $exception) {
            $this->assertSame('Este comprobante ya fue reportado.', $exception->getMessage());

            return $exception;
        }

        $this->fail('El duplicado se aceptó.');
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceOf(Layaway $layaway): array
    {
        return [
            'layaway' => (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['status', 'paid_cents', 'remaining_cents', 'next_due_date']),
            'installments' => DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['id', 'paid_cents', 'status', 'paid_at'])->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function within(callable $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback(...));
    }
}
