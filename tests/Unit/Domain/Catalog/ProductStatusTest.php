<?php

declare(strict_types=1);

use App\Domain\Catalog\ProductStatus;

it('declara los tres estados de los contratos compartidos', function (): void {
    $values = array_map(fn (ProductStatus $status): string => $status->value, ProductStatus::cases());

    expect($values)->toBe(['draft', 'published', 'archived']);
});

it('solo deja ver el producto publicado', function (ProductStatus $status, bool $visible): void {
    expect($status->isVisibleInCatalog())->toBe($visible);
})->with([
    'borrador' => [ProductStatus::Draft, false],
    'publicado' => [ProductStatus::Published, true],
    'archivado' => [ProductStatus::Archived, false],
]);

it('solo deja apartar el producto publicado', function (ProductStatus $status, bool $reservable): void {
    expect($status->canBeReserved())->toBe($reservable);
})->with([
    'borrador' => [ProductStatus::Draft, false],
    'publicado' => [ProductStatus::Published, true],
    'archivado' => [ProductStatus::Archived, false],
]);

it('contesta solo por el estado, sin fecha ni existencias', function (): void {
    $reflection = new ReflectionEnum(ProductStatus::class);

    foreach (['isVisibleInCatalog', 'canBeReserved'] as $method) {
        expect($reflection->getMethod($method)->getNumberOfParameters())->toBe(0);
    }
});

it('consume cupo del plan todo lo que no está archivado', function (): void {
    expect(ProductStatus::Draft->countsTowardPlanLimit())->toBeTrue();
    expect(ProductStatus::Published->countsTowardPlanLimit())->toBeTrue();
    expect(ProductStatus::Archived->countsTowardPlanLimit())->toBeFalse();
});
