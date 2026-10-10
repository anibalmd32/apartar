<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Subscription\SubmitSubscriptionReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Rules\RealMimeType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * El comprobante con el que el negocio paga una factura de su suscripción
 * (`REQ-SUB-006` RB-2, `T-069`): las mismas reglas de archivo que el de un
 * cliente —tipo real, tamaño máximo— porque es el mismo mecanismo.
 *
 * Los duplicados los decide la acción con `DuplicateReceipts`, y el
 * controlador devuelve el error en su campo.
 */
final class SubmitSubscriptionReceiptRequest extends FormRequest
{
    /**
     * Quien no gobierna la facturación recibe `403` **antes** de validar y de
     * subir nada (revisión de `T-069`); la acción lo vuelve a comprobar.
     */
    public function authorize(): bool
    {
        return Gate::allows('uploadPaymentProof', Subscription::query()->firstOrFail());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $method = PaymentMethod::tryFrom((string) $this->string('method'));
        $requiresReference = $method?->requiresReference() ?? false;

        return [
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'bank' => [Rule::requiredIf($requiresReference), 'nullable', 'string', 'max:'.SubmitSubscriptionReceiptData::TEXT_MAX],
            'reference' => [Rule::requiredIf($requiresReference), 'nullable', 'string', 'max:'.SubmitSubscriptionReceiptData::TEXT_MAX],
            'paidAt' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now()->addDay()->toDateString()],
            'amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'file' => ['required', 'file', 'max:'.(ReceiptFile::MAX_SIZE_MB * 1024), RealMimeType::receipts()],
        ];
    }

    /**
     * El proyecto no tiene traducciones de validación: sin esto, el error
     * sería la clave cruda (`validation.required`).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.required' => 'Elige cómo pagaste.',
            'bank.required' => 'Indica el banco.',
            'reference.required' => 'Indica la referencia del pago.',
            'paidAt.required' => 'Indica el día del pago.',
            'paidAt.before_or_equal' => 'El día del pago no puede ser futuro.',
            'amount.required' => 'Indica el importe que pagaste.',
            'amount.regex' => 'El importe es un número con hasta dos decimales.',
            'currency.required' => 'Indica la moneda.',
            'file.required' => 'Adjunta el comprobante.',
            'file.max' => sprintf('El comprobante pesa como mucho %d MB.', ReceiptFile::MAX_SIZE_MB),
        ];
    }

    public function toData(SubscriptionInvoice $invoice): SubmitSubscriptionReceiptData
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return new SubmitSubscriptionReceiptData(
            invoice: $invoice,
            method: PaymentMethod::from((string) $this->string('method')),
            bank: $this->filled('bank') ? trim((string) $this->string('bank')) : null,
            reference: $this->filled('reference') ? trim((string) $this->string('reference')) : null,
            paidAt: CarbonImmutable::parse((string) $this->string('paidAt')),
            declaredAmount: (string) $this->string('amount'),
            declaredCurrency: Currency::from((string) $this->string('currency')),
            file: $file,
        );
    }
}
