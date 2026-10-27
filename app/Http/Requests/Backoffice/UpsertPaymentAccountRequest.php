<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Organization\UpsertPaymentAccountData;
use App\Domain\Payment\PaymentMethod;
use App\Models\Bank;
use App\Rules\ValidPaymentIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpsertPaymentAccountRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', new Enum(PaymentMethod::class)],
            'bank' => ['nullable', 'string', 'max:100'],

            'holder_name' => ['required', 'string', 'max:255'],
            'holder_document' => ['required', 'string', 'max:32'],

            'identifier' => $this->identifierRules(),

            'instructions' => ['nullable', 'string', 'max:300'],

            'is_active' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $method = $this->paymentMethod();

        return [
            'holder_name' => 'titular',
            'holder_document' => 'cédula o RIF del titular',
            'identifier' => $method instanceof PaymentMethod
                ? mb_strtolower($method->identifierLabel())
                : 'identificador',
        ];
    }

    public function toData(): UpsertPaymentAccountData
    {
        return new UpsertPaymentAccountData(
            method: PaymentMethod::from((string) $this->string('method')),
            bank: Bank::canonicalName($this->nullableString('bank')),
            holderName: trim((string) $this->string('holder_name')),
            holderDocument: trim((string) $this->string('holder_document')),
            identifier: trim((string) $this->string('identifier')),
            instructions: $this->nullableString('instructions'),
            isActive: $this->boolean('is_active'),
            position: $this->input('position') === null ? null : $this->integer('position'),
        );
    }

    /**
     * @return list<mixed>
     */
    private function identifierRules(): array
    {
        $rules = ['required', 'string', 'max:255'];

        $method = $this->paymentMethod();

        if ($method instanceof PaymentMethod) {
            $rules[] = new ValidPaymentIdentifier($method);
        }

        return $rules;
    }

    private function paymentMethod(): ?PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->string('method'));
    }

    private function nullableString(string $key): ?string
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : $value;
    }
}
