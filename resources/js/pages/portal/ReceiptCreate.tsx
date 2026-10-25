import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { ReceiptForm } from '@/features/receipts/components/ReceiptForm';
import { useReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalSessionView } from '@/types/portal';
import { type ReceiptCreateProps } from '@/types/receipt';
import { cilBasket } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

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
            <div className="flex w-full max-w-[640px] flex-col gap-4 lg:gap-6">
                <Link href={backUrl} className="text-ink-3 hover:text-base-content self-start text-[13px] font-semibold">
                    ← Volver al apartado
                </Link>

                <header className="flex flex-col gap-1">
                    <h1 className="text-[24px] leading-tight font-extrabold tracking-[-0.6px] lg:text-[30px]">Subir comprobante</h1>
                    <p className="text-ink-2 text-sm">
                        Sube la captura de tu pago a {business.name}. Lo revisarán y te descontarán lo que corresponda.
                    </p>
                </header>

                <section className="border-line bg-base-100 flex flex-wrap items-center gap-3 rounded-[14px] border p-3.5" aria-label="Apartado">
                    <span aria-hidden="true" className="bg-surface-2 text-ink-3 flex size-11 shrink-0 items-center justify-center rounded-lg">
                        <Icon icon={cilBasket} size={18} />
                    </span>
                    <div className="flex min-w-0 flex-1 basis-48 flex-col gap-px">
                        <p className="line-clamp-2 text-sm font-bold">
                            {layaway.productName} · {layaway.code}
                        </p>
                        <p className="text-ink-3 text-xs">
                            Te falta: <MoneyLabel money={layaway.remaining} className="text-base-content font-semibold" />
                        </p>
                    </div>
                    <Badge className="shrink-0 max-sm:ml-14">{layaway.statusLabel}</Badge>
                </section>

                {replaces !== null ? (
                    <Alert
                        tone="warning"
                        title={`Este comprobante sustituye al que enviaste el ${formatDay(replaces.submittedAt)}, que fue rechazado.`}
                    >
                        <span className="flex flex-col gap-1 text-[13px]">
                            {replaces.reason !== null ? <span>Motivo: {replaces.reason}</span> : null}
                            <span>
                                Si es el mismo pago, con la misma referencia, no podrás reportarlo otra vez: escríbele a {business.name} para que lo
                                registre.
                            </span>
                        </span>
                    </Alert>
                ) : null}

                <section className="border-line bg-base-100 shadow-card rounded-2xl border p-5" aria-label="Tu pago">
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
                </section>
            </div>
        </PortalLayout>
    );
}
