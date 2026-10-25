<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Actions\Organization\RegisterOrganizationData;
use App\Support\ReservedSlugs;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255'],

            'organization_slug' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $this->notReserved(),
            ],

            'owner_name' => ['required', 'string', 'max:255'],

            'owner_email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],

            'owner_password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->uncompromised()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_name' => 'nombre del negocio',
            'organization_slug' => 'enlace',
            'owner_name' => 'nombre',
            'owner_email' => 'correo',
            'owner_password' => 'contraseña',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organization_slug.regex' => 'El enlace solo admite minúsculas, números y guiones.',
            'owner_email.unique' => 'Ese correo ya tiene una cuenta de equipo. Inicia sesión o usa otro.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->toData()->preferredSlug() !== '') {
                    return;
                }

                $validator->errors()->add(
                    'organization_slug',
                    'Ese nombre no produce un enlace válido: escribe uno con letras o números.',
                );
            },
        ];
    }

    public function toData(): RegisterOrganizationData
    {
        return new RegisterOrganizationData(
            organizationName: (string) $this->string('organization_name'),
            organizationSlug: Str::lower(trim((string) $this->string('organization_slug'))),
            ownerName: (string) $this->string('owner_name'),
            ownerEmail: (string) $this->string('owner_email'),
            ownerPassword: (string) $this->string('owner_password'),
        );
    }

    private function notReserved(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && ReservedSlugs::contains($value)) {
                $fail('Ese enlace está reservado por la plataforma: elige otro.');
            }
        };
    }

    protected function prepareForValidation(): void
    {
        $slug = Str::lower(trim((string) $this->string('organization_slug')));

        $this->merge(['organization_slug' => $slug]);
    }
}
