<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

final class PublishProductsRequest extends FormRequest
{
    public const MAX_IDS = 100;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Elige al menos un producto para publicar.',
            'ids.min' => 'Elige al menos un producto para publicar.',
            'ids.max' => sprintf('Puedes publicar hasta %d productos de una vez.', self::MAX_IDS),
            'ids.*.integer' => 'Uno de los productos elegidos no se reconoce.',
            'ids.*.min' => 'Uno de los productos elegidos no se reconoce.',
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('ids');

        return array_map(intval(...), $ids);
    }
}
