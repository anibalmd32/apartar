<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Money\Money;

final readonly class ProductData
{
    /**
     * @param  list<int>  $tagIds
     * @param  list<string>  $tagNames
     * @param  list<SpecificationInput>|null  $specifications
     */
    public function __construct(
        public string $name,
        public string $sku,
        public ?string $slug,
        public ?string $description,
        public Money $price,
        public ?Money $compareAtPrice,
        public Money $downPayment,
        public int $installmentsCount,
        public int $frequencyDays,
        public int $stock,
        public ?int $categoryId = null,
        public ?string $categoryName = null,
        public array $tagIds = [],
        public array $tagNames = [],
        public ?array $specifications = null,
    ) {}

    public function financingPlan(): ?FinancingPlan
    {
        return FinancingPlan::tryFrom(
            $this->price,
            $this->downPayment,
            $this->installmentsCount,
            $this->frequencyDays,
        );
    }
}
