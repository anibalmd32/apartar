<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\DuplicateReceipts;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use App\Domain\Tenancy\TenantContext;
use App\Events\ReceiptSubmitted;
use App\Jobs\CompressReceiptImage;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SubmitReceiptAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LayawayReceipts $receipts,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * @throws ModelNotFoundException
     * @throws PaymentNotApplicableException
     * @throws DuplicateReceiptException
     * @throws InvalidArgumentException
     */
    public function handle(SubmitReceiptData $data): Receipt
    {
        $file = $data->file;
        [$mime, $extension] = ReceiptFile::typeOf($file);
        $hash = ReceiptFile::fingerprint($file);
        $disk = Storage::disk(Receipt::DISK);
        $stored = null;

        try {
            return DB::transaction(function () use ($data, $file, $mime, $extension, $hash, $disk, &$stored): Receipt {
                $organization = Organization::query()->whereKey($data->layaway->organization_id)->sharedLock()->firstOrFail();

                if (! $organization->status->acceptsCustomerReceipts()) {
                    throw PaymentNotApplicableException::organizationClosed();
                }

                /** @var Layaway $layaway */
                $layaway = Layaway::query()->whereKey($data->layaway->getKey())->lockForUpdate()->firstOrFail();

                if (! $layaway->status->acceptsPayments()) {
                    throw PaymentNotApplicableException::layawayNotCollecting($layaway->status);
                }

                $criterion = $this->duplicateOf($hash, $data->bank, $data->reference);

                if ($criterion !== null) {
                    throw new DuplicateReceiptException($criterion);
                }

                $replaces = $this->receipts->rejectedToReplace($layaway)?->getKey();

                $stored = ReceiptFile::pathFor((int) $layaway->organization_id, $extension);
                $this->store($disk, $file, $stored);

                $receipt = new Receipt;
                $receipt->forceFill([
                    'organization_id' => $layaway->organization_id,
                    'layaway_id' => $layaway->getKey(),
                    'customer_id' => $layaway->customer_id,
                    'payment_account_id' => $data->paymentAccount?->getKey(),
                    'replaces_receipt_id' => $replaces,
                    'payment_method' => $data->method,
                    'bank' => $data->bank,
                    'reference' => $data->reference,
                    'paid_at' => $data->paidAt->toDateString(),
                    'declared_amount' => $data->declaredAmount,
                    'declared_currency' => $data->declaredCurrency,
                    'note' => $data->note,
                    'file_path' => $stored,
                    'file_hash' => $hash,
                    'file_mime' => $mime,
                    'file_size' => (int) $file->getSize(),
                    'status' => ReceiptStatus::PendingReview,
                ])->save();

                DB::afterCommit(function () use (&$stored): void {
                    $stored = null;
                });

                $this->compressAfterCommit($receipt, $mime);
                $this->measureStorageAfterCommit();
                $this->announceAfterCommit($receipt, $layaway->code);

                ServerTelemetry::record(
                    TelemetryEventName::ReceiptUploaded,
                    organizationId: (int) $layaway->organization_id,
                    catalogId: $layaway->catalog_id === null ? null : (int) $layaway->catalog_id,
                    subjectId: (int) $layaway->getKey(),
                    payload: ['method' => $data->method->value],
                    customerId: (int) $layaway->customer_id,
                );

                return $receipt;
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                $this->discard($disk, $stored);
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                throw $this->duplicateFrom($exception);
            }

            throw $exception;
        }
    }

    public function duplicateOf(string $fileHash, ?string $bank, ?string $reference): ?DuplicateCriterion
    {
        return DuplicateReceipts::criterionFor(Receipt::query(), $fileHash, $bank, $reference);
    }

    public function fileTaken(string $fileHash): bool
    {
        return DuplicateReceipts::fileTaken(Receipt::query(), $fileHash);
    }

    public function referenceTaken(?string $bank, ?string $reference): bool
    {
        return DuplicateReceipts::referenceTaken(Receipt::query(), $bank, $reference);
    }

    /**
     * @throws RuntimeException
     */
    private function store(Filesystem $disk, UploadedFile $file, string $path): void
    {
        if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new RuntimeException(sprintf('El disco «%s» no guardó el comprobante en %s.', Receipt::DISK, $path));
        }
    }

    private function discard(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function measureStorageAfterCommit(): void
    {
        $organization = $this->context->organization();

        DB::afterCommit(function () use ($organization): void {
            try {
                $this->usage->refresh($organization, UsageMetric::StorageMb);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function compressAfterCommit(Receipt $receipt, string $mime): void
    {
        if (! CompressReceiptImage::compresses($mime)) {
            return;
        }

        $organization = $this->context->organization();
        $receiptId = (int) $receipt->getKey();

        DB::afterCommit(function () use ($organization, $receiptId): void {
            try {
                $this->context->runFor($organization, function () use ($organization, $receiptId): void {
                    Bus::dispatch(new CompressReceiptImage($receiptId, (int) $organization->getKey()));
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function announceAfterCommit(Receipt $receipt, string $layawayCode): void
    {
        $organization = $this->context->organization();
        $event = ReceiptSubmitted::of($receipt, $layawayCode);

        DB::afterCommit(function () use ($organization, $event): void {
            try {
                $this->context->runFor($organization, fn () => event($event));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function duplicateFrom(UniqueConstraintViolationException $exception): Throwable
    {
        $criterion = DuplicateReceipts::fromViolation($exception, (new Receipt)->getTable());

        return $criterion === null ? $exception : new DuplicateReceiptException($criterion, $exception);
    }
}
