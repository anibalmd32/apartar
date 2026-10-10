<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\StreamsRowsById;
use App\Models\Customer;
use App\Queries\CustomerDirectoryFilters;
use App\Queries\CustomerDirectoryQuery;
use App\Queries\CustomerDirectoryRow;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * El directorio de clientes en Excel (`REQ-BOF-043` RB-3, RB-9, `T-070`).
 *
 * Las filas salen de `CustomerDirectoryQuery::search()`, la misma consulta de
 * la pantalla, y cada fila se compone con `CustomerDirectoryRow`, lo mismo
 * que la tabla pinta.
 *
 * **Nada de la cuenta de consumidor** (RB-9, `REQ-ACC-007` RB-5): ni
 * `account_id` ni si la ficha tiene cuenta —la tabla lo enseña como un
 * distintivo, el archivo no—. `GenerateExportTest` lo afirma con una ficha
 * vinculada de verdad.
 */
final class CustomersExport implements FromGenerator, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    use StreamsRowsById;

    /**
     * @param  array<string, string>  $meta
     */
    public function __construct(
        private readonly CustomerDirectoryFilters $filters,
        private readonly array $meta,
    ) {}

    /**
     * @return Builder<Customer>
     */
    public function query(): Builder
    {
        return app(CustomerDirectoryQuery::class)->search($this->filters);
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return $this->headingRows('Apartar · Clientes', $this->meta, [
            'Nombre', 'Documento', 'Teléfono', 'Correo', 'Apartados activos',
            'Comprometido (USD)', 'Pagado (USD)', 'Pendiente (USD)', 'Cuotas vencidas', 'En mora', 'Próximo vencimiento',
        ]);
    }

    /**
     * @return Generator<int, list<string|int|float|null>>
     */
    public function generator(): Generator
    {
        foreach ($this->streamById($this->query()) as $customer) {
            $row = CustomerDirectoryRow::of($customer);

            yield [
                $row->name,
                $row->document,
                $row->phone,
                $customer->email,
                $row->activeLayaways,
                self::dollars($row->committed->cents),
                self::dollars($row->paid->cents),
                self::dollars($row->remaining->cents),
                $row->overdueInstallments,
                $row->inArrears ? 'Sí' : 'No',
                $row->nextDueDate,
            ];
        }
    }
}
