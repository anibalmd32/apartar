import { Alert } from '@/components/ui/alert';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { ScheduleTable } from '@/features/layaway/components/ScheduleTable';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { ReceiptHistory } from '@/features/receipts/components/ReceiptHistory';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalLayawayShowProps } from '@/types/portal';
import { Link, usePage } from '@inertiajs/react';

/**
 * El detalle de un apartado en el portal (`REQ-LAY-021`).
 *
 * Dice **de qué negocio** es (RB-2), lo que se apartó y cómo va el pago
 * (RB-3), y después, según el estado:
 *
 *  - esperando la inicial, cuánto es y hasta cuándo, en lugar del cronograma,
 *    que todavía no existe (RB-6);
 *  - vivo, el cronograma con sus días (RB-4, RB-5) y el próximo pago sugerido
 *    (RB-9);
 *  - terminal, solo lectura, con un texto neutro (SP-3, SP-4).
 *
 * Las cuentas de cobro aparecen siempre que el apartado acepte pagos (RB-7).
 * Al final, el historial de pagos y de comprobantes (`REQ-LAY-022`), en
 * cualquier estado: un apartado cancelado también tiene pagos que reclamar.
 */
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
            <Link href={route('portal.dashboard')} className="link link-hover text-sm">
                ← Mis apartados
            </Link>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-3 p-4">
                    <p className="text-sm opacity-70">Apartado en {business.name}</p>
                    <div className="flex items-start justify-between gap-2">
                        <h1 className="text-xl font-semibold">
                            {layaway.quantity > 1 ? `${layaway.quantity} × ` : ''}
                            {layaway.productName}
                        </h1>
                        <span className="badge badge-ghost shrink-0">{layaway.statusLabel}</span>
                    </div>
                    <p className="font-mono text-sm">{layaway.code}</p>

                    <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                        <Amount label="Total" money={layaway.total} />
                        <Amount label="Inicial" money={layaway.downPayment} />
                        <Amount label="Pagado" money={layaway.paid} />
                        <Amount label="Te falta" money={layaway.remaining} />
                    </dl>

                    {layaway.statusMessage !== null ? <Alert tone="info">{layaway.statusMessage}</Alert> : null}
                </div>
            </section>

            {pending ? (
                <section className="card bg-base-100 shadow-sm" aria-label="Inicial">
                    <div className="card-body gap-2 p-4">
                        <h2 className="font-semibold">Paga la inicial para confirmar tu apartado</h2>
                        <p className="text-sm">
                            La inicial es de <MoneyLabel money={layaway.downPayment} />
                            {layaway.expiresAt !== null ? <> y tienes hasta el {layaway.expiresAt} para pagarla.</> : '.'} Cuando {business.name} la
                            apruebe, verás aquí tu cronograma de cuotas.
                        </p>
                    </div>
                </section>
            ) : null}

            {!pending && installments.length > 0 ? (
                <section className="card bg-base-100 shadow-sm">
                    <div className="card-body gap-3 p-4">
                        <h2 className="font-semibold">Cronograma</h2>
                        <ScheduleTable installments={installments} />
                    </div>
                </section>
            ) : null}

            {nextPaymentSuggestion !== null ? (
                <section className="card bg-base-100 shadow-sm" aria-label="Próximo pago">
                    <div className="card-body gap-2 p-4">
                        <h2 className="font-semibold">Tu próximo pago</h2>
                        <p className="text-sm">
                            {nextPaymentSuggestion.kind === 'down_payment' ? 'La inicial: ' : `La cuota ${nextPaymentSuggestion.installmentNumber}: `}
                            <MoneyLabel money={nextPaymentSuggestion.amount} className="font-semibold" />
                        </p>
                        <p className="text-sm opacity-70">
                            Si prefieres pagarlo todo de una vez: <MoneyLabel money={nextPaymentSuggestion.payOff} />
                        </p>
                        {/* `REQ-LAY-008` RB-1, RB-2 · sin descuento, y el equivalente en bolívares se fija al pagar. */}
                        <p className="text-xs opacity-70">
                            Sin descuento por adelantar.
                            {nextPaymentSuggestion.payOff.ves !== null
                                ? ' El monto en bolívares es a la tasa de hoy y puede variar el día que pagues.'
                                : null}
                        </p>
                        {receiptUrl !== null ? (
                            <Link href={receiptUrl} className="btn btn-primary btn-block mt-2">
                                Subir comprobante
                            </Link>
                        ) : null}
                    </div>
                </section>
            ) : null}

            {layaway.acceptsPayments ? (
                <section className="card bg-base-100 shadow-sm" aria-label="Dónde pagar">
                    <div className="card-body gap-3 p-4">
                        <h2 className="font-semibold">Dónde pagarle a {business.name}</h2>
                        <PaymentAccountList accounts={paymentAccounts} businessName={business.name} />
                    </div>
                </section>
            ) : null}

            <section className="card bg-base-100 shadow-sm" aria-label="Historial">
                <div className="card-body gap-3 p-4">
                    <h2 className="font-semibold">Tus pagos</h2>
                    <PaymentHistory payments={payments} empty="Todavía no hay pagos acreditados en este apartado." />
                    <h2 className="font-semibold">Tus comprobantes</h2>
                    <ReceiptHistory receipts={receipts} />
                </div>
            </section>

            {catalogUrl !== null ? (
                <Link href={catalogUrl} className="link link-hover text-sm">
                    Ver el catálogo de {business.name}
                </Link>
            ) : null}
        </PortalLayout>
    );
}

function Amount({ label, money }: { label: string; money: PortalLayawayShowProps['layaway']['total'] }) {
    return (
        <div>
            <dt className="opacity-70">{label}</dt>
            <dd className="font-semibold">
                <MoneyLabel money={money} />
            </dd>
        </div>
    );
}
