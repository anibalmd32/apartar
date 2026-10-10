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

/**
 * El negocio sube el comprobante de una factura de su suscripción
 * (`REQ-SUB-006` RB-1, RB-2, RB-7, `T-069`).
 *
 * **No reimplementa nada de [22]** (RB-2): el tipo real y el tamaño del
 * archivo (`ReceiptFile::typeOf()`), su huella (`ReceiptFile::fingerprint()`),
 * el bloqueo de duplicados con sus dos criterios y su carrera
 * (`DuplicateReceipts`, la misma excepción `DuplicateReceiptException`) y el
 * disco privado de comprobantes. El duplicado se busca **en toda la
 * plataforma** (`SubscriptionReceiptDuplicates`): aquí el que cobra es uno
 * solo. Lo que cambia es a quién se paga: el
 * comprobante queda para la plataforma, que lo aprueba o lo rechaza
 * (`ApproveSubscriptionReceiptAction`, `RejectSubscriptionReceiptAction`).
 *
 * Lo sube quien gobierna la facturación (`SubscriptionPolicy::uploadPaymentProof`,
 * capacidad `billing`). La factura pasa a `under_review`; tras un rechazo
 * vuelve a `pending` y se puede subir otro (RB-7). Dos comprobantes para la
 * misma factura pueden convivir en revisión (SP-8): el primero que se aprueba
 * la liquida.
 *
 * **No mueve `storage_mb`**: ver `SubscriptionReceipt`.
 */
final class SubmitSubscriptionReceiptAction
{
    public function __construct(private readonly SubscriptionReceiptDuplicates $duplicates) {}

    /**
     * @throws AuthorizationException si el actor no gobierna la facturación
     * @throws InvoiceNotPayableException si la factura está pagada o anulada
     * @throws DuplicateReceiptException si el archivo o la referencia ya se reportaron (`REQ-PAY-003`)
     * @throws InvalidArgumentException si el archivo no es de un tipo de comprobante o pasa del tamaño máximo
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

                // En toda la plataforma, que es quien cobra: ver
                // `SubscriptionReceiptDuplicates`.
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

                // Confirmada la fila, el archivo es de un comprobante que existe.
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
