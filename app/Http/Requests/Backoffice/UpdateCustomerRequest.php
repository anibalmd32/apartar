<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Customer\CustomerContactData;
use App\Models\Customer;
use App\Rules\ValidDocumentNumber;
use App\Rules\ValidVenezuelanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('update', $customer) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:'.CustomerContactData::MAX_NAME_PART_LENGTH],
            'last_name' => ['nullable', 'string', 'max:'.CustomerContactData::MAX_NAME_PART_LENGTH],
            'document_number' => ['nullable', 'string', new ValidDocumentNumber],
            'phone' => ['nullable', 'string', new ValidVenezuelanPhone],
            'email' => ['nullable', 'string', 'max:'.CustomerContactData::MAX_EMAIL_LENGTH, 'email:filter'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function contact(Customer $customer): CustomerContactData
    {
        try {
            return CustomerContactData::fromParts(
                $this->filled('document_number') ? $this->string('document_number')->toString() : $customer->document_number,
                $this->string('first_name')->toString(),
                $this->string('last_name')->toString(),
                $this->string('phone')->toString(),
                $this->filled('email') ? $this->string('email')->toString() : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['first_name' => $exception->getMessage()]);
        }
    }
}
