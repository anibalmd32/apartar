<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Support\SignedFileUrl;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un comprobante del historial del cliente (`REQ-LAY-022` RB-3 a RB-8).
 *
 *  - **Sin identificadores internos** (RB-6): `id` es el público
 *    (`ReceiptFile::publicIdOf()`), y el reintento se encadena con el público
 *    del que reemplaza (RB-5). Ni el `id` de la fila, ni el revisor, ni el
 *    `account_id` de nadie.
 *  - **El motivo del rechazo, tal como lo escribió el negocio** (RB-3): a
 *    diferencia del de una cancelación, este es para el cliente, que lo
 *    necesita para corregir. Sin texto escrito —el rechazo automático al
 *    cancelar el apartado— va la etiqueta del código.
 *  - **La cuenta a la que dice haber pagado** (RB-8), en palabras.
 *  - **El archivo, con una URL firmada de 5 minutos** (RB-4) por la ruta del
 *    portal.
 *  - **«En revisión desde el DD/MM»** pasadas 48 h (SP-6): sin prometer
 *    plazos ni decir que va tarde.
 *
 * Espera `paymentAccount` y `replaces` cargados.
 */
final class ReceiptRowResource extends JsonResource
{
    /** `REQ-LAY-022` SP-6, el mismo umbral que marca el atraso en la bandeja. */
    private const LONG_REVIEW_HOURS = 48;

    public function __construct(
        Receipt $receipt,
        private readonly DateTimeZone $timezone,
        private readonly CarbonImmutable $now,
    ) {
        parent::__construct($receipt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Receipt $receipt */
        $receipt = $this->resource;
        $submitted = CarbonImmutable::instance($receipt->created_at ?? $this->now)->setTimezone($this->timezone);
        $account = $receipt->paymentAccount;

        return [
            'id' => ReceiptFile::publicIdOf($receipt->file_path),
            'submittedAt' => $submitted->toDateString(),
            'method' => $receipt->payment_method->label(),
            'reference' => $receipt->reference,
            'declaredAmount' => MoneyFormatter::format(Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency)),
            'paidToAccount' => $account instanceof PaymentAccount ? $this->describe($account) : null,
            'status' => $receipt->status->value,
            'statusLabel' => $this->statusLabel($receipt, $submitted),
            'rejectionReason' => $receipt->status === ReceiptStatus::Rejected
                ? ($receipt->rejection_reason ?? $receipt->rejection_reason_code?->label())
                : null,
            'fileUrl' => SignedFileUrl::forCustomer($receipt),
            'replacesReceiptId' => $receipt->replaces instanceof Receipt ? ReceiptFile::publicIdOf($receipt->replaces->file_path) : null,
        ];
    }

    private function statusLabel(Receipt $receipt, CarbonImmutable $submitted): string
    {
        if ($receipt->status->awaitsReview() && $submitted->lessThan($this->now->subHours(self::LONG_REVIEW_HOURS))) {
            return sprintf('En revisión desde el %s', $submitted->format('d/m'));
        }

        return $receipt->status->label();
    }

    private function describe(PaymentAccount $account): string
    {
        return implode(' · ', array_filter([$account->method->label(), $account->bank, $account->identifier]));
    }
}
