<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\DuplicateReceipts;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use App\Queries\Platform\SubscriptionReceiptDuplicates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SubmitSubscriptionReceiptAction
{
    public function __construct(private readonly SubscriptionReceiptDuplicates $duplicates) {}

    /**
     * @throws AuthorizationException
     * @throws InvoiceNotPayableException
     * @throws DuplicateReceiptException
     * @throws InvalidArgumentException
     */
    public function handle(SubmitSubscriptionReceiptData $data, User $actor): SubscriptionReceipt
    {
        Gate::forUser($actor)->authorize('uploadPaymentProof', Subscription::query()->firstOrFail());

        $file = $data->file;
        [$mime, $extension] = ReceiptFile::typeOf($file);
        $hash = ReceiptFile::fingerprint($file);
        $disk = Storage::disk(Receipt::DISK);
        $stored = null;

        try {
            return DB::transaction(function () use ($data, $actor, $file, $mime, $extension, $hash, $disk, &$stored): SubscriptionReceipt {
                /** @var SubscriptionInvoice $invoice */
                $invoice = SubscriptionInvoice::query()->whereKey($data->invoice->getKey())->lockForUpdate()->firstOrFail();

                if (! $invoice->status->acceptsReceipts()) {
                    throw InvoiceNotPayableException::of($invoice);
                }

                $criterion = $this->duplicates->criterionFor($hash, $data->bank, $data->reference);

                if ($criterion !== null) {
                    throw new DuplicateReceiptException($criterion);
                }

                $stored = SubscriptionReceipt::DIRECTORY.'/'.ReceiptFile::pathFor($invoice->organization_id, $extension);

                if ($disk->putFileAs(dirname($stored), $file, basename($stored)) === false) {
                    throw new RuntimeException(sprintf('El disco «%s» no guardó el comprobante en %s.', Receipt::DISK, $stored));
                }

                $receipt = new SubscriptionReceipt;
                $receipt->forceFill([
                    'organization_id' => $invoice->organization_id,
                    'subscription_invoice_id' => $invoice->getKey(),
                    'submitted_by' => $actor->getKey(),
                    'payment_method' => $data->method,
                    'bank' => $data->bank,
                    'reference' => $data->reference,
                    'paid_at' => $data->paidAt->toDateString(),
                    'declared_amount' => $data->declaredAmount,
                    'declared_currency' => $data->declaredCurrency,
                    'file_path' => $stored,
                    'file_hash' => $hash,
                    'file_mime' => $mime,
                    'file_size' => (int) $file->getSize(),
                    'status' => ReceiptStatus::PendingReview,
                ])->save();

                $invoice->forceFill(['status' => InvoiceStatus::UnderReview])->save();

                DB::afterCommit(function () use (&$stored): void {
                    $stored = null;
                });

                return $receipt;
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                try {
                    $disk->delete($stored);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                $criterion = DuplicateReceipts::fromViolation($exception, (new SubscriptionReceipt)->getTable());

                throw $criterion === null ? $exception : new DuplicateReceiptException($criterion, $exception);
            }

            throw $exception;
        }
    }
}
