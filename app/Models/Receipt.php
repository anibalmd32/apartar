<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * El comprobante que sube un cliente (`REQ-PAY-001`).
 *
 * Nació mínimo en `T-054`, para que las preguntas que el resto del sistema ya
 * le hacía —`LayawayReceipts`, `RecordedReceipts`— fueran por el global scope
 * en cuanto existió la tabla, y `T-055` le dio su *factory* y sus relaciones.
 * La subida, con su archivo y sus duplicados, es de `T-059`; la aprobación y
 * el rechazo, de `T-061` y `T-062`.
 *
 * Nace en `pending_review` y no toca el balance (RB-6): lo que mueve el dinero
 * es el `Payment` que crea su aprobación, uno como mucho
 * (`UNIQUE(organization_id, receipt_id)` en `payments`).
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
 * @property int|null $reviewed_by nulo también cuando lo rechazó el sistema al cancelar
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property RejectionReasonCode|null $rejection_reason_code
 * @property string|null $rejection_reason
 * @property int|null $replaces_receipt_id
 */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @return BelongsTo<Layaway, $this>
     */
    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<PaymentAccount, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    /**
     * Quién del negocio lo revisó. Nunca viaja al cliente (`REQ-LAY-022` RB-6).
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * El comprobante rechazado al que este reintenta (`REQ-LAY-022` RB-5).
     *
     * @return BelongsTo<Receipt, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_receipt_id');
    }

    /**
     * Los reintentos de este comprobante.
     *
     * @return HasMany<Receipt, $this>
     */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_receipt_id');
    }

    /**
     * El pago que creó su aprobación, si se aprobó.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'date',
            'declared_amount' => 'decimal:2',
            'declared_currency' => Currency::class,
            'file_size' => 'integer',
            'status' => ReceiptStatus::class,
            'reviewed_at' => 'datetime',
            'rejection_reason_code' => RejectionReasonCode::class,
        ];
    }
}
