<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El comprobante que sube un cliente (`REQ-PAY-001`).
 *
 * **Nace aquí en su forma mínima** (`T-054`): lo que hace falta para que las
 * preguntas que el resto del sistema ya le hacía —`LayawayReceipts`,
 * `RecordedReceipts`— vayan por el global scope del tenant en cuanto existe la
 * tabla. La subida, con su archivo, sus duplicados y su `SubmitReceiptAction`,
 * es de `T-059`; la aprobación y el rechazo, de `T-061` y `T-062`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $layaway_id
 * @property int $customer_id
 * @property int|null $payment_account_id a qué cuenta del negocio dice haber pagado (`REQ-PAY-001` RB-3)
 * @property PaymentMethod $payment_method
 * @property string|null $bank va con `reference`, o no va (un `CHECK` de la tabla)
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon $paid_at un día del calendario del cliente
 * @property string $declared_amount sin convertir: la conversión es de la aprobación (`REQ-FX-006`)
 * @property Currency $declared_currency
 * @property string|null $note
 * @property string $file_path
 * @property string $file_hash `sha256` del archivo tal como se subió
 * @property string $file_mime
 * @property int $file_size
 * @property ReceiptStatus $status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property RejectionReasonCode|null $rejection_reason_code
 * @property string|null $rejection_reason
 * @property int|null $replaces_receipt_id
 */
class Receipt extends Model
{
    use BelongsToOrganization;

    /**
     * @return BelongsTo<Layaway, $this>
     */
    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    /**
     * @return BelongsTo<PaymentAccount, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'date',
            'declared_currency' => Currency::class,
            'file_size' => 'integer',
            'status' => ReceiptStatus::class,
            'reviewed_at' => 'datetime',
            'rejection_reason_code' => RejectionReasonCode::class,
        ];
    }
}
