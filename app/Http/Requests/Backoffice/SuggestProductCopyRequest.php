<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Catalog\CopywritingMode;
use App\Domain\Catalog\ProductDescription;
use App\Models\Product;
use App\Rules\RealMimeType;
use App\Support\Ai\CopywriterImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SuggestProductCopyRequest extends FormRequest
{
    private ?Product $resolvedProduct = null;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(CopywritingMode::class)],
            'name' => ['nullable', 'string', 'max:200'],
            'category_name' => ['nullable', 'string', 'max:120'],
            'description' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('mode') === CopywritingMode::Improve->value),
                'string',
                'max:'.ProductDescription::MAX_HTML_LENGTH,
            ],
            'product_id' => ['nullable', 'integer'],
            'images' => ['nullable', 'array', 'max:'.CopywriterImages::MAX_IMAGES],
            'images.*' => ['required', 'file', 'max:'.StoreProductImagesRequest::MAX_KILOBYTES, new RealMimeType],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.required' => 'Escribe primero una descripción para que la IA la mejore.',
            'images.max' => sprintf('La IA mira como máximo %d fotos.', CopywriterImages::MAX_IMAGES),
            'images.*.max' => 'Cada imagen puede pesar hasta 8 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'description' => 'descripción', 'images' => 'imágenes'];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->mode() !== CopywritingMode::Suggest) {
                return;
            }

            if ($this->productName() === '' && $this->images() === [] && ! $this->product()?->hasImages()) {
                $validator->errors()->add('name', 'Escribe el nombre o agrega una foto para que la IA tenga de dónde partir.');
            }
        }];
    }

    public function mode(): CopywritingMode
    {
        return CopywritingMode::from((string) $this->string('mode'));
    }

    public function productName(): string
    {
        return trim((string) $this->string('name'));
    }

    public function categoryName(): ?string
    {
        $value = trim((string) $this->string('category_name'));

        return $value === '' ? null : $value;
    }

    public function description(): ?string
    {
        $value = trim((string) $this->string('description'));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<UploadedFile>
     */
    public function images(): array
    {
        $files = $this->file('images', []);

        return is_array($files) ? array_values($files) : [];
    }

    public function product(): ?Product
    {
        if ($this->input('product_id') === null) {
            return null;
        }

        return $this->resolvedProduct ??= Product::query()->find($this->integer('product_id')) ?? abort(404);
    }
}
