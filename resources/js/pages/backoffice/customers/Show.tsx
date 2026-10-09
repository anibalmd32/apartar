import { Alert } from '@/components/ui/alert';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { CustomerLayaways } from '@/features/customers/components/CustomerLayaways';
import { CustomerReceipts } from '@/features/customers/components/CustomerReceipts';
import { MergeCustomerDialog } from '@/features/customers/components/MergeCustomerDialog';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CustomerShowProps } from '@/types/customers';
import { type MoneyView } from '@/types/money';
import { Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * El perfil de un cliente (`REQ-BOF-041`): todo lo suyo **en este negocio**
 * —de otros no se ve nada (RB-9)— para resolver su caso sin navegar entre
 * secciones. El balance es el mismo que ve el cliente en su portal (RB-2).
 */
export default function CustomerShow(props: CustomerShowProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const { customer, totals, layaways, lists } = props;

    return (
        <BackofficeLayout title={customer.name} description={`${customer.document} · ${customer.phoneLabel}`}>
            {errors.merge !== undefined ? <Alert tone="error">{errors.merge}</Alert> : null}
            {errors.resend !== undefined ? <Alert tone="warning">{errors.resend}</Alert> : null}

            <Card label="Contacto">
                <dl className="grid gap-2 text-sm sm:grid-cols-2">
                    <Field label="Teléfono">
                        <a href={`tel:${customer.phone}`} className="link">
                            {customer.phoneLabel}
                        </a>
                    </Field>
                    <Field label="Correo">{customer.email ?? 'Sin correo'}</Field>
                    <Field label="Cliente desde">{customer.createdAt !== null ? formatDay(customer.createdAt) : '—'}</Field>
                    <Field label="Aceptó los términos">{customer.termsAcceptedAt !== null ? formatDay(customer.termsAcceptedAt) : '—'}</Field>
                    <Field label="Cuenta vinculada">{customer.hasLinkedAccount ? 'Sí' : 'No'}</Field>
                </dl>
            </Card>

            <Card label="Balance">
                <dl className="grid gap-2 text-sm sm:grid-cols-3">
                    <Amount label="Comprometido" money={totals.committed} />
                    <Amount label="Pagado" money={totals.paid} />
                    <Amount label="Pendiente" money={totals.remaining} />
                    <Field label="Cuotas vencidas">
                        {totals.overdueInstallments}
                        {totals.overdueInstallments > 0 ? (
                            <>
                                {' '}
                                (<MoneyLabel money={totals.overdueAmount} />)
                            </>
                        ) : null}
                    </Field>
                    <Field label="Próximo vencimiento">{totals.nextDueDate !== null ? formatDay(totals.nextDueDate) : '—'}</Field>
                </dl>
            </Card>

            <Card label="Apartados activos">
                <CustomerLayaways layaways={layaways.active} empty="No tiene apartados activos." />
            </Card>

            {layaways.history.length > 0 ? (
                <Card label={`Historial de apartados (${layaways.historyTotal})`}>
                    <CustomerLayaways layaways={layaways.history} empty="" />
                    <More shown={layaways.history.length} total={layaways.historyTotal} url={lists.moreUrl} />
                </Card>
            ) : null}

            <Card label="Pagos">
                <PaymentHistory payments={props.payments} empty="Este cliente todavía no tiene pagos." />
                <More shown={props.payments.length} total={lists.paymentsTotal} url={lists.moreUrl} />
            </Card>

            <Card label="Comprobantes">
                <CustomerReceipts receipts={props.receipts} />
                <More shown={props.receipts.length} total={lists.receiptsTotal} url={lists.moreUrl} />
            </Card>

            <Card label="Fusionar con otra ficha">
                <MergeCustomerDialog merge={props.merge} />
            </Card>
        </BackofficeLayout>
    );
}

function Card({ label, children }: { label: string; children: ReactNode }) {
    return (
        <section className="card bg-base-100 shadow-sm" aria-label={label}>
            <div className="card-body gap-3">
                <h2 className="card-title text-base">{label}</h2>
                {children}
            </div>
        </section>
    );
}

/** SP-2 · una lista cortada lo dice, y trae 20 más sin perder el sitio. */
function More({ shown, total, url }: { shown: number; total: number; url: string | null }) {
    if (total <= shown) {
        return null;
    }

    return (
        <p className="text-xs opacity-70">
            Se muestran los {shown} más recientes de {total}.{' '}
            {url !== null ? (
                <Link href={url} preserveScroll preserveState className="link">
                    Ver más
                </Link>
            ) : null}
        </p>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="opacity-70">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

function Amount({ label, money }: { label: string; money: MoneyView }) {
    return (
        <Field label={label}>
            <MoneyLabel money={money} />
        </Field>
    );
}
