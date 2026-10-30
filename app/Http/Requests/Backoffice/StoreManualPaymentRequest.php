<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Payment\ManualPaymentData;
use App\Http\Requests\Backoffice\Concerns\ValidatesManualPayment;
use App\Http\Requests\Portal\StoreReceiptRequest;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class StoreManualPaymentRequest extends FormRequest
{
    use ValidatesManualPayment;

    public function authorize(): bool
    {
        $layaway = $this->route('layaway');

        return $layaway instanceof Layaway && $this->user()?->can('manage', $layaway) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        [$min, $max] = $this->paidAtBounds();

        return $this->paymentRules($min, $max);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->paymentAttributes();
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
     * @return array{0: string, 1: string}
     */
    private function paidAtBounds(): array
    {
        $layaway = $this->route('layaway');

        return $layaway instanceof Layaway
            ? StoreReceiptRequest::boundsFor($layaway, $this->organizationTimezone())
            : [CarbonImmutable::now()->toDateString(), CarbonImmutable::now()->toDateString()];
    }
}
