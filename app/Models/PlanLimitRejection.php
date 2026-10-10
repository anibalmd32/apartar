<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Un apartado que el límite del plan rechazó (`REQ-SUB-004` SP-2,
 * `REQ-BOF-044` RB-5, `T-067`): la evidencia de venta perdida del aviso
 * `plan_limit_blocking_sales`. La escribe `CreateLayawayAction` **después** de
 * deshacer su transacción —dentro, se desharía con ella— y no se edita.
 *
 * @property int $id
 * @property int $organization_id
 * @property UsageMetric $metric
 * @property \Illuminate\Support\Carbon $occurred_at
 */
class PlanLimitRejection extends Model
{
    use BelongsToOrganization;

    /**
     * `organization_id` queda fuera: lo rellena el trait.
     *
     * @var list<string>
     */
    protected $fillable = ['metric', 'occurred_at'];

    /** Una anotación por métrica y hora, como mucho: es evidencia, no un contador de visitas. */
    public const THROTTLE_MINUTES = 60;

    /**
     * Anota la venta perdida de la organización activa, salvo que ya haya una
     * de esa métrica en la última hora.
     *
     * Dos caminos llegan aquí: el intento que `CreateLayawayAction` rechaza y
     * —el de todos los días— la ficha pública que **ya no ofrece** apartar
     * porque el cupo está lleno (`LimitEnforcer::offersLayaways()`). Sin el
     * segundo, el aviso del panel casi no saldría nunca: con el formulario
     * apagado, nadie llega a enviar el apartado que se rechazaría. El freno
     * evita una fila por cada visita sin perder lo que el aviso necesita
     * saber, que es si pasó esta semana.
     */
    public static function record(UsageMetric $metric): void
    {
        $recent = self::query()
            ->where('metric', $metric)
            ->where('occurred_at', '>=', now()->subMinutes(self::THROTTLE_MINUTES))
            ->exists();

        if ($recent) {
            return;
        }

        self::query()->create(['metric' => $metric, 'occurred_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => UsageMetric::class,
            'occurred_at' => 'datetime',
        ];
    }
}
