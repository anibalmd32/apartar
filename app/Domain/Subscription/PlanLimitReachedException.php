<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use RuntimeException;

/**
 * La organización agotó el techo de una métrica de su plan
 * (`REQ-SUB-004` RB-5, `REQ-ORG-004` SP-1).
 *
 * **Lleva dentro todo lo que la interfaz necesita para redactar el mensaje**:
 * la métrica, lo consumido, el techo y **el plan mínimo que lo resuelve**. Sin
 * el último, la pantalla solo puede decir «no puedes», y eso deja a la persona
 * exactamente donde estaba. Con él dice «tu plan permite 1 usuario; Pro
 * permite 5», que es accionable, y lo dice **sin lógica propia**, que es lo que
 * exige RB-1.
 *
 * El plan mínimo se resuelve **por sus límites y nunca por su código**
 * (`REQ-SUB-001` RB-1): se busca el primero del escaparate que admita lo que
 * se pedía. El día que la matriz cambie, el mensaje cambia con ella sin tocar
 * una línea.
 *
 * Es `422` y no `403`: la petición está bien formada y quien la hace tiene
 * permiso de sobra —suele ser el `owner`—. Lo que falta es sitio, y eso se
 * arregla con dinero, no con un rol.
 */
final class PlanLimitReachedException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (`REQ-ORG-004` SP-1).
     */
    public const STATUS = 422;

    private function __construct(
        public readonly UsageMetric $metric,
        public readonly int $used,
        public readonly int $limit,
        /** `null` si ningún plan público lo resuelve; entonces no hay nada que ofrecer. */
        public readonly ?Plan $minimumPlan,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  Plan  $current  plan contratado, para nombrar lo que hoy permite
     * @param  Plan|null  $minimum  el más barato que admitiría `$used + $amount`
     */
    public static function of(
        UsageMetric $metric,
        int $used,
        int $limit,
        int $amount,
        Plan $current,
        ?Plan $minimum,
    ): self {
        return new self($metric, $used, $limit, $minimum, self::messageFor($metric, $limit, $amount, $current, $minimum));
    }

    /**
     * Lo que se le dice al negocio.
     *
     * Dos frases: qué permite su plan y cuál lo desbloquea. La segunda falta
     * cuando ya está en el mejor, y entonces no se inventa un camino que no
     * existe —ofrecer «mejora tu plan» a quien tiene el mayor es la forma más
     * rápida de perder la confianza de quien más paga—.
     */
    private static function messageFor(
        UsageMetric $metric,
        int $limit,
        int $amount,
        Plan $current,
        ?Plan $minimum,
    ): string {
        $reached = sprintf(
            'Tu plan %s permite %d %s%s.',
            $current->name,
            $limit,
            $metric->label($limit),
            $amount > 1 ? sprintf(', y estás pidiendo %d más', $amount) : '',
        );

        if (! $minimum instanceof Plan) {
            return $reached.' No hay ningún plan con un techo mayor.';
        }

        $ceiling = $minimum->limitFor($metric);

        return $reached.sprintf(
            ' %s permite %s.',
            $minimum->name,
            $ceiling === null ? 'sin límite' : $ceiling.' '.$metric->label($ceiling),
        );
    }

    /**
     * Cuerpo con el que la interfaz construye el aviso (RB-5).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'used' => $this->used,
            'limit' => $this->limit,
            'minimumPlan' => $this->minimumPlan?->code,
            'minimumPlanName' => $this->minimumPlan?->name,
        ];
    }
}
