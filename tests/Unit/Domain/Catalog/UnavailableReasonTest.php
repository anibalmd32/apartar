<?php

declare(strict_types=1);

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\UnavailableReason;
use App\Domain\Money\Money;
use App\Models\Product;

function productWithStock(int $stock): Product
{
    return (new Product)->forceFill(['status' => ProductStatus::Published, 'stock' => $stock]);
}

function coherentPlan(): FinancingPlan
{
    return new FinancingPlan(new Money(25_000), new Money(5_000), 4, 15);
}

it('deja apartar lo publicado con unidades y plan', function (): void {
    expect(UnavailableReason::of(productWithStock(1), coherentPlan()))->toBeNull();
});

it('dice agotado sin unidades', function (): void {
    expect(UnavailableReason::of(productWithStock(0), coherentPlan()))->toBe(UnavailableReason::OutOfStock);
});

it('dice no apartable sin plan, aunque además esté agotado', function (): void {
    expect(UnavailableReason::of(productWithStock(5), null))->toBe(UnavailableReason::NotReservable)
        ->and(UnavailableReason::of(productWithStock(0), null))->toBe(UnavailableReason::NotReservable);
});

it('dice no apartable si el estado no lo admite', function (): void {
    $archived = productWithStock(5)->forceFill(['status' => ProductStatus::Archived]);

    expect(UnavailableReason::of($archived, coherentPlan()))->toBe(UnavailableReason::NotReservable);
});

it('dice que el negocio no recibe apartados antes que cualquier otro motivo', function (int $stock, bool $withPlan): void {
    $product = productWithStock($stock);
    $plan = $withPlan ? coherentPlan() : null;

    expect(UnavailableReason::of($product, $plan, businessAccepts: false))->toBe(UnavailableReason::BusinessUnavailable);
    expect(UnavailableReason::of($product, $plan, businessAccepts: true))->not->toBe(UnavailableReason::BusinessUnavailable);
})->with([[0, true], [1, true], [0, false], [1, false]]);

it('no revela el plan del negocio en ningún motivo', function (UnavailableReason $reason): void {
    expect(mb_strtolower($reason->message()))
        ->not->toContain('plan')
        ->not->toContain('límite')
        ->not->toContain('free')
        ->not->toContain('suscrip')
        ->not->toContain('pago')
        ->not->toContain('mejorar');
})->with(UnavailableReason::cases());

it('explica cada motivo con una frase', function (UnavailableReason $reason): void {
    expect(mb_strlen($reason->message()))->toBeGreaterThan(20);
})->with(UnavailableReason::cases());
