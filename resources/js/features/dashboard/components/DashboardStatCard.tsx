import { StatCard } from '@/components/ui/stat-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type DashboardCard, type DashboardProps } from '@/types/dashboard';
import { cilAvTimer, cilCash, cilChatBubble, cilDescription, cilTruck, cilWarning } from '@coreui/icons';

export const CARD_LABELS: Record<DashboardCard, string> = {
    pendingReceipts: 'Comprobantes por revisar',
    collectedThisMonth: 'Cobrado este mes',
    customersOverdue: 'Clientes en mora',
    longOverdue: 'Mora prolongada',
    completedNotDelivered: 'Pagados sin entregar',
    unreadMessages: 'Chats sin leer',
};

interface DashboardStatCardProps {
    id: DashboardCard;
    metrics: DashboardProps['metrics'];
    links: DashboardProps['links'];
    longOverdueDays: number;
}

export function DashboardStatCard({ id, metrics, links, longOverdueDays }: DashboardStatCardProps) {
    const label = CARD_LABELS[id];

    switch (id) {
        case 'pendingReceipts':
            return (
                <StatCard label={label} icon={cilDescription} tone="primary" href={links.receipts} value={metrics.pendingReceipts} className="h-full">
                    {metrics.overdueReceiptsCount !== null && metrics.overdueReceiptsCount > 0 ? (
                        <>
                            <span className="badge badge-error badge-count">{metrics.overdueReceiptsCount}</span> con más de 48 h
                        </>
                    ) : null}
                </StatCard>
            );
        case 'collectedThisMonth':
            return (
                <StatCard
                    label={label}
                    icon={cilCash}
                    tone="success"
                    className="h-full"
                    value={metrics.collectedThisMonth === null ? null : <MoneyLabel money={metrics.collectedThisMonth} stacked />}
                />
            );
        case 'customersOverdue':
            return (
                <StatCard
                    label={label}
                    icon={cilWarning}
                    tone="error"
                    href={links.customersOverdue}
                    value={metrics.customersOverdue}
                    className="h-full"
                />
            );
        case 'longOverdue':
            return (
                <StatCard
                    label={label}
                    icon={cilAvTimer}
                    tone="warning"
                    href={links.longOverdue}
                    value={metrics.longOverdueLayaways}
                    className="h-full"
                >
                    {metrics.longOverdueLayaways
                        ? 'Requieren seguimiento'
                        : `Más de ${longOverdueDays} ${longOverdueDays === 1 ? 'día' : 'días'} tras la gracia`}
                </StatCard>
            );
        case 'completedNotDelivered':
            return (
                <StatCard
                    label={label}
                    icon={cilTruck}
                    tone="neutral"
                    href={links.completedNotDelivered}
                    value={metrics.completedNotDelivered}
                    className="h-full"
                >
                    {metrics.completedNotDelivered ? 'Listos para entregar' : null}
                </StatCard>
            );
        case 'unreadMessages':
            return (
                <StatCard
                    label={label}
                    icon={cilChatBubble}
                    tone="primary"
                    href="/backoffice/chat"
                    value={metrics.unreadMessages}
                    className="h-full"
                />
            );
    }
}
