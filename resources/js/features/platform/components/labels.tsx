import { Badge, type BadgeTone } from '@/components/ui/badge';
import { INVOICE_TONES, ORGANIZATION_TONES, SUBSCRIPTION_TONES, toneOf } from '@/lib/status-tones';

const ORGANIZATION: Record<string, string> = {
    active: 'Activa',
    suspended: 'Suspendida',
    closed: 'Cerrada',
};

const SUBSCRIPTION: Record<string, string> = {
    active: 'Al día',
    past_due: 'Vencida',
    grace: 'En gracia',
    under_review: 'Pago en revisión',
    suspended: 'Suspendida',
    cancelled: 'Cancelada',
};

const INVOICE: Record<string, string> = {
    pending: 'Pendiente',
    under_review: 'En revisión',
    overdue: 'Vencida',
    paid: 'Pagada',
    void: 'Anulada',
};

function StatusBadge({ labels, tones, value }: { labels: Record<string, string>; tones: Readonly<Record<string, BadgeTone>>; value: string }) {
    return <Badge tone={toneOf(tones, value)}>{labels[value] ?? value}</Badge>;
}

export const OrganizationStatusBadge = ({ status }: { status: string }) => (
    <StatusBadge labels={ORGANIZATION} tones={ORGANIZATION_TONES} value={status} />
);
export const SubscriptionStatusBadge = ({ status }: { status: string }) => (
    <StatusBadge labels={SUBSCRIPTION} tones={SUBSCRIPTION_TONES} value={status} />
);
export const InvoiceStatusBadge = ({ status }: { status: string }) => <StatusBadge labels={INVOICE} tones={INVOICE_TONES} value={status} />;

export function BillingBadge({ row }: { row: { subscriptionStatus: string | null; billingStatus?: string | null } }) {
    const status = row.billingStatus ?? row.subscriptionStatus;

    return status === null || status === 'active' ? null : <SubscriptionStatusBadge status={status} />;
}

export function PlanPill({ code }: { code: string | null }) {
    return code === null ? (
        <span className="text-ink-3">—</span>
    ) : (
        <Badge tone="primary" count className="capitalize">
            {code}
        </Badge>
    );
}
