import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { TextField } from '@/components/ui/text-field';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { formatPlanDate } from '@/lib/plan-change';
import { type PlanOption } from '@/types/plan';
import { cilCheckAlt } from '@coreui/icons';
import { useForm } from '@inertiajs/react';

const CHANGE_PLAN_URL = '/backoffice/suscripcion/cambiar';

export function UpgradeModal({
    option,
    open,
    onClose,
    needsDocument,
    timeZone,
}: {
    option: PlanOption;
    open: boolean;
    onClose: () => void;
    needsDocument: boolean;
    timeZone?: string;
}) {
    const plan = usePlanLimits();
    const { data, setData, post, processing, errors } = useForm<{ plan: string; document: string }>({ plan: option.code, document: '' });
    const invoice = option.invoicePreview ?? null;
    const gained = option.preview.gainedFeatures;

    return (
        <Modal
            open={open}
            onClose={onClose}
            size="md"
            title={`Pasar a ${option.name}`}
            description="El cambio es inmediato: los nuevos límites se aplican apenas confirmes."
            actions={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Ahora no
                    </Button>
                    <Button
                        loading={processing}
                        disabled={needsDocument && data.document.trim() === ''}
                        onClick={() => post(CHANGE_PLAN_URL, { preserveScroll: true, onSuccess: onClose })}
                    >
                        Confirmar y ver factura
                    </Button>
                </>
            }
        >
            {gained.length > 0 ? (
                <ul className="flex flex-col gap-1.5 text-[13px]">
                    {gained.map((feature) => (
                        <li key={feature} className="flex items-center gap-2">
                            <Icon icon={cilCheckAlt} size={14} className="text-success" />
                            {plan.featureLabel(feature)}
                        </li>
                    ))}
                </ul>
            ) : null}

            {invoice !== null ? (
                <section aria-label="Factura que se emite hoy" className="bg-surface-2 flex flex-col gap-2 rounded-xl p-4 text-[13px]">
                    <h3 className="text-eyebrow text-ink-3">Factura que se emite hoy</h3>
                    <dl className="flex flex-col gap-1.5">
                        <div className="flex justify-between gap-3">
                            <dt className="text-ink-2">Periodo</dt>
                            <dd className="font-semibold">
                                {formatPlanDate(invoice.periodStart, timeZone)} – {formatPlanDate(invoice.periodEnd, timeZone)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-ink-2">Monto</dt>
                            <dd className="font-semibold">
                                {invoice.amount}
                                {invoice.amountVes !== null ? ` · ${invoice.amountVes}` : ''}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-ink-2">Vence</dt>
                            <dd className="font-semibold">Hoy</dd>
                        </div>
                    </dl>
                    <p className="text-ink-3 text-xs">Pagas por pago móvil o transferencia y subes el comprobante desde Suscripción.</p>
                </section>
            ) : null}

            {needsDocument ? (
                <TextField
                    label="RIF o cédula"
                    value={data.document}
                    onChange={(event) => setData('document', event.target.value)}
                    error={errors.document ?? errors.plan}
                    placeholder="J-41234567-8 o V-12345678"
                    hint="El RIF del negocio o, si no tienes, tu cédula. Lo pedimos una sola vez, antes de tu primera factura."
                />
            ) : errors.plan ? (
                <p className="text-error text-sm">{errors.plan}</p>
            ) : null}
        </Modal>
    );
}
