<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Layaway\StaffReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La nueva fecha y el motivo de una reprogramación (`REQ-LAY-011` RB-4).
 *
 * Aquí solo la forma: una fecha `Y-m-d` y un motivo de 10 a 500 caracteres.
 * Si la fecha cae dentro de la ventana lo decide la acción **bajo el
 * bloqueo** del apartado, porque depende de las fechas de las vecinas y esas
 * pueden haberse movido entre el formulario y el envío (SP-5).
 */
final class RescheduleInstallmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:'.StaffReason::MIN, 'max:'.StaffReason::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['due_date' => 'nueva fecha', 'reason' => 'motivo'];
    }

    /**
     * El día elegido, sin zona: la acción lo lee en el calendario del negocio.
     */
    public function dueDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('due_date')->toString())
            ?: throw new \LogicException('La regla `date_format` ya validó la fecha.');
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
