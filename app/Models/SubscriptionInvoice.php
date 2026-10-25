<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
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
     * @var list<string>
     */
    public const IMMUTABLE_COLUMNS = ['organization_id', 'plan_id', 'kind', 'period_start', 'period_end', 'amount_cents', 'currency', 'due_date', 'issued_at'];

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        $guard = static function (SubscriptionInvoice $invoice): void {
            if (! $invoice->exists) {
                return;
            }

            $changed = array_values(array_filter(self::IMMUTABLE_COLUMNS, fn (string $column): bool => $invoice->isDirty($column)));

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Una factura emitida es inmutable y se intentó cambiar: %s. Corregirla es anularla y emitir otra.',
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

    public function number(): string
    {
        $sequence = self::query()
            ->where('organization_id', $this->organization_id)
            ->where('id', '<=', $this->getKey())
            ->count();

        return self::formatNumber($this->issued_at, $sequence);
    }

    public static function formatNumber(CarbonInterface $issuedAt, int $sequence): string
    {
        return sprintf('F-%s-%04d', $issuedAt->format('Y'), $sequence);
    }

    public function amount(): Money
    {
        return new Money($this->amount_cents);
    }

    public function outstanding(): Money
    {
        return new Money(max(0, $this->amount_cents - $this->paid_cents));
    }

    public function displayStatus(CarbonImmutable $today): InvoiceStatus
    {
        if ($this->status === InvoiceStatus::Pending && $this->due_date->toDateString() < $today->toDateString()) {
            return InvoiceStatus::Overdue;
        }

        return $this->status;
    }
}
