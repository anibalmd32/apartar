<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReassignCategoryProductsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::notIn(array_filter([$this->currentId()])),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.not_in' => 'Elige una categoría distinta de la que quieres borrar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'categoría de destino',
        ];
    }

    public function targetId(): int
    {
        return $this->integer('category_id');
    }

    private function currentId(): ?int
    {
        $category = $this->route('category');

        return $category instanceof Category ? (int) $category->getKey() : null;
    }
}
