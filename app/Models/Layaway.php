<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\LayawayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string $idempotency_key
 * @property int $customer_id
 * @property int $product_id
 * @property int|null $catalog_id
 * @property int $quantity
 * @property Money $unit_price_cents
 * @property Money $down_payment_cents
 * @property int $installments_count
 * @property int $frequency_days
 * @property string $product_name_snapshot
 * @property string $product_sku_snapshot
 * @property Money $total_cents
 * @property Money $paid_cents
 * @property Money $remaining_cents
 * @property LayawayStatus $status
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $extended_at
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $next_due_date
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $stock_released_at
 */
class Layaway extends Model
{
    /** @use HasFactory<LayawayFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var list<string>
     */
    public const SNAPSHOT_COLUMNS = [
        'code',
        'idempotency_key',
        'product_id',
        'quantity',
        'unit_price_cents',
        'down_payment_cents',
        'installments_count',
        'frequency_days',
        'product_name_snapshot',
        'product_sku_snapshot',
        'total_cents',
    ];

    /**
     * @var list<string>
     */
    public const BALANCE_COLUMNS = ['paid_cents', 'remaining_cents', 'next_due_date'];

    protected static function booted(): void
    {
        static::saving(static function (Layaway $layaway): void {
            $status = $layaway->getAttribute('status');

            if (! $layaway->exists) {
                if ($status !== null && $status !== LayawayStatus::PendingDownPayment) {
                    throw new LogicException(sprintf(
                        'Un apartado nace en «pending_down_payment» y se intentó crear en «%s». '
                        .'Los demás estados se alcanzan con LayawayStateMachine::transition().',
                        $status instanceof LayawayStatus ? $status->value : (string) $status,
                    ));
                }

                self::assertBornWithoutPayments($layaway);

                return;
            }

            $balance = array_values(array_filter(self::BALANCE_COLUMNS, fn (string $column): bool => $layaway->isDirty($column)));

            if ($balance !== []) {
                throw new LogicException(sprintf(
                    'El balance de un apartado solo lo escribe LayawayBalanceRecalculator::recalculate(), '
                    .'y se intentó guardar: %s. Mueve los pagos o las cuotas y pídele al recalculador que lo derive.',
                    implode(', ', $balance),
                ));
            }

            if ($layaway->isDirty('status')) {
                throw new LogicException(
                    'El estado de un apartado solo lo escribe LayawayStateMachine::transition(): '
                    .'la máquina valida la matriz, escribe la marca de tiempo, ajusta el cupo del plan y emite el '
                    .'evento en la misma transacción. Guardarlo a mano se salta las cuatro cosas.'
                );
            }

            $changed = array_values(array_filter(self::SNAPSHOT_COLUMNS, fn (string $column): bool => $layaway->isDirty($column)));

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Las condiciones de un apartado se congelan al crearlo y no se vuelven a tocar, '
                    .'y se intentó cambiar: %s. No hay recálculo de un apartado: se cancela y se crea otro.',
                    implode(', ', $changed),
                ));
            }
        });
    }

    private static function assertBornWithoutPayments(self $layaway): void
    {
        $paid = $layaway->getAttribute('paid_cents');
        $remaining = $layaway->getAttribute('remaining_cents');
        $total = $layaway->getAttribute('total_cents');

        $paidIsZero = $paid === null || ($paid instanceof Money && $paid->cents === 0);
        $remainingIsTotal = $remaining instanceof Money && $total instanceof Money && $remaining->cents === $total->cents;

        if (! $paidIsZero || ! $remainingIsTotal || $layaway->getAttribute('next_due_date') !== null) {
            throw new LogicException(
                'Un apartado nace sin pagos: paid_cents = 0, remaining_cents = total_cents y sin '
                .'next_due_date. A partir de ahí el balance solo lo escribe LayawayBalanceRecalculator.'
            );
        }
    }

    public function terms(): LayawayTerms
    {
        return new LayawayTerms(
            unitPrice: $this->unit_price_cents,
            downPayment: $this->down_payment_cents,
            quantity: $this->quantity,
            installmentsCount: $this->installments_count,
            frequencyDays: $this->frequency_days,
        );
    }

    /**
     * @return HasMany<Installment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('settled_at')->orderBy('id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompletedNotDelivered(Builder $query): Builder
    {
        return $query->where('status', LayawayStatus::Completed)->whereNull('delivered_at');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Catalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => LayawayStatus::class,
            'quantity' => 'integer',
            'unit_price_cents' => MoneyCast::class,
            'down_payment_cents' => MoneyCast::class,
            'installments_count' => 'integer',
            'frequency_days' => 'integer',
            'total_cents' => MoneyCast::class,
            'paid_cents' => MoneyCast::class,
            'remaining_cents' => MoneyCast::class,
            'expires_at' => 'datetime',
            'extended_at' => 'datetime',
            'activated_at' => 'datetime',
            'next_due_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'stock_released_at' => 'datetime',
        ];
    }
}
