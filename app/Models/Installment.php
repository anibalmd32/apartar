<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\InstallmentFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $layaway_id
 * @property int $number
 * @property \Illuminate\Support\Carbon $due_date
 * @property Money $amount_cents
 * @property Money $paid_cents
 * @property InstallmentStatus $status
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
class Installment extends Model
{
    /** @use HasFactory<InstallmentFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var list<string>
     */
    public const IMMUTABLE_COLUMNS = ['layaway_id', 'number', 'amount_cents'];

    protected static function booted(): void
    {
        static::saving(static function (Installment $installment): void {
            if (! $installment->exists) {
                return;
            }

            $changed = array_values(array_filter(self::IMMUTABLE_COLUMNS, fn (string $column): bool => $installment->isDirty($column)));

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Una cuota es inmutable en importe y se intentó cambiar: %s. '
                    .'Lo único que se mueve de una cuota es su vencimiento, con RescheduleInstallmentAction.',
                    implode(', ', $changed),
                ));
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Overdue);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnpaidDueBefore(Builder $query, string $day): Builder
    {
        return $query->whereColumn('paid_cents', '<', 'amount_cents')->where('due_date', '<', $day);
    }

    /**
     * @return BelongsTo<Layaway, $this>
     */
    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @param  list<self>  $installments
     */
    public static function scheduleOf(array $installments, DateTimeZone $timezone): AmortizationSchedule
    {
        return new AmortizationSchedule(array_map(
            fn (self $row): ScheduledInstallment => new ScheduledInstallment(
                $row->number,
                CarbonImmutable::parse($row->due_date->toDateString(), $timezone)->startOfDay(),
                $row->amount_cents,
                $row->paid_cents,
                (int) $row->getKey(),
            ),
            $installments,
        ));
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'due_date' => 'date',
            'amount_cents' => MoneyCast::class,
            'paid_cents' => MoneyCast::class,
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }
}
