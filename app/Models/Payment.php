<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $layaway_id
 * @property int|null $receipt_id
 * @property PaymentMethod|null $payment_method
 * @property int|null $payment_account_id
 * @property \Illuminate\Support\Carbon|null $paid_on
 * @property string|null $idempotency_key
 * @property Money $amount_cents_usd
 * @property Money $unallocated_cents
 * @property Currency $declared_currency
 * @property string $declared_amount
 * @property string $rate_applied
 * @property RateSource $rate_source
 * @property \Illuminate\Support\Carbon|null $rate_published_at
 * @property \Illuminate\Support\Carbon $settled_at
 * @property int|null $registered_by
 * @property string|null $note
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var list<string>
     */
    public const IMMUTABLE_COLUMNS = [
        'layaway_id',
        'receipt_id',
        'payment_method',
        'payment_account_id',
        'paid_on',
        'idempotency_key',
        'amount_cents_usd',
        'unallocated_cents',
        'declared_currency',
        'declared_amount',
        'rate_applied',
        'rate_source',
        'rate_published_at',
        'settled_at',
    ];

    protected static function booted(): void
    {
        static::saving(static function (Payment $payment): void {
            if (! $payment->exists) {
                return;
            }

            $changed = array_values(array_filter(self::IMMUTABLE_COLUMNS, fn (string $column): bool => $payment->isDirty($column)));

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Un pago conciliado es inmutable y se intentó cambiar: %s. La reasignación '
                    .'mueve sus asignaciones, no el pago; corregirlo exige anularlo.',
                    implode(', ', $changed),
                ));
            }
        });
    }

    /**
     * @return BelongsTo<Layaway, $this>
     */
    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    public function isManual(): bool
    {
        return $this->receipt_id === null;
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents_usd' => MoneyCast::class,
            'unallocated_cents' => MoneyCast::class,
            'declared_currency' => Currency::class,
            'declared_amount' => 'decimal:2',
            'rate_applied' => 'decimal:8',
            'rate_source' => RateSource::class,
            'rate_published_at' => 'datetime',
            'settled_at' => 'datetime',
            'payment_method' => PaymentMethod::class,
            'paid_on' => 'date',
        ];
    }
}
