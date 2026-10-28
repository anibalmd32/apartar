<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Product\ProductData;

final class UpdateProductRequest extends StoreProductRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'slug' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function toData(): ProductData
    {
        return $this->productData($this->requestedSlug());
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function imageRules(): array
    {
        return [];
    }

    /**
     * @return list<\Illuminate\Http\UploadedFile>
     */
    protected function uploadedImages(): array
    {
        return [];
    }

    protected function requestedSlug(): ?string
    {
        return $this->nullableString('slug');
    }
}
