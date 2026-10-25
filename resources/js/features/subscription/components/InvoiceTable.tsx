import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type InvoiceRow, type InvoiceStatus } from '@/types/invoice';

const STATUS_LABELS: Record<InvoiceStatus, string> = {
    pending: 'Pendiente',
    under_review: 'En revisión',
    paid: 'Pagada',
    overdue: 'Vencida',
    void: 'Anulada',
};

const STATUS_TONES: Record<InvoiceStatus, BadgeTone> = {
    pending: 'warning',
    under_review: 'info',
    paid: 'success',
    overdue: 'error',
    void: 'neutral',
};

export function isPayable(invoice: InvoiceRow): boolean {
    return invoice.payUrl !== null && invoice.receiptStatus !== 'pending_review';
}

export function formatDay(day: string, withYear = false): string {
    const [year, month, date] = day.split('-');

    return withYear ? `${date}/${month}/${year}` : `${date}/${month}`;
}

export function InvoiceTable({
    invoices,
    selectedId,
    onSelect,
}: {
    invoices: readonly InvoiceRow[];
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    return (
        <div className="bg-base-100 border-line shadow-card overflow-x-auto rounded-2xl border">
            <table className="table">
                <thead>
                    <tr>
                        <th scope="col">Factura</th>
                        <th scope="col">Periodo</th>
                        <th scope="col">Monto</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Vence</th>
                        <th scope="col">Comprobante</th>
                        <th scope="col">
                            <span className="sr-only">Acciones</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {invoices.map((invoice) => (
                        <tr key={invoice.id} className={invoice.id === selectedId ? 'bg-primary-soft/40' : undefined}>
                            <td>
                                <p className="font-bold whitespace-nowrap">{invoice.number}</p>
                                <p className="text-ink-3 text-xs">{invoice.kind}</p>
                            </td>
                            <td className="text-ink-2 whitespace-nowrap">
                                {formatDay(invoice.periodStart.slice(0, 10))} – {formatDay(invoice.periodEnd.slice(0, 10))}
                            </td>
                            <td>
                                <p className="font-semibold whitespace-nowrap">{invoice.amount.usd}</p>
                                {invoice.amount.ves !== null ? <p className="text-ink-3 text-xs whitespace-nowrap">≈ {invoice.amount.ves}</p> : null}
                                {invoice.outstanding !== null ? (
                                    <p className="text-warning text-xs font-semibold whitespace-nowrap">Falta por pagar: {invoice.outstanding.usd}</p>
                                ) : null}
                            </td>
                            <td>
                                <Badge tone={STATUS_TONES[invoice.status]}>{STATUS_LABELS[invoice.status]}</Badge>
                            </td>
                            <td className="text-ink-2 whitespace-nowrap">{formatDay(invoice.dueDate)}</td>
                            <td>
                                <ReceiptCell invoice={invoice} />
                            </td>
                            <td className="text-right">
                                {isPayable(invoice) && invoice.id !== selectedId ? (
                                    <Button size="sm" variant="soft" aria-label={`Pagar ${invoice.number}`} onClick={() => onSelect(invoice.id)}>
                                        Pagar
                                    </Button>
                                ) : null}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ReceiptCell({ invoice }: { invoice: InvoiceRow }) {
    if (invoice.status === 'paid') {
        return <span className="text-success font-medium">Aprobado</span>;
    }

    if (invoice.receiptStatus === 'pending_review') {
        return <span className="text-info font-medium">En revisión</span>;
    }

    if (invoice.receiptStatus === 'rejected') {
        return (
            <>
                <p className="text-error font-medium">Rechazado</p>
                {invoice.rejectionReason !== null ? <p className="text-ink-3 text-xs">{invoice.rejectionReason.replace(/\.$/, '')}</p> : null}
            </>
        );
    }

    return <span className="text-ink-3">—</span>;
}
