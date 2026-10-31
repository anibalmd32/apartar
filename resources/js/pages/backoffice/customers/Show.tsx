import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { type FilterTab, FilterTabs } from '@/components/ui/filter-tabs';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { SectionCard } from '@/components/ui/section-card';
import { CustomerLayaways } from '@/features/customers/components/CustomerLayaways';
import { BalanceRow, ContactStrip } from '@/features/customers/components/CustomerProfile';
import { CustomerReceipts } from '@/features/customers/components/CustomerReceipts';
import { ManageCustomer } from '@/features/customers/components/ManageCustomer';
import { MergeCustomerDialog } from '@/features/customers/components/MergeCustomerDialog';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CustomerShowProps } from '@/types/customers';
import { cilBell, cilChatBubble, cilLibrary, cilLink, cilPlus } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Tab = 'layaways' | 'payments' | 'receipts';

export default function CustomerShow(props: CustomerShowProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const { customer, totals, layaways, lists } = props;
    const [tab, setTab] = useState<Tab>('layaways');

    const tabs: FilterTab<Tab>[] = [
        { value: 'layaways', label: 'Apartados', count: layaways.active.length },
        { value: 'payments', label: 'Pagos', count: lists.paymentsTotal },
        { value: 'receipts', label: 'Comprobantes', count: lists.receiptsTotal },
    ];

    return (
        <BackofficeLayout
            title={customer.name}
            back={{ href: '/backoffice/clientes', label: 'Clientes' }}
            badge={
                <>
                    {customer.hasLinkedAccount ? (
                        <Badge tone="primary">
                            <Icon icon={cilLink} size={12} />
                            Cuenta vinculada
                        </Badge>
                    ) : null}
                    {totals.overdueInstallments > 0 ? <Badge tone="error">En mora</Badge> : null}
                </>
            }
            actions={
                <>
                    <Link href={`/backoffice/apartados/nuevo?cliente=${customer.id}`} className="btn btn-secondary">
                        <Icon icon={cilPlus} />
                        Nuevo apartado
                    </Link>
                    <Link href={`/backoffice/avisos?cliente=${customer.id}`} className="btn btn-secondary">
                        <Icon icon={cilBell} />
                        Notificar
                    </Link>
                    {props.conversationId !== null ? (
                        <Link href={`/backoffice/chat/${props.conversationId}`} className="btn btn-primary">
                            <Icon icon={cilChatBubble} />
                            Abrir la conversación
                        </Link>
                    ) : null}
                    <ManageCustomer customer={customer} manage={props.manage} />
                </>
            }
        >
            {errors.customer !== undefined ? <Alert tone="error">{errors.customer}</Alert> : null}
            {errors.merge !== undefined ? <Alert tone="error">{errors.merge}</Alert> : null}
            {errors.resend !== undefined ? <Alert tone="warning">{errors.resend}</Alert> : null}

            <ContactStrip customer={customer} />
            <BalanceRow totals={totals} />

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:gap-6">
                <div className="flex min-w-0 flex-col gap-4">
                    <FilterTabs label="Secciones del cliente" tabs={tabs} value={tab} onChange={setTab} />

                    {tab === 'layaways' ? (
                        <>
                            <section aria-label="Apartados activos">
                                <CustomerLayaways layaways={layaways.active} empty="No tiene apartados activos." />
                            </section>
                            {layaways.history.length > 0 ? (
                                <SectionCard
                                    title="Historial"
                                    subtitle={`${layaways.historyTotal} apartados cerrados`}
                                    aria-label={`Historial de apartados (${layaways.historyTotal})`}
                                >
                                    <CustomerLayaways layaways={layaways.history} empty="" />
                                    <More shown={layaways.history.length} total={layaways.historyTotal} url={lists.moreUrl} />
                                </SectionCard>
                            ) : null}
                        </>
                    ) : null}

                    {tab === 'payments' ? (
                        <SectionCard aria-label="Pagos">
                            <PaymentHistory payments={props.payments} empty="Este cliente todavía no tiene pagos." />
                            <More shown={props.payments.length} total={lists.paymentsTotal} url={lists.moreUrl} />
                        </SectionCard>
                    ) : null}

                    {tab === 'receipts' ? (
                        <SectionCard aria-label="Comprobantes">
                            <CustomerReceipts receipts={props.receipts} />
                            <More shown={props.receipts.length} total={lists.receiptsTotal} url={lists.moreUrl} />
                        </SectionCard>
                    ) : null}
                </div>

                <div className="flex flex-col gap-4">
                    {props.subscriptions.length > 0 ? (
                        <SectionCard title="Sigue tus catálogos">
                            <ul className="flex flex-col gap-3">
                                {props.subscriptions.map((subscription) => (
                                    <li key={subscription.name} className="flex items-center gap-3">
                                        <IconChip icon={cilLibrary} tone="neutral" />
                                        <span className="flex min-w-0 flex-col">
                                            <span className="text-sm font-semibold">{subscription.name}</span>
                                            <span className="text-ink-3 text-xs">
                                                {subscription.since !== null
                                                    ? `Desde ${subscription.since.slice(8, 10)}/${subscription.since.slice(5, 7)} · `
                                                    : ''}
                                                {subscription.muted ? 'silenciado' : 'recibe novedades'}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    ) : null}

                    <section aria-label="Fusionar con otra ficha" className="bg-surface-2 border-line flex flex-col gap-2 rounded-2xl border p-5">
                        <h2 className="text-base font-bold">¿Registro duplicado?</h2>
                        <p className="text-ink-2 text-[13px]">
                            Si esta persona apartó con otra cédula o teléfono, puedes unir las dos fichas: se moverán sus apartados, pagos y
                            comprobantes.
                        </p>
                        <MergeCustomerDialog merge={props.merge} />
                    </section>
                </div>
            </div>
        </BackofficeLayout>
    );
}

function More({ shown, total, url }: { shown: number; total: number; url: string | null }) {
    if (total <= shown) {
        return null;
    }

    return (
        <p className="text-ink-3 text-xs">
            Se muestran los {shown} más recientes de {total}.{' '}
            {url !== null ? (
                <Link href={url} preserveScroll preserveState className="text-primary-ink font-semibold hover:underline">
                    Ver más
                </Link>
            ) : null}
        </p>
    );
}
