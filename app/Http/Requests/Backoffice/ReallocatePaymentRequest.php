<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\Layaway;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

final class ReallocatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');
        $layaway = $payment instanceof Payment ? Layaway::query()->find($payment->layaway_id) : null;

        return $layaway instanceof Layaway && $this->user()?->can('manage', $layaway) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1', 'max:120'],
            'allocations.*.installment' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['nullable', 'string', 'regex:'.ApproveReceiptRequest::AMOUNT_PATTERN],
            'reason' => ['required', 'string', 'min:'.StaffReason::MIN, 'max:'.StaffReason::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'allocations' => 'reparto',
            'allocations.*.installment' => 'cuota',
            'allocations.*.amount' => 'importe de la cuota',
            'reason' => 'motivo',
        ];
    }

    /**
     * @return list<array{installmentId: int, cents: int}>
     */
    public function allocations(): array
    {
        /** @var list<array{installment: int|string, amount: ?string}> $rows */
        $rows = $this->validated('allocations');
        $plan = [];

        foreach ($rows as $row) {
            $cents = $row['amount'] === null ? 0 : Money::fromDecimalString($row['amount'], Currency::USD)->cents;

            if ($cents > 0) {
                $plan[] = ['installmentId' => (int) $row['installment'], 'cents' => $cents];
            }
        }

        return $plan;
    }
}
