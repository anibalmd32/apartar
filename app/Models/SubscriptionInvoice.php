<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Una factura de la suscripción (`REQ-SUB-006`, `T-069`).
 *
 * Del tenant para leerla —el historial de un negocio es suyo— y emitida por
 * la plataforma (`IssueInvoiceAction`). **Inmutable en importe, período y
 * plan** (RB-3): corregirla es anularla y emitir otra. Lo que sí se mueve es
 * lo cobrado (`paid_cents`, que crece con cada aprobación, SP-2) y el estado.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property InvoiceKind $kind
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property int $amount_cents
 * @property int $paid_cents
 * @property string $currency
 * @property InvoiceStatus $status
 * @property \Illuminate\Support\Carbon $due_date
 * @property \Illuminate\Support\Carbon $issued_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property string|null $void_reason
 * @property-read Plan $plan
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubscriptionReceipt> $receipts
 */
class SubscriptionInvoice extends Model
{
    /** @use HasFactory<SubscriptionInvoiceFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Lo que una factura emitida no puede cambiar (RB-3).
     *
     * @var list<string>
     */
    public const IMMUTABLE_COLUMNS = ['organization_id', 'plan_id', 'kind', 'period_start', 'period_end', 'amount_cents', 'currency', 'due_date', 'issued_at'];

    /** @var list<string> */
    protected $fillable = [];

    /**
     * `saving` y también `updating`: `increment()` sobre el modelo dispara el
     * segundo y no el primero. Lo que este guarda no cubre es escribir sin
     * modelo (`query()->update()`, `DB::table`): nadie lo hace con facturas, y
     * un `UPDATE` a mano en producción es una decisión, no un descuido.
     */
    protected static function booted(): void
    {
        $guard = static function (SubscriptionInvoice $invoice): void {
            if (! $invoice->exists) {
                return;
            }

            $changed = array_values(array_filter(self::IMMUTABLE_COLUMNS, fn (string $column): bool => $invoice->isDirty($column)));

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Una factura emitida es inmutable (REQ-SUB-006 RB-3) y se intentó cambiar: %s. Corregirla es anularla y emitir otra.',
                    implode(', ', $changed),
                ));
            }
        };

        static::saving($guard);
        static::updating($guard);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'amount_cents' => 'integer',
            'paid_cents' => 'integer',
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SubscriptionReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(SubscriptionReceipt::class);
    }

    /**
     * El importe de la factura, en dólares (RB-4).
     */
    public function amount(): Money
    {
        return new Money($this->amount_cents);
    }

    /**
     * Lo que falta por pagar: el faltante exacto de un pago parcial (SP-2).
     */
    public function outstanding(): Money
    {
        return new Money(max(0, $this->amount_cents - $this->paid_cents));
    }

    /**
     * El estado tal como se enseña: `pending` con el vencimiento pasado es
     * `overdue` (ver `InvoiceStatus`).
     *
     * `$today` es la fecha **del negocio**: una factura vence al acabar su día
     * en la zona de quien la paga, no en la de la plataforma.
     */
    public function displayStatus(CarbonImmutable $today): InvoiceStatus
    {
        if ($this->status === InvoiceStatus::Pending && $this->due_date->toDateString() < $today->toDateString()) {
            return InvoiceStatus::Overdue;
        }

        return $this->status;
    }
}
