<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Money\Money;
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

final class CollectionsExport implements FromGenerator, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    use StreamsRowsById;

    /**
     * @param  array<string, string>  $meta
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
        return $this->headingRows('Apartar · Cobranza', $this->meta, match (true) {
            $this->awaitsDownPayment() => ['Cliente', 'Teléfono', 'Apartado', 'Producto', 'Inicial (USD)', 'Falta de la inicial (USD)', 'Vence'],
            $this->listsLayaways() => ['Cliente', 'Teléfono', 'Apartado', 'Producto', 'Completado el', 'Total (USD)'],
            default => ['Cliente', 'Teléfono', 'Apartado', 'Cuota', 'Vence', 'Importe (USD)', 'Pagado (USD)', 'Pendiente (USD)', 'Días de atraso'],
        });
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
                yield $this->awaitsDownPayment() ? $this->downPaymentRow($layaway, $zone) : $this->layawayRow($layaway, $zone);
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
     * @return list<string|int|float|null>
     */
    private function downPaymentRow(Layaway $layaway, DateTimeZone $zone): array
    {
        $total = $layaway->terms()->totalDownPayment();

        return [
            ...$this->customerCells($layaway->customer),
            $layaway->code,
            $layaway->product_name_snapshot,
            self::dollars($total),
            self::dollars(new Money(max(0, $total->cents - $layaway->paid_cents->cents))),
            $layaway->expires_at?->setTimezone($zone)->format('Y-m-d H:i'),
        ];
    }

    private function awaitsDownPayment(): bool
    {
        return $this->filters->preset?->awaitsDownPayment() === true;
    }

    /**
     * @return list<string|null>
     */
    private function customerCells(?Customer $customer): array
    {
        return [$customer?->full_name, $customer?->phone];
    }
}
