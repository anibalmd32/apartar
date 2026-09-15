<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Organization\UpdateOrganizationData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * La identidad del negocio que llega del formulario (`REQ-ORG-006`).
 *
 * **La unicidad del slug no se valida aquí**, por lo mismo que en el registro
 * (`T-010`): entre un `unique:` y el `UPDATE` cabe otra organización, así que
 * la comprobación vive en la acción con el índice detrás. Lo reservado sí se
 * valida en la acción y no aquí porque `ReservedSlugs` y la colisión dan el
 * mismo tipo de respuesta —una variante libre— y tenerlas juntas evita dos
 * mensajes distintos para el mismo problema.
 *
 * El logo se valida **aquí y no en la acción**, que es al revés de lo
 * anterior: el tamaño y el tipo de un archivo subido son exactamente lo que un
 * `FormRequest` existe para rechazar antes de que nadie lo mueva a disco.
 */
final class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Tamaño máximo del logo, en kilobytes.
     *
     * Dos megas es de sobra para un logo y poco para una foto de catálogo
     * subida por error, que es el caso que de verdad ocurre. El almacenamiento
     * es además una métrica de plan (`REQ-SUB-003`), así que un tope generoso
     * se lo come el negocio de su cuota.
     */
    private const MAX_LOGO_KILOBYTES = 2048;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'document' => ['present', 'nullable', 'string', 'max:32'],
            'phone' => ['present', 'nullable', 'string', 'max:32'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'brand_color' => ['present', 'nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'storefront_enabled' => ['required', 'boolean'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:'.self::MAX_LOGO_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'El enlace solo admite minúsculas, números y guiones: así se ve y así se comparte.',
            'brand_color.regex' => 'El color va en hexadecimal, como #1f6feb.',
            'timezone.timezone' => 'Esa zona horaria no existe.',
        ];
    }

    /**
     * El enlace se normaliza antes de validar: quien escribe «Mi Negocio»
     * quiere `mi-negocio`, no un error de formato.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) $this->string('slug')),
        ]);
    }

    public function toData(): UpdateOrganizationData
    {
        $logo = $this->file('logo');

        return new UpdateOrganizationData(
            name: trim((string) $this->string('name')),
            slug: (string) $this->string('slug'),
            document: $this->filledStringOrNull('document'),
            phone: $this->filledStringOrNull('phone'),
            timezone: (string) $this->string('timezone'),
            brandColor: $this->filledStringOrNull('brand_color'),
            storefrontEnabled: $this->boolean('storefront_enabled'),
            logo: $logo instanceof UploadedFile ? $logo : null,
        );
    }

    /**
     * Cadena recortada, o `null` si venía vacía.
     *
     * Un campo opcional que llega como cadena vacía no es «cadena vacía»: es
     * que no lo rellenaron. Guardarlo como `''` obligaría a que cada lectura
     * futura tratara dos valores como el mismo ausente.
     */
    private function filledStringOrNull(string $key): ?string
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : $value;
    }
}
