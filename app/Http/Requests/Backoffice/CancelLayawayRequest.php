<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Layaway\StaffReason;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El motivo de una cancelación (`REQ-LAY-009` RB-2, SP-2).
 *
 * Quién puede cancelar lo decide `LayawayPolicy` dentro de la acción; aquí
 * solo la forma del motivo, con los mismos límites que `StaffReason`.
 */
final class CancelLayawayRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:'.StaffReason::MIN, 'max:'.StaffReason::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
