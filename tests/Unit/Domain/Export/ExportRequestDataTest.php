<?php

declare(strict_types=1);

use App\Actions\Export\ExportRequestData;
use App\Domain\Export\ExportType;

it('guarda solo las claves de su tipo, como texto', function (): void {
    $data = ExportRequestData::of(ExportType::Collections, [
        'preset' => 'due_today',
        'q' => '',
        'status' => ['active'],
        'catalog' => str_repeat('9', ExportRequestData::VALUE_MAX + 1),
        'low_stock' => '1',
        'evil' => 'x',
    ]);

    expect($data->filters)->toBe(['preset' => 'due_today'])->and($data->type)->toBe(ExportType::Collections);
});

it('lee las claves de la pantalla de cada tipo', function (): void {
    expect(ExportRequestData::keysOf(ExportType::Inventory))->toBe(['status', 'category', 'tag', 'low_stock', 'q', 'scheduled'])
        ->and(ExportRequestData::keysOf(ExportType::Customers))->toBe(['q', 'overdue', 'due_soon', 'without_active', 'from', 'to', 'catalog'])
        ->and(ExportRequestData::keysOf(ExportType::Collections))->toBe(['preset', 'from', 'to', 'status', 'q', 'category', 'catalog']);
});
