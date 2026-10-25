<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Catalog\CatalogData;
use App\Support\UniqueCatalogSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class UpsertCatalogRequest extends FormRequest
{
    public const MAX_COVER_KILOBYTES = 4096;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],

            'slug' => ['nullable', 'string', 'max:'.UniqueCatalogSlug::MAX_LENGTH],

            'description' => ['nullable', 'string', 'max:2000'],

            'cover' => ['sometimes', 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.self::MAX_COVER_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'slug' => 'enlace',
            'description' => 'descripción',
            'cover' => 'portada',
        ];
    }

    public function toData(): CatalogData
    {
        $slug = trim((string) $this->string('slug'));
        $description = trim((string) $this->string('description'));
        $cover = $this->file('cover');

        return new CatalogData(
            name: trim((string) $this->string('name')),
            slug: $slug === '' ? null : $slug,
            description: $description === '' ? null : $description,
            cover: $cover instanceof UploadedFile ? $cover : null,
        );
    }
}
