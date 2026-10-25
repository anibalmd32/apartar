<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\StreamsRowsById;
use App\Models\Product;
use App\Queries\InventoryFilters;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class InventoryExport implements FromGenerator, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    use StreamsRowsById;

    /**
     * @param  array<string, string>  $meta
     */
    public function __construct(
        private readonly InventoryFilters $filters,
        private readonly array $meta,
        private readonly string $timezone,
    ) {}

    /**
     * @return Builder<Product>
     */
    public function query(): Builder
    {
        return $this->filters->apply(Product::query()->with('category')->withCount('catalogs')->withReservedUnits());
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return $this->headingRows('Apartar · Inventario', $this->meta, [
            'SKU', 'Producto', 'Estado', 'Categoría', 'Precio (USD)', 'Disponibles', 'Apartadas', 'Faltantes', 'Catálogos', 'Actualizado',
        ]);
    }

    /**
     * @return Generator<int, list<string|int|float|null>>
     */
    public function generator(): Generator
    {
        foreach ($this->streamById($this->query()) as $product) {
            yield [
                $product->sku,
                $product->name,
                $product->status->label(),
                $product->category?->name,
                self::dollars($product->price_cents),
                $product->stock,
                $product->reservedUnits(),
                $product->shortfallUnits(),
                (int) $product->getAttribute('catalogs_count'),
                $product->updated_at?->setTimezone($this->timezone)->toDateTimeString(),
            ];
        }
    }
}
