<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Customer\CustomerContactData;
use App\Models\Customer;
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
            'full_name' => ['required', 'string', 'max:'.CustomerContactData::MAX_NAME_LENGTH],
            'phone' => ['required', 'string', new ValidVenezuelanPhone],
            'email' => ['nullable', 'string', 'max:'.CustomerContactData::MAX_EMAIL_LENGTH, 'email:filter'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function contact(Customer $customer): CustomerContactData
    {
        try {
            return CustomerContactData::fromInput(
                $customer->document_number,
                $this->string('full_name')->toString(),
                $this->string('phone')->toString(),
                $this->filled('email') ? $this->string('email')->toString() : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['full_name' => $exception->getMessage()]);
        }
    }
}
