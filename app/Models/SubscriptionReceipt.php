<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\SubscriptionReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El comprobante con el que un negocio paga una factura de su suscripción
 * (`REQ-SUB-006` RB-1, RB-2, `T-069`).
 *
 * Es el mismo mecanismo que los comprobantes de clientes ([22]) un nivel más
 * arriba, y por eso reutiliza sus piezas en vez de copiarlas: los estados
 * (`ReceiptStatus`), los métodos de pago (`PaymentMethod`), la huella y el
 * tipo de archivo (`ReceiptFile`) y el bloqueo de duplicados
 * (`DuplicateReceipts`).
 *
 * **No consume `storage_mb`** (`REQ-SUB-003` RB-2 dice «comprobantes»): son
 * los del negocio pagándole a la plataforma, y cobrarle cuota por pagarnos
 * sería absurdo. Lo hace solo: `UsageAggregates` suma `receipts`, no esta
 * tabla, y el archivo vive bajo `subscriptions/` en el disco.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $subscription_invoice_id
 * @property int $submitted_by
 * @property PaymentMethod $payment_method
 * @property string|null $bank
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon $paid_at
 * @property string $declared_amount
 * @property Currency $declared_currency
 * @property string $file_path
 * @property string $file_hash
 * @property string $file_mime
 * @property int $file_size
 * @property ReceiptStatus $status
 * @property string|null $settled_amount
 * @property Currency|null $settled_currency
 * @property int|null $settled_cents_usd
 * @property string|null $rate_applied
 * @property RateSource|null $rate_source
 * @property \Illuminate\Support\Carbon|null $rate_published_at
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property-read SubscriptionInvoice $invoice
 */
class SubscriptionReceipt extends Model
{
    /** @use HasFactory<SubscriptionReceiptFactory> */
    use BelongsToOrganization, HasFactory;

    /** Bajo esta carpeta del disco de comprobantes, aparte de los de clientes. */
    public const DIRECTORY = 'subscriptions';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
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
            'settled_amount' => 'decimal:2',
            'settled_currency' => Currency::class,
            'settled_cents_usd' => 'integer',
            'rate_source' => RateSource::class,
            'rate_published_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }
}
