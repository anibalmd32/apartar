<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use Illuminate\Support\Str;

/**
 * Siembra comprobantes para los tests que necesitan que existan (`T-054`).
 *
 * Hasta `T-059` no hay `SubmitReceiptAction` —ni archivo, ni hash calculado,
 * ni comprobación de duplicados—, así que aquí la fila se escribe directamente
 * con el modelo, dentro de la organización del apartado. El archivo es una
 * ruta que no existe y el hash, uno aleatorio: lo que estos tests necesitan es
 * la fila, con su estado.
 *
 * `bank` y `reference` van juntos, como exige el `CHECK` de la tabla.
 */
trait CreatesReceipts
{
    /**
     * Un comprobante de ese apartado, en ese estado.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function receiptFor(Layaway $layaway, ReceiptStatus $status = ReceiptStatus::PendingReview, array $attributes = []): Receipt
    {
        $organization = Organization::query()->findOrFail($layaway->organization_id);

        return app(TenantContext::class)->runFor($organization, function () use ($layaway, $status, $attributes): Receipt {
            $receipt = new Receipt;
            $receipt->forceFill([
                'layaway_id' => $layaway->getKey(),
                'customer_id' => $layaway->customer_id,
                'payment_account_id' => null,
                'payment_method' => PaymentMethod::PagoMovil,
                'bank' => 'Banesco',
                'reference' => (string) random_int(100_000, 999_999_999),
                'paid_at' => now()->toDateString(),
                'declared_amount' => '850.00',
                'declared_currency' => Currency::VES,
                'file_path' => 'receipts/'.Str::uuid().'.jpg',
                'file_hash' => hash('sha256', (string) Str::uuid()),
                'file_mime' => 'image/jpeg',
                'file_size' => 120_000,
                'status' => $status,
                ...$attributes,
            ])->save();

            return $receipt;
        });
    }
}
