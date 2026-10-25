<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Layaway\StaffReason;
use App\Domain\Payment\RejectionReasonCode;
use App\Models\Receipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
