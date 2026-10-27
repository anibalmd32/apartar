import { Alert } from '@/components/ui/alert';
import { InvoicePayPanel } from '@/features/subscription/components/InvoicePayPanel';
import { formatDay, InvoiceTable, isPayable } from '@/features/subscription/components/InvoiceTable';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type InvoicesPageProps } from '@/types/invoice';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function SubscriptionInvoices({ invoices, methods, currencies, file, subscriptionUrl, platformAccounts }: InvoicesPageProps) {
    const { url } = usePage();
    const requested = Number(new URLSearchParams(url.split('?')[1] ?? '').get('pagar'));
    const payable = invoices.filter(isPayable);
    const [selectedId, setSelectedId] = useState<number | null>((payable.find((invoice) => invoice.id === requested) ?? payable[0])?.id ?? null);
    const selected = payable.find((invoice) => invoice.id === selectedId) ?? null;
    const open = invoices.find((invoice) => invoice.payUrl !== null) ?? null;

    return (
        <BackofficeLayout
            title="Facturas"
            description="Lo que pagas a Apartar por tu plan. Pagas subiendo el comprobante, y aquí verás cuándo lo aprobamos."
            back={{ href: subscriptionUrl, label: 'Suscripción' }}
        >
            {invoices.length === 0 ? (
                <Alert tone="info">Todavía no tienes facturas: en el plan Gratis no se factura nada.</Alert>
            ) : (
                <>
                    {open !== null ? (
                        <Alert tone={open.status === 'overdue' ? 'error' : 'warning'} title="Tienes una factura por pagar">
                            {open.number} · {(open.outstanding ?? open.amount).usd} · {open.status === 'overdue' ? 'venció' : 'vence'} el{' '}
                            {formatDay(open.dueDate)}.{open.receiptStatus === 'pending_review' ? ' Tu comprobante está en revisión.' : null}
                        </Alert>
                    ) : null}

                    <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                        <InvoiceTable invoices={invoices} selectedId={selected?.id ?? null} onSelect={setSelectedId} />
                        {selected !== null ? (
                            <InvoicePayPanel
                                invoice={selected}
                                methods={methods}
                                currencies={currencies}
                                file={file}
                                platformAccounts={platformAccounts}
                            />
                        ) : null}
                    </div>
                </>
            )}
        </BackofficeLayout>
    );
}
