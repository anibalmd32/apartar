<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Un pago conciliado: lo único que mueve el balance de un apartado
 * (`REQ-PAY-005`, `REQ-PAY-007`, `REQ-FX-006`).
 *
 * Lo crea solo `PaymentApplier` (`T-058`), desde una aprobación o un pago
 * manual, dentro de la transacción que bloquea el apartado.
 *
 * ## Inmutable
 *
 * El snapshot de la tasa —con qué tasa se convirtió lo declarado, de qué
 * fuente y cuándo se publicó— **no se actualiza nunca** (`REQ-FX-006` RB-3):
 * el pago del mes pasado dice «tasa 42,50» aunque hoy esté a 60, y el saldo
 * no se mueve con ella (RB-7). Tampoco cambian el importe conciliado, el
 * apartado ni el comprobante del que salió: la reasignación mueve **la
 * aplicación** del pago, nunca el pago (`REQ-PAY-009` RB-1), y corregir uno
 * exige anularlo, que está fuera de alcance en v1. Es la misma guarda
 * `saving` que el snapshot de `Layaway` y el importe de `Installment`.
 *
 * Fuera de la guarda quedan la nota, que se puede corregir, y `registered_by`,
 * que la clave ajena pone a `NULL` si el usuario llegara a borrarse
 * (`nullOnDelete`; los usuarios se desactivan, no se borran, `REQ-ORG-005`).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $layaway_id
 * @property int|null $receipt_id nulo en un pago manual
 * @property string|null $idempotency_key la del formulario del pago manual (`REQ-PAY-007` SP-5)
 * @property Money $amount_cents_usd lo que entra en el balance
 * @property Currency $declared_currency
 * @property string $declared_amount en su moneda, con dos decimales
 * @property string $rate_applied ocho decimales; `1` en un pago en dólares (`REQ-FX-006` RB-4)
 * @property RateSource $rate_source
 * @property \Illuminate\Support\Carbon|null $rate_published_at nula solo con una tasa manual
 * @property \Illuminate\Support\Carbon $settled_at
 * @property int|null $registered_by quién lo registró a mano
 * @property string|null $note
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Lo que se fija al crear el pago y no se vuelve a tocar.
     *
     * @var list<string>
     */
    public const IMMUTABLE_COLUMNS = [
        'layaway_id',
        'receipt_id',
        'idempotency_key',
        'amount_cents_usd',
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
                    'Un pago conciliado es inmutable (REQ-FX-006 RB-3) y se intentó cambiar: %s. La reasignación '
                    .'mueve sus asignaciones, no el pago (REQ-PAY-009 RB-1); corregirlo exige anularlo.',
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
     * Cómo se repartió entre las cuotas, en el orden en que se cubrieron.
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    /**
     * Si lo registró el negocio a mano, sin comprobante (`REQ-LAY-022` RB-7).
     */
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
            'declared_currency' => Currency::class,
            'declared_amount' => 'decimal:2',
            'rate_applied' => 'decimal:8',
            'rate_source' => RateSource::class,
            'rate_published_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
