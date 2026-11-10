<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Payment\ReceiptFile;
use App\Domain\Safeguard\SafeguardScope;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentSafeguard;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RecordSafeguardAction
{
    public const DIRECTORY = 'resguardos';

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function handle(Payment $payment, SafeguardData $data, User $actor): PaymentSafeguard
    {
        $layaway = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($actor)->authorize('manage', $layaway);

        if ($payment->voided_at !== null) {
            throw new InvalidArgumentException('Un pago anulado no lleva resguardo.');
        }

        if (! SafeguardScope::appliesTo($layaway)) {
            throw new InvalidArgumentException('El resguardo se activa en el catálogo de este apartado.');
        }

        $stored = $data->proof instanceof UploadedFile ? $this->store($data->proof, (int) $payment->organization_id) : null;

        try {
            [$safeguard, $previous] = DB::transaction(function () use ($payment, $data, $actor, $stored): array {
                $safeguard = PaymentSafeguard::query()->where('payment_id', $payment->getKey())->lockForUpdate()->first()
                    ?? (new PaymentSafeguard)->forceFill(['payment_id' => $payment->getKey()]);
                $previous = $safeguard->proof_path;
                $replaced = $stored !== null || $data->removeProof;

                $safeguard->forceFill([
                    'destination' => $data->destination,
                    'reference' => $data->reference,
                    'note' => $data->note,
                    'recorded_by' => $actor->getKey(),
                    ...($replaced ? ['proof_path' => $stored['path'] ?? null, 'proof_mime' => $stored['mime'] ?? null] : []),
                ])->save();

                return [$safeguard, $replaced ? $previous : null];
            });
        } catch (Throwable $exception) {
            $this->discard($stored['path'] ?? null);

            throw $exception;
        }

        $this->discard($previous);

        return $safeguard;
    }

    /**
     * @return array{path: string, mime: string}
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    private function store(UploadedFile $file, int $organizationId): array
    {
        [$mime, $extension] = ReceiptFile::typeOf($file);
        $path = self::DIRECTORY.'/'.ReceiptFile::pathFor($organizationId, $extension);

        if (Storage::disk(Receipt::DISK)->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new RuntimeException(sprintf('El disco «%s» no guardó el comprobante del resguardo en %s.', Receipt::DISK, $path));
        }

        return ['path' => $path, 'mime' => $mime];
    }

    private function discard(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            Storage::disk(Receipt::DISK)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
