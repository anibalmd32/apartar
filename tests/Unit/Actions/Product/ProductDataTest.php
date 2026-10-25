<?php

declare(strict_types=1);

use App\Actions\Product\ProductData;
use App\Domain\Catalog\FinancingPlan;
use App\Domain\Money\Money;

function productData(
    string $price = '250.00',
    string $downPayment = '50.00',
    int $installments = 5,
    int $frequency = 15,
): ProductData {
    return new ProductData(
        name: 'Taladro Bosch',
        sku: 'BOSCH-01',
        slug: null,
        description: null,
        price: Money::fromDecimalString($price),
        compareAtPrice: null,
        downPayment: Money::fromDecimalString($downPayment),
        installmentsCount: $installments,
        frequencyDays: $frequency,
        stock: 10,
    );
}

it('guarda los importes en céntimos', function (): void {
    $data = productData(price: '250.00', downPayment: '50.00');

    expect($data->price->cents)->toBe(25_000);
    expect($data->downPayment->cents)->toBe(5_000);
    expect($data->compareAtPrice)->toBeNull();
});

it('nace sin taxonomía', function (): void {
    $data = productData();

    expect($data->categoryId)->toBeNull();
    expect($data->categoryName)->toBeNull();
    expect($data->tagIds)->toBe([]);
    expect($data->tagNames)->toBe([]);
});

it('compone el plan con el reparto del dominio', function (): void {
    $plan = productData(price: '250.00', downPayment: '50.00', installments: 5)->financingPlan();

    expect($plan)->toBeInstanceOf(FinancingPlan::class);
    expect($plan?->installmentAmount()->cents)->toBe(4_000);
    expect($plan?->financedAmount()->cents)->toBe(20_000);
});

it('no compone plan cuando los términos no permiten repartir nada', function (string $price, string $down, int $count): void {
    expect(productData(price: $price, downPayment: $down, installments: $count)->financingPlan())->toBeNull();
})->with([
    'inicial igual al precio' => ['100.00', '100.00', 4],
    'inicial por encima' => ['100.00', '150.00', 4],
    'sin cuotas' => ['100.00', '10.00', 0],
]);
