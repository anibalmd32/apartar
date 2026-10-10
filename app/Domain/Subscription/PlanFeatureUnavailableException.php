<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use RuntimeException;

/**
 * El plan contratado no incluye esa función (`REQ-SUB-004` RB-7 y SP-3).
 *
 * A diferencia de un límite, aquí no hay nada consumido: la función se tiene o
 * no se tiene. Y la diferencia con `PlanLimitReachedException` está en la
 * respuesta: esto es **`403`**, porque la operación no está disponible para
 * esta organización; un límite es `422`, porque la operación existe y lo que
 * falta es sitio.
 *
 * `403` y no `404` (SP-3): ocultar que la función existe le impediría a quien
 * la pide entender que basta con subir de plan. RB-7 lo dice desde el otro
 * lado —el control se muestra deshabilitado con el motivo, no oculto—: hay que
 * poder **ver** lo que uno se está perdiendo.
 */
final class PlanFeatureUnavailableException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-3).
     */
    public const STATUS = 403;

    private function __construct(
        public readonly PlanFeature $feature,
        /** `null` si ningún plan público la incluye. */
        public readonly ?Plan $minimumPlan,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  Plan  $current  plan contratado
     * @param  Plan|null  $minimum  el más barato del escaparate que sí la incluye
     */
    public static function of(PlanFeature $feature, Plan $current, ?Plan $minimum): self
    {
        // El nombre de la función, no su clave: «excel_export» no se le dice a
        // nadie (`PlanFeature::label()`).
        $label = $feature->label();
        $message = sprintf('Tu plan %s no incluye: %s.', $current->name, mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1));

        $message .= $minimum instanceof Plan
            ? sprintf(' %s sí la incluye.', $minimum->name)
            : ' Ningún plan la incluye todavía.';

        return new self($feature, $minimum, $message);
    }

    /**
     * Cuerpo con el que la interfaz construye el aviso (RB-5).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature->value,
            'minimumPlan' => $this->minimumPlan?->code,
            'minimumPlanName' => $this->minimumPlan?->name,
        ];
    }
}
