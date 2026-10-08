import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { CancelLayawayDialog } from '@/features/layaway/components/CancelLayawayDialog';
import { RescheduleInstallmentDialog } from '@/features/layaway/components/RescheduleInstallmentDialog';
import { AllocationEditor } from '@/features/receipts/components/AllocationEditor';
import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type BackofficeLayawayShowProps } from '@/types/layaway-backoffice';
import { useForm, usePage } from '@inertiajs/react';

/**
 * Un apartado visto por el negocio (`REQ-LAY-009`, `REQ-LAY-010`,
 * `REQ-LAY-011`).
 *
 * La vista mínima que necesitan las tres operaciones de `T-053`: la ficha, el
 * cronograma y sus botones. Cada botón aparece solo si el servidor mandó su
 * URL, que es como dice que la operación es posible; quien decide de verdad es
 * la acción, bajo el bloqueo del apartado. Los listados de cobranza que traen
 * hasta aquí son de `T-065`.
 */
export default function LayawayShow({ layaway, customer, installments, payments, manualPayment, reallocation }: BackofficeLayawayShowProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const delivery = useForm({});

    return (
        <BackofficeLayout title={`Apartado ${layaway.code}`}>
            <section className="card bg-base-100 shadow-sm" aria-label="Resumen del apartado">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h1 className="font-mono text-xl font-semibold">{layaway.code}</h1>
                            <p className="text-sm opacity-70">
                                {layaway.quantity > 1 ? `${layaway.quantity} × ` : ''}
                                {layaway.productName}
                            </p>
                        </div>
                        <span className="badge badge-outline">{layaway.statusLabel}</span>
                    </header>

                    {customer !== null ? (
                        <p className="text-sm">
                            {customer.fullName} · {customer.documentNumber} · {customer.phone}
                        </p>
                    ) : null}

                    <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <Amount label="Total" money={layaway.total} />
                        <Amount label="Inicial" money={layaway.downPayment} />
                        <Amount label="Pagado" money={layaway.paid} />
                        <Amount label="Pendiente" money={layaway.remaining} />
                    </dl>

                    {layaway.cancellationReason !== null ? (
                        <Alert tone="info">
                            <span>
                                Cancelado el {layaway.cancelledAt}. Motivo: {layaway.cancellationReason}
                            </span>
                        </Alert>
                    ) : null}

                    {layaway.deliveredAt !== null ? <p className="text-sm">Entregado el {layaway.deliveredAt}.</p> : null}

                    {errors.delivery ? <Alert tone="error">{errors.delivery}</Alert> : null}

                    {layaway.cancelUrl !== null || layaway.deliverUrl !== null ? (
                        <div className="flex flex-wrap gap-2">
                            {layaway.deliverUrl !== null ? (
                                <Button
                                    loading={delivery.processing}
                                    onClick={() => delivery.post(layaway.deliverUrl ?? '', { preserveScroll: true })}
                                >
                                    Marcar entregado
                                </Button>
                            ) : null}
                            {layaway.cancelUrl !== null ? <CancelLayawayDialog cancelUrl={layaway.cancelUrl} paid={layaway.paid} /> : null}
                        </div>
                    ) : null}
                </div>
            </section>

            {installments.length > 0 ? (
                <section className="card bg-base-100 shadow-sm" aria-label="Cronograma">
                    <div className="card-body gap-3">
                        <h2 className="text-lg font-semibold">Cronograma</h2>
                        <ol className="flex flex-col gap-2">
                            {installments.map((installment) => (
                                <li
                                    key={installment.number}
                                    className="border-base-300 flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                                >
                                    <span className="flex flex-col">
                                        <span className="font-medium">
                                            Cuota {installment.number} · {installment.dueDateLabel}
                                        </span>
                                        <span className="text-sm opacity-70">
                                            <MoneyLabel money={installment.amount} /> · pagado <MoneyLabel money={installment.paid} /> ·{' '}
                                            {installment.statusLabel}
                                        </span>
                                    </span>
                                    {installment.reschedule !== null ? (
                                        <RescheduleInstallmentDialog installment={installment} option={installment.reschedule} />
                                    ) : null}
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>
            ) : null}

            {manualPayment !== null ? (
                <section className="card bg-base-100 shadow-sm" aria-label="Registrar un pago">
                    <div className="card-body gap-3">
                        <h2 className="card-title text-base">Registrar un pago</h2>
                        <ManualPaymentForm options={manualPayment} />
                    </div>
                </section>
            ) : null}

            <section className="card bg-base-100 shadow-sm" aria-label="Pagos">
                <div className="card-body gap-3">
                    <h2 className="card-title text-base">Pagos</h2>
                    <PaymentHistory payments={payments} empty="Este apartado todavía no tiene pagos." />
                </div>
            </section>

            {reallocation !== null ? (
                <section className="card bg-base-100 shadow-sm" aria-label="Reasignar un pago">
                    <div className="card-body gap-3">
                        <h2 className="card-title text-base">Reasignar un pago</h2>
                        <AllocationEditor options={reallocation} />
                    </div>
                </section>
            ) : null}
        </BackofficeLayout>
    );
}

function Amount({ label, money }: { label: string; money: BackofficeLayawayShowProps['layaway']['total'] }) {
    return (
        <div>
            <dt className="opacity-70">{label}</dt>
            <dd className="font-semibold">
                <MoneyLabel money={money} />
            </dd>
        </div>
    );
}
