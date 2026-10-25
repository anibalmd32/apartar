import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { type InvoiceRow } from '@/types/platform';
import { useState } from 'react';
import { InvoiceStatusBadge } from './labels';

const OPEN = ['pending', 'under_review', 'overdue'];

export function InvoiceList({ invoices }: { invoices: readonly InvoiceRow[] }) {
    const [voiding, setVoiding] = useState<InvoiceRow | null>(null);
    const [reason, setReason] = useState('');

    const close = () => {
        setVoiding(null);
        setReason('');
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
                                <Button size="sm" variant="ghost" className="text-error" onClick={() => setVoiding(invoice)}>
                                    Anular
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                open={voiding !== null}
                onClose={close}
                title="¿Anular esta factura?"
                description={
                    voiding !== null ? `Factura del plan ${voiding.plan} por ${voiding.amount.usd}, que vence el ${voiding.dueDate}.` : undefined
                }
                confirmLabel="Anular factura"
                method="post"
                url={voiding !== null ? `/plataforma/facturas/${voiding.id}/anular` : ''}
                data={{ reason: reason.trim() }}
                confirmDisabled={reason.trim() === ''}
            >
                <TextField
                    label="Motivo"
                    hint="El negocio lo verá junto a la factura."
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    autoFocus
                />
            </ConfirmDialog>
        </SectionCard>
    );
}
