import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { pf } from '@/lib/surfaces';
import { type InvoiceRow } from '@/types/platform';
import { useState } from 'react';
import { InvoiceStatusBadge } from './labels';

const OPEN = ['pending', 'under_review', 'overdue'];

const DAY = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

export function humanDay(day: string): string {
    const date = new Date(`${day.slice(0, 10)}T00:00:00Z`);

    return Number.isNaN(date.getTime()) ? day : DAY.format(date).replace('.', '');
}

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
                        <li key={invoice.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 md:flex-row md:items-center md:gap-4">
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <div className="flex items-baseline justify-between gap-3">
                                    <p className="text-sm font-semibold capitalize">{invoice.plan}</p>
                                    <p className="text-sm font-bold whitespace-nowrap tabular-nums md:hidden">{invoice.amount.usd}</p>
                                </div>
                                <div className="text-ink-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                    <span className="md:hidden">
                                        <InvoiceStatusBadge status={invoice.status} />
                                    </span>
                                    <span className="whitespace-nowrap">Vence el {humanDay(invoice.dueDate)}</span>
                                    {Number(invoice.paid.cents) > 0 ? <span className="whitespace-nowrap">Pagado {invoice.paid.usd}</span> : null}
                                </div>
                                {invoice.voidReason ? <p className="text-ink-3 text-xs">Motivo: {invoice.voidReason}</p> : null}
                            </div>
                            <span className="hidden text-sm font-semibold whitespace-nowrap tabular-nums md:inline">{invoice.amount.usd}</span>
                            <span className="hidden md:inline-flex">
                                <InvoiceStatusBadge status={invoice.status} />
                            </span>
                            {OPEN.includes(invoice.status) ? (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    className="text-error self-start md:self-auto"
                                    onClick={() => setVoiding(invoice)}
                                    aria-label={`Anular la factura de ${invoice.plan} que vence el ${humanDay(invoice.dueDate)}`}
                                >
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
                    voiding !== null
                        ? `Factura del plan ${voiding.plan} por ${voiding.amount.usd}, que vence el ${humanDay(voiding.dueDate)}.`
                        : undefined
                }
                confirmLabel="Anular factura"
                method="post"
                url={voiding !== null ? pf(`/facturas/${voiding.id}/anular`) : ''}
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
