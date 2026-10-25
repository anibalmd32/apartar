<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

final class ReorderCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'categories' => ['present', 'array'],
            'categories.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function orderedIds(): array
    {
        $ids = $this->input('categories', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(intval(...), $ids));
    }
}
