<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\Category;
use App\Rules\UniqueTaxonomyName;
use Illuminate\Foundation\Http\FormRequest;

final class UpsertCategoryRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', new UniqueTaxonomyName(Category::class, $this->editedId())],

            'slug' => ['nullable', 'string', 'max:120'],
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
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function slug(): ?string
    {
        $slug = trim((string) $this->string('slug'));

        return $slug === '' ? null : $slug;
    }

    private function editedId(): ?int
    {
        $category = $this->route('category');

        return $category instanceof Category ? (int) $category->getKey() : null;
    }
}
