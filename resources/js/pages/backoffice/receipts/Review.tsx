import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { ApprovalForm } from '@/features/receipts/components/ApprovalForm';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { type ReceiptReviewProps } from '@/types/receipt';
import { Link } from '@inertiajs/react';

/**
 * La revisión de un comprobante (`REQ-PAY-004` RB-6) y su aprobación
 * (`REQ-PAY-005`).
 *
 * En una pantalla, lo que hace falta para decidir: el archivo —que se abre
 * entero en otra pestaña—, lo que declaró el cliente y a qué cuenta dice haber
 * pagado (marcada si está desactivada, SP-8), el cronograma con lo pagado, el
 * saldo, los pagos previos con su tasa congelada y, junto al botón, lo que
 * aplicaría FIFO. Uno ya resuelto se ve igual, con quién lo resolvió y sin
 * formulario.
 *
 * El formulario va en un hijo del marco: su vista previa es una consulta del
 * `QueryClient` que monta `BackofficeLayout`.
 */
export default function ReceiptReview(props: ReceiptReviewProps) {
    const { receipt, layaway } = props;

    return (
        <BackofficeLayout
            title={`Comprobante de ${layaway.code}`}
            description={`${receipt.method} · ${receipt.declared} · pagado el ${formatDay(receipt.paidAt)}`}
        >
            <Link href={props.inboxUrl} className="link text-sm">
                Volver a la bandeja
            </Link>

            <div className="grid gap-4 lg:grid-cols-2">
                <ReceiptPanel {...props} />
                <LayawayPanel {...props} />
            </div>
        </BackofficeLayout>
    );
}

function ReceiptPanel({ receipt, customer }: ReceiptReviewProps) {
    const account = receipt.paymentAccount;

    return (
        <section className="card bg-base-100 shadow-sm" aria-label="Comprobante">
            <div className="card-body gap-3">
                <a href={receipt.file.url} target="_blank" rel="noopener noreferrer" className="link text-sm">
                    Abrir el archivo completo
                </a>
                {receipt.file.kind === 'pdf' ? (
                    <object
                        data={receipt.file.url}
                        type="application/pdf"
                        className="bg-base-200 h-96 w-full rounded"
                        aria-label="Comprobante en PDF"
                    />
                ) : (
                    <img src={receipt.file.url} alt="Captura del comprobante" className="bg-base-200 max-h-[32rem] w-full rounded object-contain" />
                )}

                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt className="opacity-70">Cliente</dt>
                    <dd>
                        {customer === null
                            ? '—'
                            : `${customer.fullName} · ${formatDocumentNumber(customer.documentNumber)} · ${formatPhone(customer.phone)}`}
                    </dd>
                    <dt className="opacity-70">Pago</dt>
                    <dd>
                        {receipt.method}
                        {receipt.bank !== null ? ` · ${receipt.bank} · Ref. ${receipt.reference}` : ''}
                    </dd>
                    <dt className="opacity-70">Cuenta declarada</dt>
                    <dd className="flex flex-wrap items-center gap-2">
                        {account === null
                            ? 'Sin indicar'
                            : `${account.methodLabel}${account.bank !== null ? ` · ${account.bank}` : ''} · ${account.identifier}`}
                        {account !== null && !account.isActive ? (
                            <Badge tone="warning" outline>
                                Cuenta desactivada
                            </Badge>
                        ) : null}
                    </dd>
                    <dt className="opacity-70">Llegó</dt>
                    <dd>{receipt.submittedAt}</dd>
                    {receipt.note !== null ? (
                        <>
                            <dt className="opacity-70">Nota</dt>
                            <dd>{receipt.note}</dd>
                        </>
                    ) : null}
                </dl>
            </div>
        </section>
    );
}

function LayawayPanel({ receipt, layaway, installments, payments, rate, preview, previewError, approval }: ReceiptReviewProps) {
    return (
        <section className="flex flex-col gap-4">
            <div className="card bg-base-100 shadow-sm">
                <div className="card-body gap-3">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <Link href={layaway.url} className="link font-mono">
                            {layaway.code}
                        </Link>
                        <Badge tone="neutral">{layaway.statusLabel}</Badge>
                    </header>
                    <p className="text-sm">{layaway.productName}</p>
                    <p className="text-sm">
                        Pagado <MoneyLabel money={layaway.paid} /> de <MoneyLabel money={layaway.total} /> · falta{' '}
                        <MoneyLabel money={layaway.remaining} />
                    </p>

                    {installments.length === 0 ? (
                        <p className="text-sm opacity-70">
                            Espera la inicial de <MoneyLabel money={layaway.downPayment} />: el cronograma nace al aprobarla.
                        </p>
                    ) : (
                        <table className="table-sm table">
                            <caption className="sr-only">Cronograma</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Cuota</th>
                                    <th scope="col">Vence</th>
                                    <th scope="col">Pagado</th>
                                    <th scope="col">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {installments.map((installment) => (
                                    <tr key={installment.number}>
                                        <td>{installment.number}</td>
                                        <td>{installment.dueDateLabel}</td>
                                        <td>
                                            <MoneyLabel money={installment.paid} /> de <MoneyLabel money={installment.amount} />
                                        </td>
                                        <td>{installment.statusLabel}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            <div className="card bg-base-100 shadow-sm">
                <div className="card-body gap-3">
                    <h2 className="card-title text-base">{approval === null ? receipt.statusLabel : 'Aprobar'}</h2>
                    {approval !== null ? (
                        <ApprovalForm approval={approval} receipt={receipt} rate={rate} initialPreview={preview} initialError={previewError} />
                    ) : receipt.status === 'pending_review' ? (
                        <Alert tone="warning">
                            El apartado está «{layaway.statusLabel}» y ya no admite pagos: este comprobante no se puede aprobar.
                        </Alert>
                    ) : (
                        <Alert tone="info">
                            {receipt.reviewedBy !== null ? `${receipt.statusLabel} por ${receipt.reviewedBy}.` : `${receipt.statusLabel}.`}
                            {receipt.rejectionReason !== null ? ` Motivo: ${receipt.rejectionReason}` : ''}
                        </Alert>
                    )}
                </div>
            </div>

            <div className="card bg-base-100 shadow-sm">
                <div className="card-body gap-3">
                    <h2 className="card-title text-base">Pagos previos</h2>
                    <PaymentHistory payments={payments} empty="Este apartado todavía no tiene pagos." />
                </div>
            </div>
        </section>
    );
}
