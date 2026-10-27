<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Subscription\SubmitSubscriptionReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Models\Bank;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Rules\RealMimeType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class SubmitSubscriptionReceiptRequest extends FormRequest
{
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
            'file' => ['required', 'file', 'max:'.(ReceiptFile::maxSizeMb() * 1024), RealMimeType::receipts()],
        ];
    }

    /**
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
            'file.max' => sprintf('El comprobante pesa como mucho %d MB.', ReceiptFile::maxSizeMb()),
        ];
    }

    public function toData(SubscriptionInvoice $invoice): SubmitSubscriptionReceiptData
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return new SubmitSubscriptionReceiptData(
            invoice: $invoice,
            method: PaymentMethod::from((string) $this->string('method')),
            bank: $this->filled('bank') ? Bank::canonicalName((string) $this->string('bank')) : null,
            reference: $this->filled('reference') ? trim((string) $this->string('reference')) : null,
            paidAt: CarbonImmutable::parse((string) $this->string('paidAt')),
            declaredAmount: (string) $this->string('amount'),
            declaredCurrency: Currency::from((string) $this->string('currency')),
            file: $file,
        );
    }
}
