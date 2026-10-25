<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Product\ProductData;
use App\Domain\Money\Money;
use App\Http\Requests\Backoffice\Concerns\ValidatesSpecifications;
use App\Rules\CoherentFinancingTerms;
use Illuminate\Foundation\Http\FormRequest;

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

            'description' => ['nullable', 'string', 'max:5000'],

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
        ] + $this->specificationRules();
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
        ] + $this->specificationAttributes();
    }

    public function toData(): ProductData
    {
        return $this->productData($this->requestedSlug());
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
