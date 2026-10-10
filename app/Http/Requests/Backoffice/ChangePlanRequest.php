<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Subscription\LimitEnforcer;
use App\Models\Plan;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El plan al que se cambia (`REQ-SUB-005`, `T-068`).
 *
 * Por código y solo entre los del escaparate (`REQ-SUB-001` RB-6): un plan
 * retirado sigue valiendo para quien ya lo tiene, así que también se admite el
 * **actual**, que es como se revierte una bajada programada (RB-7).
 *
 * `confirmed` es la confirmación explícita de una bajada que deja algo por
 * encima del techo (SP-2); si hace falta lo decide la acción, que es quien
 * calcula lo que excede.
 *
 * El permiso no se comprueba aquí: lo hace la policy dentro de la acción
 * (`SubscriptionPolicy::changePlan`, RB-1).
 */
final class ChangePlanRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $current = app(LimitEnforcer::class)->plan()->code;

        return [
            'plan' => ['required', 'string', 'max:50', Rule::exists('plans', 'code')->where(
                fn (Builder $query) => $query->where('is_public', true)->orWhere('code', $current),
            )],
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Sin traducciones de validación en el proyecto, el mensaje por defecto
     * sería la clave cruda (`validation.exists`): lo cazó el e2e de `T-068`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.required' => 'Elige un plan.',
            'plan.exists' => 'Ese plan no está a la venta.',
        ];
    }

    /**
     * El plan elegido. La acción vuelve a exigir que esté a la venta.
     */
    public function plan(): Plan
    {
        return Plan::query()->where('code', (string) $this->string('plan'))->firstOrFail();
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirmed');
    }
}
