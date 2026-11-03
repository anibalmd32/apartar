<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Payment\ManualPaymentData;
use App\Http\Requests\Backoffice\Concerns\ValidatesManualPayment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Support\DecimalInput;
use App\Support\LayawayCode;
use App\Support\ManualPaymentDates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class CorrectPaymentRequest extends FormRequest
{
    use ValidatesManualPayment;

    public function authorize(): bool
    {
        $payment = $this->route('payment');

        if (! $payment instanceof Payment) {
            return false;
        }

        $layaway = Layaway::query()->find($payment->layaway_id);

        return $layaway instanceof Layaway && $this->user()?->can('manage', $layaway) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(DecimalInput::normalizeKeys($this->all(), ['amount', 'rate']));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        [$min, $max] = ManualPaymentDates::bounds($this->organizationTimezone());

        return $this->paymentRules($min, $max) + ['destino' => ['nullable', 'string', 'max:20']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->paymentAttributes() + ['destino' => 'apartado de destino', 'note' => 'motivo'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->paymentMessages();
    }

    /**
     * @throws ValidationException
     */
    public function toData(): ManualPaymentData
    {
        return $this->paymentData();
    }

    /**
     * @throws ValidationException
     */
    public function target(Payment $payment): Layaway
    {
        $code = trim($this->string('destino')->toString());

        if ($code === '') {
            return Layaway::query()->findOrFail($payment->layaway_id);
        }

        $layaway = Layaway::query()->where('code', LayawayCode::normalize($code))->first();

        if (! $layaway instanceof Layaway) {
            throw ValidationException::withMessages(['destino' => 'No hay un apartado con ese código en tu negocio.']);
        }

        if (! $layaway->status->acceptsPayments()) {
            throw ValidationException::withMessages(['destino' => sprintf('El apartado %s está %s y no recibe pagos.', $layaway->code, mb_strtolower($layaway->status->label()))]);
        }

        return $layaway;
    }
}
