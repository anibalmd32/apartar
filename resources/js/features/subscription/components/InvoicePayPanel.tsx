import { Alert } from '@/components/ui/alert';
import { CopyButton } from '@/features/catalog/components/CopyButton';
import { PayInvoiceForm } from '@/features/subscription/components/PayInvoiceForm';
import { type InvoiceRow, type InvoicesPageProps } from '@/types/invoice';

export function InvoicePayPanel({
    invoice,
    platformAccounts,
    ...form
}: { invoice: InvoiceRow } & Pick<InvoicesPageProps, 'methods' | 'currencies' | 'file' | 'platformAccounts'>) {
    const pending = invoice.outstanding ?? invoice.amount;

    return (
        <section aria-label={`Pagar ${invoice.number}`} className="bg-base-100 border-line shadow-card flex flex-col gap-4 rounded-2xl border p-5">
            <header className="flex flex-col gap-0.5">
                <h2 className="text-[15px] font-bold">Pagar {invoice.number}</h2>
                <p className="text-ink-2 text-[13px]">
                    {pending.usd}
                    {pending.ves !== null ? ` · ${pending.ves} a tasa BCV` : null}
                </p>
            </header>

            {invoice.receiptStatus === 'rejected' ? (
                <Alert tone="warning">Rechazamos tu último comprobante: {invoice.rejectionReason?.replace(/\.$/, '')}. Puedes enviar otro.</Alert>
            ) : null}

            {platformAccounts.length > 0 ? (
                <div className="bg-surface-2 flex flex-col gap-2 rounded-xl p-3.5">
                    <p className="text-eyebrow text-ink-3">Cuentas de Apartar</p>
                    <ul className="flex flex-col gap-2">
                        {platformAccounts.map((account) => (
                            <li key={account.method} className="flex items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-[13px] font-semibold">{account.method}</p>
                                    <p className="text-ink-2 truncate text-xs">{account.detail}</p>
                                </div>
                                <CopyButton value={account.detail} label={account.method} />
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <PayInvoiceForm key={invoice.id} invoice={invoice} {...form} />
        </section>
    );
}
