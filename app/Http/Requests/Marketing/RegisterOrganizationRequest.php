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

/**
 * Validación del registro (`REQ-ORG-001`).
 *
 * Lo que **no** valida es tan deliberado como lo que valida:
 *
 *  - **la unicidad del slug no se comprueba aquí.** Entre un `unique:` y el
 *    `INSERT` cabe otro registro, así que la comprobación de verdad está en
 *    la acción, con el índice de la base detrás. Ponerla también aquí daría un
 *    mensaje sin la variante libre que pide SP-1, que es justo lo único útil
 *    que se le puede decir a quien la sufre;
 *  - **el correo no se cruza con `accounts`.** Un correo que ya existe como
 *    cuenta de cliente final se acepta (SP-3): son dos identidades distintas
 *    (`D-8`) y la misma persona puede ser dueña de un negocio y clienta de
 *    otro. Lo que sí se rechaza es un correo de equipo ya usado (SP-2), porque
 *    `users.email` es único en toda la plataforma para que el login no tenga
 *    que preguntar por la organización.
 */
class RegisterOrganizationRequest extends FormRequest
{
    /**
     * El registro es abierto, sin invitación ni lista de espera (RB-8). Quien
     * llega aquí no está autenticado y no tiene nada que autorizar; el abuso
     * lo acota el rate limit de la ruta.
     */
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

            // Opcional: si no viene, se deriva del nombre. El formato es el de
            // un segmento de URL, que es lo que acaba siendo.
            'organization_slug' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $this->notReserved(),
            ],

            'owner_name' => ['required', 'string', 'max:255'],

            'owner_email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],

            // RB-4, tal cual: doce caracteres, mayúsculas y minúsculas,
            // números y **no comprometida**. Lo último es lo que de verdad
            // protege una cuenta que gobierna la cobranza de un negocio: una
            // contraseña larga que ya está en una filtración no es fuerte.
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
     * SP-7 · un nombre que no produce enlace exige escribirlo a mano.
     *
     * Pasa con un nombre de solo emojis o de solo signos. Se comprueba aquí y
     * no en la acción porque el remedio es escribir algo en un campo del
     * formulario, y eso es un error de validación con su mensaje al lado del
     * campo.
     *
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

    /**
     * Datos ya normalizados para la acción.
     *
     * El teléfono no se pide: no hay columna donde guardarlo ([05 §4]), y
     * recoger un dato que se pierde es peor que no recogerlo.
     */
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

    /**
     * Rechaza los slugs que la plataforma se reserva ([05 §7.2]).
     *
     * Va como regla —y no solo en la acción— porque cuando el dueño lo
     * escribió a mano puede elegir otro ahí mismo: es una corrección junto al
     * campo, no un fallo del sistema. El slug **derivado del nombre** sí llega
     * a la acción, que lo resuelve con una variante libre.
     */
    private function notReserved(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && ReservedSlugs::contains($value)) {
                $fail('Ese enlace está reservado por la plataforma: elige otro.');
            }
        };
    }

    /**
     * El enlace se normaliza antes de validarlo: `Mi-Tienda` y ` mi-tienda `
     * son el mismo segmento de URL, y rechazar el primero por la mayúscula
     * sería un acertijo.
     */
    protected function prepareForValidation(): void
    {
        $slug = Str::lower(trim((string) $this->string('organization_slug')));

        $this->merge(['organization_slug' => $slug]);
    }
}
