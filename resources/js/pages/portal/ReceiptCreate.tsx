import { Alert } from '@/components/ui/alert';
import { BackLink } from '@/components/ui/back-link';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { PayInstructions } from '@/features/receipts/components/PayInstructions';
import { ReceiptForm } from '@/features/receipts/components/ReceiptForm';
import { ReceiptLayawayCard } from '@/features/receipts/components/ReceiptLayawayCard';
import { useReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import PortalLayout from '@/layouts/portal-layout';
import { formatDate } from '@/lib/dates';
import { afterPaint } from '@/lib/reveal-first-error';
import { type NextPaymentInReview, type PortalSessionView } from '@/types/portal';
import { type ReceiptCreateProps } from '@/types/receipt';
import { cilCheckAlt, cilCloudUpload } from '@coreui/icons';
import { usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

export type ReceiptCreatePageProps = ReceiptCreateProps & { readonly coveredByReview?: NextPaymentInReview | null };

export default function ReceiptCreate(props: ReceiptCreatePageProps) {
    const { business, layaway, suggestion, coveredByReview = null, paymentAccounts, backUrl, replaces } = props;
    const covered = suggestion === null && coveredByReview !== null;
    const { auth } = usePage<{ auth: { portal: PortalSessionView | null } }>().props;
    const form = useReceiptForm(props);
    const [paid, setPaid] = useState(replaces !== null);
    const uploadHeading = useRef<HTMLHeadingElement>(null);
    const open = paid || Object.keys(form.errors).some((field) => field !== 'paymentAccount');

    const startUpload = () => {
        if (paymentAccounts.length > 1 && form.data.paymentAccount === null) {
            form.setError('paymentAccount', 'Elige la cuenta a la que vas a pagar.');

            return;
        }

        form.clearErrors('paymentAccount');
        setPaid(true);
        afterPaint(() => {
            uploadHeading.current?.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
            uploadHeading.current?.focus({ preventScroll: true });
        });
    };

    return (
        <PortalLayout title={`Pagar · ${layaway.code} · ${business.name}`} businessName={business.name} customerName={auth.portal?.fullName ?? ''}>
            <div className="mx-auto flex w-full max-w-[640px] flex-col gap-4 lg:gap-6">
                <BackLink href={backUrl}>Volver al apartado</BackLink>

                <ReceiptLayawayCard layaway={layaway} />

                {replaces !== null ? (
                    <Alert
                        tone="warning"
                        title={`Este comprobante sustituye al que enviaste el ${formatDate(replaces.submittedAt)}, que fue rechazado.`}
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

                <div className="border-line bg-base-100 shadow-card flex flex-col gap-4 rounded-2xl border p-5">
                    <PayInstructions
                        form={form}
                        paymentAccounts={paymentAccounts}
                        suggestion={suggestion}
                        coveredByReview={coveredByReview}
                        businessName={business.name}
                    />

                    {open ? null : (
                        <Button variant={covered ? 'secondary' : 'primary'} size="lg" block onClick={startUpload}>
                            <Icon icon={covered ? cilCloudUpload : cilCheckAlt} size={18} />
                            {covered ? 'Subir otro comprobante' : 'Ya pagué, subir el comprobante'}
                        </Button>
                    )}
                </div>

                {open ? (
                    <section
                        className="border-line bg-base-100 shadow-card flex flex-col gap-3 rounded-2xl border p-5"
                        aria-labelledby="upload-heading"
                    >
                        <header className="flex flex-col gap-1">
                            <h2 id="upload-heading" ref={uploadHeading} tabIndex={-1} className="text-[17px] font-bold outline-none">
                                Sube el comprobante
                            </h2>
                            <p className="text-ink-2 text-[13px]">{business.name} lo revisará y te descontará lo que corresponda.</p>
                        </header>
                        <ReceiptForm {...props} form={form} businessName={business.name} />
                    </section>
                ) : null}
            </div>
        </PortalLayout>
    );
}
