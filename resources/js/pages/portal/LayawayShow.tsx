import { Icon } from '@/components/ui/icon';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { PortalLayawayHero } from '@/features/layaway/components/PortalLayawayHero';
import { PortalNextPayment } from '@/features/layaway/components/PortalNextPayment';
import { ScheduleTable } from '@/features/layaway/components/ScheduleTable';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { ReceiptHistory } from '@/features/receipts/components/ReceiptHistory';
import PortalLayout from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import { type PortalLayawayShowProps } from '@/types/portal';
import { cilArrowLeft, cilLibrary, cilWallet } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

const card = 'border-line bg-base-100 shadow-card rounded-2xl border';

export default function LayawayShow({
    business,
    layaway,
    installments,
    nextPaymentSuggestion,
    paymentAccounts,
    catalogUrl,
    receiptUrl,
    payments,
    receipts,
}: PortalLayawayShowProps) {
    const { auth } = usePage<{ auth: { portal: { fullName: string } | null } }>().props;
    const pending = layaway.status === 'pending_down_payment';

    return (
        <PortalLayout title={`Apartado ${layaway.code} · ${business.name}`} businessName={business.name} customerName={auth.portal?.fullName ?? ''}>
            <Link
                href={route('portal.dashboard')}
                className="text-ink-3 hover:text-base-content flex items-center gap-1.5 self-start text-[13px] font-semibold"
            >
                <Icon icon={cilArrowLeft} size={14} />
                Mis apartados
            </Link>

            <div className="flex flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,1fr)_400px] lg:items-start lg:gap-5">
                <div className="contents lg:flex lg:flex-col lg:gap-4">
                    <PortalLayawayHero layaway={layaway} businessName={business.name} className="order-1" />

                    {pending && Number(layaway.downPayment.cents) === 0 ? (
                        <section className={cn(card, 'order-3 flex flex-col gap-2 p-5')} aria-label="Inicial">
                            <h2 className="text-[17px] font-bold">Este apartado no lleva inicial</h2>
                            <p className="text-ink-2 text-sm">
                                Lo pagas solo en cuotas, pero tu primer pago lo activa
                                {layaway.expiresAt !== null ? <>: hazlo antes del {layaway.expiresAt} o el apartado se cancela.</> : '.'} Cuando{' '}
                                {business.name} lo apruebe, verás aquí tu cronograma de cuotas.
                            </p>
                        </section>
                    ) : null}

                    {pending && Number(layaway.downPayment.cents) > 0 ? (
                        <section className={cn(card, 'order-3 flex flex-col gap-2 p-5')} aria-label="Inicial">
                            <h2 className="text-[17px] font-bold">Paga la inicial para confirmar tu apartado</h2>
                            <p className="text-ink-2 text-sm">
                                La inicial es de <MoneyLabel money={layaway.downPayment} className="text-base-content font-semibold" />
                                {layaway.expiresAt !== null ? <> y tienes hasta el {layaway.expiresAt} para pagarla.</> : '.'} Cuando {business.name}{' '}
                                la apruebe, verás aquí tu cronograma de cuotas.
                            </p>
                        </section>
                    ) : null}

                    {!pending && installments.length > 0 ? (
                        <section className={cn(card, 'order-3 flex flex-col px-5 pt-4 pb-1.5')}>
                            <h2 className="text-[17px] font-bold">Tus cuotas</h2>
                            <ScheduleTable installments={installments} />
                        </section>
                    ) : null}

                    <section className={cn(card, 'order-5 flex flex-col gap-3 p-5')} aria-label="Historial">
                        <h2 className="text-[17px] font-bold">Pagos y comprobantes</h2>
                        <h3 className="text-eyebrow text-ink-3">Tus pagos</h3>
                        <PaymentHistory payments={payments} empty="Todavía no hay pagos acreditados en este apartado." />
                        <h3 className="text-eyebrow text-ink-3 mt-2">Tus comprobantes</h3>
                        <ReceiptHistory receipts={receipts} />
                    </section>
                </div>

                <aside className="contents lg:sticky lg:top-[92px] lg:flex lg:flex-col lg:gap-4">
                    {nextPaymentSuggestion !== null ? (
                        <PortalNextPayment suggestion={nextPaymentSuggestion} receiptUrl={receiptUrl} className="order-2" />
                    ) : null}

                    {layaway.acceptsPayments ? (
                        <section className="bg-surface-2 order-4 flex flex-col gap-2.5 rounded-[14px] p-4" aria-label="Dónde pagar">
                            <h2 className="flex items-center gap-2 text-[15px] font-bold">
                                <Icon icon={cilWallet} />
                                Dónde pagarle a {business.name}
                            </h2>
                            <div className="[&_li]:bg-base-100">
                                <PaymentAccountList accounts={paymentAccounts} businessName={business.name} />
                            </div>
                        </section>
                    ) : null}

                    {catalogUrl !== null ? (
                        <Link href={catalogUrl} className="btn btn-ghost order-6 self-start">
                            <Icon icon={cilLibrary} />
                            Ver el catálogo de {business.name}
                        </Link>
                    ) : null}
                </aside>
            </div>
        </PortalLayout>
    );
}
