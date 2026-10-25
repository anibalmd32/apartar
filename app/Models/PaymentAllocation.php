<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $payment_id
 * @property int $installment_id
 * @property Money $amount_cents
 */
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use BelongsToOrganization, HasFactory;

    protected static function booted(): void
    {
        static::saving(static function (PaymentAllocation $allocation): void {
            if ($allocation->exists && $allocation->isDirty(['payment_id', 'installment_id', 'amount_cents'])) {
                throw new LogicException(
                    'Una asignación de pago no se edita: la reasignación borra el reparto del pago y '
                    .'escribe el nuevo en la misma transacción.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Installment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
        ];
    }
}
