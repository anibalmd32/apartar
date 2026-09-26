<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Un apartado (`REQ-LAY-001`, `REQ-LAY-005`).
 *
 * **Llega en su forma mínima con `T-042`**, como `Catalog` con `T-024`: la
 * tabla, el aislamiento del tenant y los *casts* del estado y de las fechas,
 * porque `LayawayStateMachine` necesita un modelo al que transicionar. Sus
 * relaciones, sus *casts* de dinero, `terms()` y `LayawayCode` los pone
 * `T-045`, que es quien los usa.
 *
 * ## El estado solo lo escribe la máquina
 *
 * `REQ-LAY-005` RB-2: **ninguna** escritura de estado ocurre fuera de
 * `LayawayStateMachine::transition()`. Un `$layaway->status = …; save()` en
 * cualquier acción convertiría la matriz de RB-1 en una sugerencia, y el día
 * que apareciera un `completed` con cuotas pendientes no habría dónde mirar.
 * Por eso no está prohibido: **está impedido**, en dos capas.
 *
 *  - **Aquí**: guardar un apartado con el estado cambiado **lanza**, y crearlo
 *    en un estado que no sea `pending_down_payment` también
 *    (`REQ-LAY-001` RB-7). Cubre el `save()`, el `update()` y el `forceFill()`
 *    de un modelo, que es como se escribe casi todo en este proyecto.
 *  - **En `LayawayStatusWritesTest`**: lo que no pasa por un modelo —un
 *    `update()` del *query builder*, un `DB::table('layaways')`— no dispara
 *    eventos, así que lo caza un test de arquitectura que recorre `app/` y
 *    solo admite la escritura del estado en la máquina.
 *
 * La máquina escribe por el *query builder* precisamente por eso: es la única
 * escritura que no pasa por esta guarda, y la única que el test de
 * arquitectura admite.
 *
 * @property int $id
 * @property int $organization_id
 * @property LayawayStatus $status solo lo escribe `LayawayStateMachine`
 * @property \Illuminate\Support\Carbon|null $expires_at plazo de la inicial, no el momento en que expiró
 * @property \Illuminate\Support\Carbon|null $extended_at la prórroga única de `REQ-LAY-006` RB-3
 * @property \Illuminate\Support\Carbon|null $activated_at salida de `pending_down_payment`; no la reescribe la vuelta de la mora
 * @property \Illuminate\Support\Carbon|null $next_due_date un día del calendario de la organización
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $stock_released_at
 */
class Layaway extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        static::saving(static function (Layaway $layaway): void {
            $status = $layaway->getAttribute('status');

            if (! $layaway->exists) {
                if ($status !== null && $status !== LayawayStatus::PendingDownPayment) {
                    throw new LogicException(sprintf(
                        'Un apartado nace en «pending_down_payment» (REQ-LAY-001 RB-7) y se intentó crear en «%s». '
                        .'Los demás estados se alcanzan con LayawayStateMachine::transition().',
                        $status instanceof LayawayStatus ? $status->value : (string) $status,
                    ));
                }

                return;
            }

            if ($layaway->isDirty('status')) {
                throw new LogicException(
                    'El estado de un apartado solo lo escribe LayawayStateMachine::transition() (REQ-LAY-005 RB-2): '
                    .'la máquina valida la matriz, escribe la marca de tiempo, ajusta el cupo del plan y emite el '
                    .'evento en la misma transacción. Guardarlo a mano se salta las cuatro cosas.'
                );
            }
        });
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => LayawayStatus::class,
            'expires_at' => 'datetime',
            'extended_at' => 'datetime',
            'activated_at' => 'datetime',
            // Un día del calendario de la organización, no un instante
            // (`T-039`): `DATE` en la base y `date` aquí.
            'next_due_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'stock_released_at' => 'datetime',
        ];
    }
}
