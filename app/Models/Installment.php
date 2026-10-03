<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Una cuota del cronograma de un apartado (`REQ-LAY-004`).
 *
 * Nace al **activar** el apartado (RB-1), y solo la crea
 * `ActivateLayawayAction` (`T-046`), que materializa lo que calculó
 * `ScheduleGenerator`.
 *
 * ## El importe no se mueve
 *
 * RB-6: las cuotas son **inmutables en importe**, y la única que mueve algo es
 * `RescheduleInstallmentAction` (`REQ-LAY-011`), que mueve `due_date`. Así que
 * guardar una cuota existente con `amount_cents`, `number` o `layaway_id`
 * cambiados **lanza**, igual que `Layaway` con su snapshot: un importe
 * retocado rompe `sum(amount_cents) === financiado` (RB-5) sin que nada lo
 * diga hasta que la reconciliación lo encuentre.
 *
 * ## `due_date` es un día, no un instante
 *
 * `DATE` en la base y `date` aquí (`T-039`): la cuota vence el día 25 **en la
 * zona de la organización**, y «vencida» se decide comparando días contra el
 * «hoy» de esa zona calculado en PHP, nunca con `CURDATE()`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $layaway_id
 * @property int $number 1…`installments_count`
 * @property \Illuminate\Support\Carbon $due_date un día del calendario de la organización
 * @property Money $amount_cents inmutable (RB-6)
 * @property Money $paid_cents
 * @property InstallmentStatus $status
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
class Installment extends Model
{
    use BelongsToOrganization;

    /**
     * Lo que fija la materialización y ya no se toca (RB-6). `due_date` no
     * está: la reprogramación la mueve (`REQ-LAY-011`).
     *
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
                    'Una cuota es inmutable en importe (REQ-LAY-004 RB-6) y se intentó cambiar: %s. '
                    .'Lo único que se mueve de una cuota es su vencimiento, con RescheduleInstallmentAction (REQ-LAY-011).',
                    implode(', ', $changed),
                ));
            }
        });
    }

    /**
     * Las cuotas marcadas en mora (`REQ-LAY-007`).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Overdue);
    }

    /**
     * Las que no están cubiertas y vencen **antes** de ese día: las que
     * entran en mora si ese día es `hoy − gracia` (RB-1, RB-4).
     *
     * Recibe el día como `Y-m-d` ya calculado en la zona de la organización;
     * aquí no se pregunta la hora a MySQL (`T-039`).
     *
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
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            // Un día del calendario de la organización, no un instante.
            'due_date' => 'date',
            'amount_cents' => MoneyCast::class,
            'paid_cents' => MoneyCast::class,
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }
}
