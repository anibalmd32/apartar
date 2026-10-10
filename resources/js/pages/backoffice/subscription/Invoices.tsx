import { Alert } from '@/components/ui/alert';
import { PayInvoiceForm } from '@/features/subscription/components/PayInvoiceForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatPlanDate } from '@/lib/plan-change';
import { type InvoiceRow, type InvoicesPageProps, type InvoiceStatus } from '@/types/invoice';
import { Link } from '@inertiajs/react';

const STATUS_LABELS: Record<InvoiceStatus, string> = {
    pending: 'Pendiente',
    under_review: 'En revisión',
    paid: 'Pagada',
    overdue: 'Vencida',
    void: 'Anulada',
};

const STATUS_BADGES: Record<InvoiceStatus, string> = {
    pending: 'badge-warning',
    under_review: 'badge-info',
    paid: 'badge-success',
    overdue: 'badge-error',
    void: 'badge-ghost',
};

/**
 * El historial de facturas de la suscripción y el pago de cada una
 * (`REQ-SUB-006` RB-1, RB-7, RB-10, `T-069`): el importe en dólares y su
 * equivalente en bolívares a la tasa de la plataforma, el estado, el último
 * comprobante —con el motivo si se rechazó— y, si todavía se paga, el
 * formulario para subir el comprobante.
 */
export default function SubscriptionInvoices({ invoices, methods, currencies, file, subscriptionUrl }: InvoicesPageProps) {
    return (
        <BackofficeLayout title="Facturas" description="Lo que has pagado de tu suscripción y lo que queda por pagar.">
            <Link href={subscriptionUrl} className="link text-sm">
                Volver a Suscripción
            </Link>

            {invoices.length === 0 ? (
                <Alert tone="info">Todavía no tienes facturas: en Free no se factura nada.</Alert>
            ) : (
                <ul className="flex flex-col gap-3">
                    {invoices.map((invoice) => (
                        <li key={invoice.id}>
                            <InvoiceCard invoice={invoice} methods={methods} currencies={currencies} file={file} />
                        </li>
                    ))}
                </ul>
            )}
        </BackofficeLayout>
    );
}

function InvoiceCard({ invoice, ...form }: { invoice: InvoiceRow } & Pick<InvoicesPageProps, 'methods' | 'currencies' | 'file'>) {
    return (
        <article className="card bg-base-100 shadow-sm">
            <div className="card-body gap-2">
                <header className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="card-title text-base">
                        {invoice.planName} · {invoice.kind}
                    </h2>
                    <span className={`badge ${STATUS_BADGES[invoice.status]}`}>{STATUS_LABELS[invoice.status]}</span>
                </header>

                <p className="text-sm">
                    Del {formatPlanDate(invoice.periodStart)} al {formatPlanDate(invoice.periodEnd)} · vence el {formatDay(invoice.dueDate)}
                </p>
                <p className="text-lg font-semibold">
                    {invoice.amount.usd}
                    {invoice.amount.ves !== null ? <span className="ml-2 text-sm font-normal opacity-70">≈ {invoice.amount.ves}</span> : null}
                </p>
                {invoice.outstanding !== null ? <p className="text-sm">Falta por pagar: {invoice.outstanding.usd}</p> : null}

                {invoice.receiptStatus === 'pending_review' ? <Alert tone="info">Tu comprobante está en revisión.</Alert> : null}
                {invoice.receiptStatus === 'rejected' ? (
                    <Alert tone="warning">Rechazamos tu último comprobante: {invoice.rejectionReason?.replace(/\.$/, '')}. Puedes enviar otro.</Alert>
                ) : null}

                {invoice.payUrl !== null && invoice.receiptStatus !== 'pending_review' ? <PayInvoiceForm invoice={invoice} {...form} /> : null}
            </div>
        </article>
    );
}

/** Un día del calendario (`2026-09-25`) como se lee: sin zona, porque no es un instante. */
function formatDay(day: string): string {
    const [year, month, date] = day.split('-');

    return `${date}/${month}/${year}`;
}
