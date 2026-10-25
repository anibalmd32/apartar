import { Badge, type BadgeTone } from '@/components/ui/badge';
import { IconChip } from '@/components/ui/icon-chip';
import { formatDay } from '@/features/subscription/components/InvoiceTable';
import { formatPlanDate } from '@/lib/plan-change';
import { type PlanState } from '@/types/plan';
import { cilDiamond } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export interface SubscriptionPeriod {
    readonly start: string;
    readonly end: string;
    readonly price: string;
}

export interface NextInvoice {
    readonly number: string;
    readonly amount: string;
    readonly dueDate: string;
    readonly payUrl: string;
}

const STATUS_LABELS = {
    active: 'Al día',
    past_due: 'Pago pendiente',
    grace: 'Cambio de plan programado',
    suspended: 'Suspendida',
    cancelled: 'Cancelada',
} as const;

const STATUS_TONES: Record<keyof typeof STATUS_LABELS, BadgeTone> = {
    active: 'success',
    past_due: 'warning',
    grace: 'info',
    suspended: 'error',
    cancelled: 'neutral',
};

export function PlanSummary({
    state,
    pricingUrl,
    period,
    nextInvoice,
    timeZone,
}: {
    state: PlanState;
    pricingUrl: string;
    period: SubscriptionPeriod | null;
    nextInvoice: NextInvoice | null;
    timeZone: string;
}) {
    return (
        <section
            aria-label={`Plan ${state.planName}`}
            className="bg-base-100 border-line shadow-card flex flex-wrap items-center gap-4 rounded-2xl border p-5"
        >
            <IconChip icon={cilDiamond} tone="primary" size={48} className="rounded-xl" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="flex flex-wrap items-center gap-2">
                    <span className="text-xl font-bold tracking-[-0.3px]">Plan {state.planName}</span>
                    <Badge tone={STATUS_TONES[state.status]}>{STATUS_LABELS[state.status]}</Badge>
                </p>
                {period !== null ? (
                    <p className="text-ink-2 text-[13px]">
                        Periodo actual: {formatPlanDate(period.start, timeZone).slice(0, 5)} – {formatPlanDate(period.end, timeZone)} · {period.price}{' '}
                        al mes
                    </p>
                ) : null}
                <a className="text-primary-ink self-start text-[13px] font-semibold hover:underline" href={pricingUrl}>
                    Compara los planes
                </a>
            </div>
            {nextInvoice !== null ? (
                <div className="bg-surface-2 flex flex-wrap items-center gap-4 rounded-xl px-4 py-3">
                    <div className="flex flex-col">
                        <span className="text-ink-3 text-xs font-semibold">Próxima factura</span>
                        <span className="text-[15px] font-bold">
                            {nextInvoice.amount} · vence el {formatDay(nextInvoice.dueDate)}
                        </span>
                    </div>
                    <Link className="btn btn-primary btn-sm" href={nextInvoice.payUrl}>
                        Pagar ahora
                    </Link>
                </div>
            ) : null}
        </section>
    );
}
