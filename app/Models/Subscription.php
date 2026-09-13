<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La suscripción de una organización: su plan y su estado (`REQ-SUB-002`).
 *
 * Tabla del tenant, con `UNIQUE(organization_id)`: exactamente una por
 * organización y desde el primer instante (RB-1). Una organización nunca puede
 * quedarse sin suscripción —cancelar un plan pago **degrada a Free**
 * (RB-6)—, así que este modelo no contempla el caso de no existir.
 *
 * El modelo es anémico a propósito (`REQ-QAX-001` RB-3): los límites los
 * responde `Plan` y el impago lo gobierna `SubscriptionStatus`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end nulo en Free: sin fecha de fin
 * @property \Illuminate\Support\Carbon|null $grace_ends_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait
     * (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'status',
        'started_at',
        'current_period_start',
        'current_period_end',
        'grace_ends_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Plan contratado.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Indica si la suscripción no tiene fecha de fin.
     *
     * Es lo normal en Free, y es una decisión de producto: la capa gratuita
     * **es** la prueba, así que no hay reloj que se agote y nadie pierde
     * acceso a su inventario por terminar un trial (RB-3).
     */
    public function isOpenEnded(): bool
    {
        return $this->current_period_end === null;
    }
}
