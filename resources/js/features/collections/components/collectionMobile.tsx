import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { formatDate } from '@/lib/dates';
import { todayIso } from '@/lib/iso-date';
import { type CollectionCustomer, type CollectionInstallmentRow } from '@/types/collections';
import { type MobileLayout } from '@/types/data-table';
import { cilChatBubble, cilMoney, cilPhone } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { WhatsAppReminder } from './WhatsAppReminder';

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

export const ICON_ACTION = 'btn btn-square size-11 min-h-11';

function CallButton({ customer }: { customer: CollectionCustomer }) {
    if (customer.phone === null) {
        return null;
    }

    return (
        <a
            href={`tel:${customer.phone}`}
            className={ICON_ACTION}
            aria-label={`Llamar a ${customer.name} al ${customer.phoneLabel ?? customer.phone}`}
        >
            <Icon icon={cilPhone} size={18} />
        </a>
    );
}

function ChatButton({ customer }: { customer: CollectionCustomer }) {
    if (!customer.chatUrl) {
        return null;
    }

    return (
        <Link href={customer.chatUrl} className={ICON_ACTION} aria-label={`Escribir a ${customer.name} por el chat`}>
            <Icon icon={cilChatBubble} size={18} />
        </Link>
    );
}

export function PaymentLink({ layaway }: { layaway: { code: string; url: string } }) {
    return (
        <Link href={layaway.url} className={`${ICON_ACTION} btn-primary`} aria-label={`Registrar un pago de ${layaway.code}`}>
            <Icon icon={cilMoney} size={18} />
        </Link>
    );
}

export function customerMobile<
    TRow extends { customer: CollectionCustomer | null; pending?: { cents: number }; daysLate?: number },
>(): MobileLayout<TRow> {
    return {
        media: (row) => <Avatar name={row.customer?.name ?? '—'} size={40} tone={row.customer ? 'accent' : 'neutral'} />,
        title: (row) => row.customer?.name ?? 'Sin cliente',
        action: (row) =>
            row.customer === null ? null : (
                <>
                    {row.pending !== undefined ? (
                        <WhatsAppReminder customer={row.customer} dueCents={Number(row.pending.cents)} daysLate={row.daysLate ?? 0} iconOnly />
                    ) : null}
                    <ChatButton customer={row.customer} />
                    <CallButton customer={row.customer} />
                </>
            ),
    };
}

export function dueMeta(day: string | null, prefix: string): string | null {
    return day === null ? null : `${prefix} ${formatDate(day.slice(0, 10))}`;
}

export function dueDateMeta(day: string | null): string | null {
    if (day === null) {
        return null;
    }

    const date = day.slice(0, 10);

    return `${date < todayIso() ? 'Venció' : 'Vence'} el ${formatDate(date)}`;
}
