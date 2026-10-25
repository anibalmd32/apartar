<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\Product;
use App\Rules\RealMimeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class StoreProductImagesRequest extends FormRequest
{
    public const MAX_IMAGES = 10;

    public const MAX_KILOBYTES = 8 * 1024;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.$this->remainingSlots()],

            'images.*' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                new RealMimeType,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.max' => sprintf(
                'Un producto admite %d imágenes y a este le quedan %d.',
                self::MAX_IMAGES,
                $this->remainingSlots(),
            ),
            'images.*.max' => 'Cada imagen puede pesar hasta 8 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['images' => 'imágenes'];
    }

    /**
     * @return list<UploadedFile>
     */
    public function images(): array
    {
        $files = $this->file('images', []);

        return is_array($files) ? array_values($files) : [];
    }

    private function remainingSlots(): int
    {
        $product = $this->route('product');

        $current = $product instanceof Product ? $product->getMedia(Product::IMAGES)->count() : 0;

        return max(0, self::MAX_IMAGES - $current);
    }
}
