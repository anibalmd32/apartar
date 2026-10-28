<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Product\ProductData;
use App\Domain\Catalog\ProductDescription;
use App\Domain\Money\Money;
use App\Http\Requests\Backoffice\Concerns\ValidatesSpecifications;
use App\Rules\CoherentFinancingTerms;
use App\Rules\RealMimeType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ValidatesSpecifications;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],

            'sku' => ['required', 'string', 'max:64'],

            'description' => ['nullable', 'string', $this->descriptionFits()],

            'price' => ['required', 'numeric', 'decimal:0,2', new CoherentFinancingTerms],
            'compare_at_price' => ['nullable', 'numeric', 'decimal:0,2'],
            'down_payment' => ['required', 'numeric', 'decimal:0,2'],

            'installments_count' => ['required', 'integer'],
            'frequency_days' => ['required', 'integer'],
            'stock' => ['required', 'integer'],

            'category_id' => ['nullable', 'integer'],
            'category_name' => ['nullable', 'string', 'max:120'],

            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer'],
            'tag_names' => ['array'],
            'tag_names.*' => ['string', 'max:80'],
        ] + $this->specificationRules() + $this->imageRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.max' => sprintf('Un producto admite %d imágenes.', StoreProductImagesRequest::MAX_IMAGES),
            'images.*.max' => 'Cada imagen puede pesar hasta 8 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'sku' => 'SKU',
            'price' => 'precio',
            'compare_at_price' => 'precio tachado',
            'down_payment' => 'cuota inicial',
            'installments_count' => 'número de cuotas',
            'frequency_days' => 'días entre cuotas',
            'stock' => 'existencias',
            'slug' => 'enlace',
            'images' => 'imágenes',
        ] + $this->specificationAttributes();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->specificationChecks()];
    }

    public function toData(): ProductData
    {
        return $this->productData($this->requestedSlug());
    }

    protected function descriptionFits(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (mb_strlen($value) > ProductDescription::MAX_HTML_LENGTH) {
                $fail('La descripción trae demasiado formato. Quita parte de las negritas o listas, o acórtala.');

                return;
            }

            if (ProductDescription::length($value) > ProductDescription::MAX_PLAIN_LENGTH) {
                $fail('validation.max.string')->translate(['max' => (string) ProductDescription::MAX_PLAIN_LENGTH]);
            }
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function imageRules(): array
    {
        return [
            'images' => ['nullable', 'array', 'max:'.StoreProductImagesRequest::MAX_IMAGES],
            'images.*' => ['required', 'file', 'max:'.StoreProductImagesRequest::MAX_KILOBYTES, new RealMimeType],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    protected function uploadedImages(): array
    {
        $files = $this->file('images', []);

        return is_array($files) ? array_values($files) : [];
    }

    protected function requestedSlug(): ?string
    {
        return null;
    }

    protected function productData(?string $slug): ProductData
    {
        return new ProductData(
            name: trim((string) $this->string('name')),
            sku: trim((string) $this->string('sku')),
            slug: $slug,
            description: $this->nullableString('description'),
            price: $this->amount('price'),
            compareAtPrice: $this->nullableAmount('compare_at_price'),
            downPayment: $this->amount('down_payment'),
            installmentsCount: $this->integer('installments_count'),
            frequencyDays: $this->integer('frequency_days'),
            stock: $this->integer('stock'),
            categoryId: $this->input('category_id') === null ? null : $this->integer('category_id'),
            categoryName: $this->nullableString('category_name'),
            tagIds: $this->integerList('tag_ids'),
            tagNames: $this->stringList('tag_names'),
            specifications: $this->specifications(),
            images: $this->uploadedImages(),
        );
    }

    protected function amount(string $key): Money
    {
        return Money::fromDecimalString(trim((string) $this->string($key)));
    }

    protected function nullableAmount(string $key): ?Money
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : Money::fromDecimalString($value);
    }

    protected function nullableString(string $key): ?string
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<int>
     */
    protected function integerList(string $key): array
    {
        $values = $this->input($key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(intval(...), $values));
    }

    /**
     * @return list<string>
     */
    protected function stringList(string $key): array
    {
        $values = $this->input($key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(fn (mixed $value): string => trim((string) $value), $values));
    }
}
