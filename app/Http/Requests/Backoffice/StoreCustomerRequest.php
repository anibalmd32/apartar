<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Customer\CustomerContactData;
use App\Domain\Customer\TermsConsent;
use App\Models\Customer;
use App\Rules\ValidDocumentNumber;
use App\Rules\ValidVenezuelanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:'.CustomerContactData::MAX_NAME_LENGTH],
            'document_number' => ['required', 'string', new ValidDocumentNumber],
            'phone' => ['required', 'string', new ValidVenezuelanPhone],
            'email' => ['nullable', 'string', 'max:'.CustomerContactData::MAX_EMAIL_LENGTH, 'email:filter'],
            'terms_accepted' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['terms_accepted.accepted' => 'Confirma que el cliente aceptó las condiciones del apartado.'];
    }

    /**
     * @throws ValidationException
     */
    public function contact(): CustomerContactData
    {
        try {
            return CustomerContactData::fromInput(
                $this->string('document_number')->toString(),
                $this->string('full_name')->toString(),
                $this->string('phone')->toString(),
                $this->filled('email') ? $this->string('email')->toString() : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['full_name' => $exception->getMessage()]);
        }
    }

    public function consent(): TermsConsent
    {
        return TermsConsent::acceptedNowFrom((string) $this->ip());
    }
}
