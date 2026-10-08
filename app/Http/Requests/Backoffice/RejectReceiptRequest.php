<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Layaway\StaffReason;
use App\Domain\Payment\RejectionReasonCode;
use App\Models\Receipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que manda el formulario de rechazo (`REQ-PAY-006` RB-1): un código del
 * catálogo y un texto de 10 a 500 caracteres que el cliente leerá tal cual.
 *
 * El código automático de la cancelación no se ofrece ni se acepta (SP-6). La
 * policy autoriza antes de validar; el comprobante de otra organización ni
 * llega (`404` por el binding, SP-7).
 */
final class RejectReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $receipt = $this->route('receipt');

        return $receipt instanceof Receipt && $this->user()?->can('review', $receipt) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', Rule::in(array_map(fn (RejectionReasonCode $code): string => $code->value, RejectionReasonCode::chosenByStaff()))],
            'reason' => ['required', 'string', 'min:'.StaffReason::MIN, 'max:'.StaffReason::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'motivo', 'reason' => 'explicación'];
    }

    public function code(): RejectionReasonCode
    {
        return RejectionReasonCode::from($this->string('code')->toString());
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
