<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

final class SyncCatalogProductsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'products' => ['present', 'array'],
            'products.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['products.*.integer' => 'La lista de productos llegó mal formada.'];
    }

    /**
     * @return list<int>
     */
    public function orderedIds(): array
    {
        $ids = $this->input('products', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(intval(...), $ids));
    }
}
