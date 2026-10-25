import { Badge, type BadgeTone } from '@/components/ui/badge';

type Entry = readonly [label: string, tone: BadgeTone];

const ORGANIZATION: Record<string, Entry> = {
    active: ['Activa', 'success'],
    suspended: ['Suspendida', 'error'],
    closed: ['Cerrada', 'neutral'],
};

const SUBSCRIPTION: Record<string, Entry> = {
    active: ['Al día', 'success'],
    past_due: ['Vencida', 'warning'],
    grace: ['En gracia', 'warning'],
    suspended: ['Suspendida', 'error'],
    cancelled: ['Cancelada', 'neutral'],
};

const INVOICE: Record<string, Entry> = {
    pending: ['Pendiente', 'info'],
    under_review: ['En revisión', 'warning'],
    overdue: ['Vencida', 'error'],
    paid: ['Pagada', 'success'],
    void: ['Anulada', 'neutral'],
};

function StatusBadge({ map, value }: { map: Record<string, Entry>; value: string }) {
    const [label, tone] = map[value] ?? [value, 'neutral'];

    return <Badge tone={tone}>{label}</Badge>;
}

export const OrganizationStatusBadge = ({ status }: { status: string }) => <StatusBadge map={ORGANIZATION} value={status} />;
export const SubscriptionStatusBadge = ({ status }: { status: string }) => <StatusBadge map={SUBSCRIPTION} value={status} />;
export const InvoiceStatusBadge = ({ status }: { status: string }) => <StatusBadge map={INVOICE} value={status} />;

export function PlanPill({ code }: { code: string | null }) {
    return code === null ? (
        <span className="text-ink-3">—</span>
    ) : (
        <Badge tone="primary" count className="capitalize">
            {code}
        </Badge>
    );
}
