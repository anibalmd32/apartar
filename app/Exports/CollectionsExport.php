<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\StreamsRowsById;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Queries\CollectionFilters;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * La cartera en Excel (`REQ-BOF-043` RB-3, RB-8, `REQ-BOF-042` RB-1, `T-070`).
 *
 * **Las filas salen de `CollectionFilters::query()`**, la misma consulta que
 * la pantalla (`REQ-BOF-042` RB-1: duplicar el filtrado está prohibido), con
 * sus dos formas: cuotas sin cubrir, o **apartados** pagados sin entregar con
 * el preset `completed_not_delivered` (`preset->listsLayaways()`), cada una
 * con sus columnas. Los importes van en dólares, como números, en columnas
 * propias (RB-8).
 *
 * Sin nada de la cuenta de consumidor (RB-9): cliente, teléfono y apartado,
 * como la tabla.
 */
final class CollectionsExport implements FromGenerator, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    use StreamsRowsById;

    /**
     * @param  array<string, string>  $meta  lo que la cabecera dice: cuándo, filtros, gracia, ignorados
     */
    public function __construct(
        private readonly CollectionFilters $filters,
        private readonly array $meta,
        private readonly string $timezone,
    ) {}

    public function listsLayaways(): bool
    {
        return $this->filters->preset?->listsLayaways() === true;
    }

    /**
     * @return Builder<Installment>|Builder<Layaway>
     */
    public function query(): Builder
    {
        $query = $this->filters->query();

        return $this->listsLayaways()
            ? $query->with('customer:id,organization_id,full_name,phone')
            : $query->with(['layaway:id,organization_id,code,customer_id', 'layaway.customer:id,organization_id,full_name,phone']);
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return $this->headingRows('Apartar · Cobranza', $this->meta, $this->listsLayaways()
            ? ['Cliente', 'Teléfono', 'Apartado', 'Producto', 'Completado el', 'Total (USD)']
            : ['Cliente', 'Teléfono', 'Apartado', 'Cuota', 'Vence', 'Importe (USD)', 'Pagado (USD)', 'Pendiente (USD)', 'Días de atraso']);
    }

    /**
     * @return Generator<int, list<string|int|float|null>>
     */
    public function generator(): Generator
    {
        $zone = new DateTimeZone($this->timezone);
        $today = CarbonImmutable::now($zone)->startOfDay();

        if ($this->listsLayaways()) {
            foreach ($this->streamById($this->filters->layaways()->with('customer:id,organization_id,full_name,phone')) as $layaway) {
                yield $this->layawayRow($layaway, $zone);
            }

            return;
        }

        foreach ($this->streamById($this->filters->installments()->with(['layaway:id,organization_id,code,customer_id', 'layaway.customer:id,organization_id,full_name,phone'])) as $installment) {
            yield $this->installmentRow($installment, $today);
        }
    }

    /**
     * @return list<string|int|float|null>
     */
    private function installmentRow(Installment $installment, CarbonImmutable $today): array
    {
        /** @var Layaway $layaway */
        $layaway = $installment->layaway;
        $due = CarbonImmutable::parse($installment->due_date->toDateString(), $today->getTimezone())->startOfDay();

        return [
            ...$this->customerCells($layaway->customer),
            $layaway->code,
            $installment->number,
            $installment->due_date->toDateString(),
            self::dollars($installment->amount_cents),
            self::dollars($installment->paid_cents),
            self::dollars($installment->amount_cents->subtract($installment->paid_cents)),
            max(0, (int) $due->diffInDays($today, false)),
        ];
    }

    /**
     * @return list<string|int|float|null>
     */
    private function layawayRow(Layaway $layaway, DateTimeZone $zone): array
    {
        return [
            ...$this->customerCells($layaway->customer),
            $layaway->code,
            $layaway->product_name_snapshot,
            $layaway->completed_at?->setTimezone($zone)->toDateString(),
            self::dollars($layaway->total_cents),
        ];
    }

    /**
     * @return list<string|null>
     */
    private function customerCells(?Customer $customer): array
    {
        return [$customer?->full_name, $customer?->phone];
    }
}
