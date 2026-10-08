import { Alert } from '@/components/ui/alert';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { ReceiptForm } from '@/features/receipts/components/ReceiptForm';
import { useReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalSessionView } from '@/types/portal';
import { type ReceiptCreateProps } from '@/types/receipt';
import { Link, usePage } from '@inertiajs/react';

/**
 * Reportar un pago desde el portal (`REQ-PAY-001`).
 *
 * Llega desde «Subir comprobante» en el detalle del apartado. Arriba, de qué
 * apartado se trata y cuánto falta, para no reportar el pago en el que no es;
 * debajo, el formulario. El comprobante nace en revisión y no toca el saldo
 * (RB-6): tras enviarlo, el servidor vuelve al detalle con un aviso, sin nada
 * que hacer aquí.
 */
export default function ReceiptCreate({
    business,
    layaway,
    suggestion,
    paymentAccounts,
    methods,
    currencies,
    paidAt,
    file,
    storeUrl,
    backUrl,
    replaces,
}: ReceiptCreateProps) {
    const { auth } = usePage<{ auth: { portal: PortalSessionView | null } }>().props;

    const form = useReceiptForm({ paymentAccounts, methods, currencies, suggestion, paidAt, file, storeUrl });

    return (
        <PortalLayout
            title={`Comprobante · ${layaway.code} · ${business.name}`}
            businessName={business.name}
            customerName={auth.portal?.fullName ?? ''}
        >
            <Link href={backUrl} className="link link-hover text-sm">
                ← Volver al apartado
            </Link>

            <header className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold">Reportar un pago</h1>
                <p className="text-sm opacity-70">Sube la captura de tu pago a {business.name}. Lo revisarán y te descontarán lo que corresponda.</p>
            </header>

            {replaces !== null ? (
                <Alert tone="warning" className="flex-col items-start gap-1">
                    <span>Este comprobante sustituye al que enviaste el {formatDay(replaces.submittedAt)}, que fue rechazado.</span>
                    {replaces.reason !== null ? <span>Motivo: {replaces.reason}</span> : null}
                    <span className="text-sm">
                        Si es el mismo pago, con la misma referencia, no podrás reportarlo otra vez: escríbele a {business.name} para que lo registre.
                    </span>
                </Alert>
            ) : null}

            <section className="card bg-base-100 shadow-sm" aria-label="Apartado">
                <div className="card-body gap-1 p-4">
                    <div className="flex items-start justify-between gap-2">
                        <p className="font-semibold">{layaway.productName}</p>
                        <span className="badge badge-ghost shrink-0">{layaway.statusLabel}</span>
                    </div>
                    <p className="font-mono text-sm">{layaway.code}</p>
                    <p className="text-sm">
                        Te falta: <MoneyLabel money={layaway.remaining} className="font-semibold" />
                    </p>
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm" aria-label="Tu pago">
                <div className="card-body p-4">
                    <ReceiptForm
                        form={form}
                        paymentAccounts={paymentAccounts}
                        methods={methods}
                        currencies={currencies}
                        suggestion={suggestion}
                        paidAt={paidAt}
                        file={file}
                        businessName={business.name}
                    />
                </div>
            </section>
        </PortalLayout>
    );
}
