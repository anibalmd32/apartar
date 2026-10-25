<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Notification\ManualPushData;
use App\Domain\Tenancy\OrganizationCapability;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendManualPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->role->can(OrganizationCapability::Customers);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.ManualPushData::TITLE_MAX],
            'body' => ['required', 'string', 'max:'.ManualPushData::BODY_MAX],
            'url' => ['nullable', 'url', 'max:500'],
            'customers' => ['nullable', 'array', 'max:500'],
            'customers.*' => ['integer'],
            'segment' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('customers') === $this->has('segment')) {
                $validator->errors()->add('customers', 'Elige clientes o un segmento de la cobranza, uno de los dos.');
            }
        });
    }

    public function toData(): ManualPushData
    {
        $customers = $this->input('customers');
        $segment = $this->input('segment');

        return new ManualPushData(
            title: trim((string) $this->string('title')),
            body: trim((string) $this->string('body')),
            url: $this->filled('url') ? (string) $this->string('url') : null,
            customerIds: is_array($customers) ? array_values(array_map('intval', $customers)) : null,
            segment: is_array($segment) ? $segment : null,
        );
    }
}
