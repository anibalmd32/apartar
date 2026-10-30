<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\StreamsRowsById;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\DocumentNumber;
use DateTimeZone;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class PaymentsExport implements FromGenerator, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    use StreamsRowsById;

    /**
     * @param  array<string, string>  $meta
     */
    public function __construct(
        private readonly array $meta,
        private readonly string $timezone,
    ) {}

    /**
     * @return Builder<Payment>
     */
    public function query(): Builder
    {
        return Payment::query()->with([
            'layaway:id,organization_id,code,product_name_snapshot,customer_id',
            'layaway.customer:id,organization_id,full_name,document_number',
            'receipt',
            'receipt.paymentAccount:id,organization_id,method,bank,identifier',
            'paymentAccount:id,organization_id,method,bank,identifier',
        ]);
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return $this->headingRows('Apartar · Pagos', $this->meta, [
            'Fecha del pago', 'Registrado el', 'Cliente', 'Documento', 'Apartado', 'Producto', 'Origen', 'Método', 'Cuenta de cobro',
            'Banco', 'Referencia', 'Moneda declarada', 'Monto declarado', 'Tasa aplicada (Bs./USD)', 'Fuente de la tasa', 'Monto (USD)', 'Nota',
        ]);
    }

    /**
     * @return Generator<int, list<string|int|float|null>>
     */
    public function generator(): Generator
    {
        $zone = new DateTimeZone($this->timezone);

        foreach ($this->streamById($this->query()) as $payment) {
            $receipt = $payment->receipt;
            $customer = $payment->layaway->customer;
            $account = $payment->paymentAccount ?? $receipt?->paymentAccount;
            $paidOn = $payment->paid_on ?? $receipt?->paid_at;

            yield [
                $paidOn?->toDateString(),
                $payment->settled_at->setTimezone($zone)->format('Y-m-d H:i'),
                $customer?->full_name,
                self::document($customer),
                $payment->layaway->code,
                $payment->layaway->product_name_snapshot,
                $payment->isManual() ? 'Registro manual' : 'Comprobante',
                ($payment->payment_method ?? $receipt?->payment_method)?->label(),
                $account === null ? null : trim(sprintf('%s %s', $account->method->label(), $account->identifier)),
                $payment->bank ?? $receipt->bank ?? $account?->bank,
                $payment->reference ?? $receipt?->reference,
                $payment->declared_currency->value,
                round((float) $payment->declared_amount, 2),
                round((float) $payment->rate_applied, 8),
                $payment->rate_source->label(),
                self::dollars($payment->amount_cents_usd),
                $payment->note,
            ];
        }
    }

    private static function document(?Customer $customer): ?string
    {
        if ($customer === null) {
            return null;
        }

        $document = DocumentNumber::tryFromInput($customer->document_number);

        return $document instanceof DocumentNumber ? $document->formatted() : $customer->document_number;
    }
}
