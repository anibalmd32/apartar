import { SectionCard } from '@/components/ui/section-card';
import { type InvoiceRow } from '@/types/platform';
import { router } from '@inertiajs/react';
import { InvoiceStatusBadge } from './labels';

const OPEN = ['pending', 'under_review', 'overdue'];

export function InvoiceList({ invoices }: { invoices: readonly InvoiceRow[] }) {
    const voidInvoice = (id: number) => {
        const reason = window.prompt('Motivo de la anulación (el negocio lo verá):');

        if (reason !== null && reason.trim() !== '') {
            router.post(`/plataforma/facturas/${id}/anular`, { reason });
        }
    };

    return (
        <SectionCard title="Facturas" aria-label="Facturas">
            {invoices.length === 0 ? (
                <p className="text-ink-2 text-sm">Sin facturas.</p>
            ) : (
                <ul className="divide-line flex flex-col divide-y">
                    {invoices.map((invoice) => (
                        <li key={invoice.id} className="flex flex-wrap items-center gap-x-4 gap-y-1.5 py-3 first:pt-0 last:pb-0">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold capitalize">{invoice.plan}</p>
                                <p className="text-ink-3 text-xs">
                                    Vence el {invoice.dueDate} · pagado {invoice.paid.usd}
                                    {invoice.voidReason ? ` · ${invoice.voidReason}` : ''}
                                </p>
                            </div>
                            <span className="text-sm font-semibold">{invoice.amount.usd}</span>
                            <InvoiceStatusBadge status={invoice.status} />
                            {OPEN.includes(invoice.status) ? (
                                <button type="button" className="btn btn-ghost btn-xs" onClick={() => voidInvoice(invoice.id)}>
                                    Anular
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
