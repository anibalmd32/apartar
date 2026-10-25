<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Organization\UpdateOrganizationData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class UpdateOrganizationRequest extends FormRequest
{
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
            'tagline' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'address' => ['sometimes', 'nullable', 'string', 'max:160'],
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
            profile: array_map(
                fn (string $field): ?string => $this->filledStringOrNull($field),
                array_filter(['tagline' => 'tagline', 'description' => 'description', 'address' => 'address'], fn (string $field): bool => $this->has($field)),
            ),
        );
    }

    private function filledStringOrNull(string $key): ?string
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : $value;
    }
}
