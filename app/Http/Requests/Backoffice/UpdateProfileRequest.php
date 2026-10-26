<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::when($this->string('email')->toString() !== $user->email, [Rule::unique('users', 'email')]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Ese correo no parece válido. Revisa que tenga @ y un dominio.',
            'email.unique' => 'Ese correo ya lo usa otra persona en Apartar.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->string('name')),
            'email' => mb_strtolower(trim((string) $this->string('email'))),
        ]);
    }
}
