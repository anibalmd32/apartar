<?php

declare(strict_types=1);

use App\Domain\Export\ExportType;

it('declara exactamente los tres tipos', function (): void {
    expect(array_map(fn (ExportType $type): string => $type->value, ExportType::cases()))
        ->toBe(['collections', 'customers', 'inventory']);
});

it('nombra cada tipo y su archivo', function (ExportType $type, string $label, string $file): void {
    expect($type->label())->toBe($label)->and($type->filename())->toBe($file);
})->with([
    [ExportType::Collections, 'Cobranza', 'cobranza'],
    [ExportType::Customers, 'Clientes', 'clientes'],
    [ExportType::Inventory, 'Inventario', 'inventario'],
]);
