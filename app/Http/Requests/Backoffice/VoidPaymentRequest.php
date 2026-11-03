<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Layaway\StaffReason;
use App\Models\Layaway;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

final class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');
        $layaway = $payment instanceof Payment ? Layaway::query()->find($payment->layaway_id) : null;

        return $layaway instanceof Layaway && $this->user()?->can('manage', $layaway) === true;
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
    public function messages(): array
    {
        return [
            'reason.required' => 'Escribe el motivo de la anulación.',
            'reason.min' => 'El motivo tiene que tener al menos '.StaffReason::MIN.' caracteres.',
            'reason.max' => 'El motivo puede tener como mucho '.StaffReason::MAX.' caracteres.',
        ];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
