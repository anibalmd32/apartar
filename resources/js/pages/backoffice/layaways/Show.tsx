import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { SectionCard } from '@/components/ui/section-card';
import { CancelLayawayDialog } from '@/features/layaway/components/CancelLayawayDialog';
import { LayawayActivity } from '@/features/layaway/components/LayawayActivity';
import { LayawayCustomerCard } from '@/features/layaway/components/LayawayCustomerCard';
import { InstallmentPlan, LayawaySummary } from '@/features/layaway/components/LayawayOverview';
import { AllocationEditor } from '@/features/receipts/components/AllocationEditor';
import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type BackofficeLayawayShowProps } from '@/types/layaway-backoffice';
import { cilPlus } from '@coreui/icons';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function LayawayShow({
    layaway,
    customer,
    installments,
    payments,
    manualPayment,
    reallocation,
    activity,
}: BackofficeLayawayShowProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const delivery = useForm({});
    const [paying, setPaying] = useState(false);

    return (
        <BackofficeLayout
            title={layaway.code}
            badge={<Badge tone={layaway.status === 'cancelled' ? 'neutral' : 'primary'}>{layaway.statusLabel}</Badge>}
            back={{ href: '/backoffice/cobranza', label: 'Cobranza' }}
            description={layaway.createdAt !== null ? `Creado el ${layaway.createdAt}` : undefined}
            actions={
                <>
                    {layaway.cancelUrl !== null ? <CancelLayawayDialog cancelUrl={layaway.cancelUrl} paid={layaway.paid} /> : null}
                    {layaway.deliverUrl !== null ? (
                        <Button loading={delivery.processing} onClick={() => delivery.post(layaway.deliverUrl ?? '', { preserveScroll: true })}>
                            Marcar entregado
                        </Button>
                    ) : null}
                    {manualPayment !== null ? (
                        <Button onClick={() => setPaying(true)}>
                            <Icon icon={cilPlus} />
                            Registrar pago
                        </Button>
                    ) : null}
                </>
            }
        >
            {layaway.cancellationReason !== null ? (
                <Alert tone="info">
                    Cancelado el {layaway.cancelledAt}. Motivo: {layaway.cancellationReason}
                </Alert>
            ) : null}
            {layaway.deliveredAt !== null ? <Alert tone="success">Entregado el {layaway.deliveredAt}.</Alert> : null}
            {errors.delivery ? <Alert tone="error">{errors.delivery}</Alert> : null}

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-6">
                <div className="flex min-w-0 flex-col gap-4">
                    <LayawaySummary layaway={layaway} installments={installments} />
                    {installments.length > 0 ? <InstallmentPlan installments={installments} /> : null}

                    <SectionCard title="Pagos recibidos" aria-label="Pagos">
                        <PaymentHistory payments={payments} empty="Este apartado todavía no tiene pagos." />
                    </SectionCard>

                    {reallocation !== null ? (
                        <SectionCard title="Reasignar un pago" aria-label="Reasignar un pago">
                            <AllocationEditor options={reallocation} />
                        </SectionCard>
                    ) : null}
                </div>

                <div className="flex flex-col gap-4">
                    {customer !== null ? <LayawayCustomerCard customer={customer} /> : null}
                    <LayawayActivity events={activity ?? []} />
                </div>
            </div>

            {manualPayment !== null ? (
                <Modal
                    open={paying}
                    onClose={() => setPaying(false)}
                    title="Registrar pago manual"
                    description={`${layaway.code}${customer !== null ? ` · ${customer.fullName}` : ''}. Úsalo para pagos que recibiste por fuera del portal; puedes adjuntar el comprobante.`}
                >
                    <section aria-label="Registrar un pago">
                        <ManualPaymentForm options={manualPayment} />
                    </section>
                </Modal>
            ) : null}
        </BackofficeLayout>
    );
}
