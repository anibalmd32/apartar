<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

final class SyncImageOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'images' => ['present', 'array'],
            'images.*.id' => ['required', 'integer'],

            'images.*.alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'images' => 'imágenes',
            'images.*.alt' => 'texto alternativo',
        ];
    }

    /**
     * @return list<array{id: int, alt?: string|null}>
     */
    public function images(): array
    {
        $images = $this->input('images', []);

        if (! is_array($images)) {
            return [];
        }

        $ordered = [];

        foreach ($images as $image) {
            if (! is_array($image) || ! isset($image['id']) || ! is_numeric($image['id'])) {
                continue;
            }

            $entry = ['id' => (int) $image['id']];

            if (array_key_exists('alt', $image)) {
                $entry['alt'] = is_scalar($image['alt']) ? (string) $image['alt'] : null;
            }

            $ordered[] = $entry;
        }

        return $ordered;
    }
}
