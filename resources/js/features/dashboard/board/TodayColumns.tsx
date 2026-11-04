import { formatAge } from '@/features/receipts/lib/inbox-format';
import { formatBsCents, formatUsdCents } from '@/lib/cents';
import { bo } from '@/lib/surfaces';
import { cn } from '@/lib/utils';
import { type DashboardCollectToday, type DashboardMetrics, type DashboardPendingReceipt, type DashboardProps } from '@/types/dashboard';
import { Link } from '@inertiajs/react';
import { BoardColumn, BoardRow, EmptyNote, ROW_ACTION } from './BoardColumn';
import { reminderUrl } from './reminder';

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

export function ReviewColumn({ metrics, receipts, href }: { metrics: DashboardMetrics; receipts: readonly DashboardPendingReceipt[]; href: string }) {
    const count = metrics.pendingReceipts;
    const late = metrics.overdueReceiptsCount ?? 0;
    const now = Date.now();

    return (
        <BoardColumn
            id="por-revisar"
            label="Por revisar"
            tone="blue"
            figure={count ?? '—'}
            detail={
                count === null
                    ? 'No pudimos contar los comprobantes.'
                    : `${count === 1 ? 'comprobante' : 'comprobantes'}${late > 0 ? ` · ${late} con más de 48 h` : ''}`
            }
            more={count !== null && count > receipts.length ? { href, label: 'Ver todos los comprobantes' } : undefined}
        >
            {receipts.length === 0 ? (
                <EmptyNote>Nada por revisar.</EmptyNote>
            ) : (
                receipts.map((receipt) => (
                    <BoardRow
                        key={receipt.id}
                        title={receipt.customerName ?? 'Cliente sin ficha'}
                        meta={
                            <span className={cn(receipt.overdue && 'text-warning font-semibold')}>
                                {receipt.methodLabel}
                                {receipt.submittedAt !== null ? ` · ${formatAge(receipt.submittedAt, now)}` : ''}
                            </span>
                        }
                        amount={receipt.declared}
                        action={
                            <Link href={receipt.reviewUrl} className={cn(ROW_ACTION, 'btn-primary')}>
                                Revisar
                            </Link>
                        }
                    />
                ))
            )}
        </BoardColumn>
    );
}

interface CollectColumnProps {
    collect: DashboardCollectToday;
    rate: number | null;
    business: string;
    portalUrl: string;
}

export function CollectColumn({ collect, rate, business, portalUrl }: CollectColumnProps) {
    const detail =
        collect.customers === 0
            ? 'Nadie tiene cuotas vencidas ni que venzan hoy.'
            : `${plural(collect.customers, 'cliente', 'clientes')}${collect.late > 0 ? ` · ${plural(collect.late, 'atrasado', 'atrasados')}` : ''}${rate !== null ? ` · ${formatBsCents(collect.totalCents, rate)}` : ''}`;

    return (
        <BoardColumn
            id="por-cobrar"
            label="Por cobrar hoy"
            tone={collect.late > 0 ? 'red' : 'ink'}
            figure={formatUsdCents(collect.totalCents)}
            detail={detail}
            more={collect.customers > collect.rows.length ? { href: bo('/cobranza'), label: 'Ver toda la cobranza' } : undefined}
        >
            {collect.rows.length === 0 ? (
                <EmptyNote>Todo al día.</EmptyNote>
            ) : (
                collect.rows.map((row) => {
                    const whatsapp = reminderUrl({ ...row, business, portalUrl });

                    return (
                        <BoardRow
                            key={row.customerId}
                            title={
                                <Link href={row.url} className="hover:underline">
                                    {row.name}
                                </Link>
                            }
                            meta={
                                <span className={cn(row.daysLate > 0 && 'text-error font-semibold')}>
                                    {row.daysLate > 0 ? `Atrasada ${plural(row.daysLate, 'día', 'días')}` : 'Vence hoy'}
                                    {row.installments > 1 ? ` · ${row.installments} cuotas` : ''}
                                </span>
                            }
                            amount={formatUsdCents(row.dueCents)}
                            action={
                                whatsapp === null ? (
                                    <Link href={row.url} className={cn(ROW_ACTION, 'btn-ghost')}>
                                        Ver
                                    </Link>
                                ) : (
                                    <a href={whatsapp} target="_blank" rel="noopener noreferrer" className={cn(ROW_ACTION)}>
                                        WhatsApp
                                    </a>
                                )
                            }
                        />
                    );
                })
            )}
        </BoardColumn>
    );
}

export function MonthColumn({
    metrics,
    collect,
    links,
}: {
    metrics: DashboardMetrics;
    collect: DashboardCollectToday | null;
    links: DashboardProps['links'];
}) {
    const items = [
        { label: 'Pagados sin entregar', value: metrics.completedNotDelivered, href: links.completedNotDelivered, tone: 'text-success' },
        { label: 'Atraso largo', value: metrics.longOverdueLayaways, href: links.longOverdue, tone: 'text-error' },
        { label: 'Chats sin leer', value: metrics.unreadMessages, href: bo('/chat'), tone: 'text-primary' },
    ];

    return (
        <BoardColumn
            id="este-mes"
            label="Este mes"
            tone="green"
            figure={metrics.collectedThisMonth?.usd ?? '—'}
            detail={
                <>
                    entró
                    {metrics.collectedThisMonth?.ves ? ` (${metrics.collectedThisMonth.ves})` : ''}
                    {collect !== null ? ` · falta ${formatUsdCents(collect.outstandingCents)}` : ''}
                </>
            }
        >
            {items.map((item) => (
                <BoardRow
                    key={item.label}
                    title={
                        <Link href={item.href} className="hover:underline">
                            {item.label}
                        </Link>
                    }
                    amount={<span className={cn((item.value ?? 0) > 0 ? item.tone : 'text-ink-3')}>{item.value ?? '—'}</span>}
                />
            ))}
        </BoardColumn>
    );
}
