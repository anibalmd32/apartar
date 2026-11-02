import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { todayIso } from '@/lib/iso-date';
import { type CollectionCustomer, type CollectionInstallmentRow } from '@/types/collections';
import { type MobileLayout } from '@/types/data-table';
import { cilPhone } from '@coreui/icons';
import { type ReactNode } from 'react';

export function DueBadge({ row }: { row: CollectionInstallmentRow }) {
    if (row.daysLate > 0) {
        return <Badge tone="error">{row.daysLate === 1 ? '1 día de atraso' : `${row.daysLate} días de atraso`}</Badge>;
    }

    const today = new Date();
    const start = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    const [year = 0, month = 1, day = 1] = row.dueDate.split('-').map(Number);
    const days = Math.round((Date.UTC(year, month - 1, day) - start) / 86_400_000);

    if (days <= 0) {
        return <Badge tone="warning">Vence hoy</Badge>;
    }

    return days === 1 ? <Badge tone="warning">Mañana</Badge> : <Badge tone="neutral">{`En ${days} días`}</Badge>;
}

export function expiryBadge(expiresAt: string | null): ReactNode {
    if (expiresAt === null) {
        return null;
    }

    const hours = Math.round((new Date(expiresAt).getTime() - Date.now()) / 3_600_000);

    if (hours <= 0) {
        return <Badge tone="error">Plazo vencido</Badge>;
    }

    return hours < 24 ? <Badge tone="warning">{hours === 1 ? 'Queda 1 hora' : `Quedan ${hours} horas`}</Badge> : null;
}

function CallButton({ customer }: { customer: CollectionCustomer }) {
    if (customer.phone === null) {
        return null;
    }

    return (
        <a
            href={`tel:${customer.phone}`}
            className="btn btn-square size-11 min-h-11"
            aria-label={`Llamar a ${customer.name} al ${customer.phoneLabel ?? customer.phone}`}
        >
            <Icon icon={cilPhone} size={18} />
        </a>
    );
}

export function customerMobile<TRow extends { customer: CollectionCustomer | null }>(): MobileLayout<TRow> {
    return {
        media: (row) => <Avatar name={row.customer?.name ?? '—'} size={40} tone={row.customer ? 'accent' : 'neutral'} />,
        title: (row) => row.customer?.name ?? 'Sin cliente',
        action: (row) => (row.customer ? <CallButton customer={row.customer} /> : null),
    };
}

export function dueMeta(day: string | null, prefix: string): string | null {
    return day === null ? null : `${prefix} ${formatDay(day.slice(0, 10))}`;
}

export function dueDateMeta(day: string | null): string | null {
    if (day === null) {
        return null;
    }

    const date = day.slice(0, 10);

    return `${date < todayIso() ? 'Venció' : 'Vence'} el ${formatDay(date)}`;
}
