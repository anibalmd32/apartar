import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { cn } from '@/lib/utils';
import { type ThreadContext } from '@/types/chat';
import { cilChevronRight, cilWallet } from '@coreui/icons';
import { Link } from '@inertiajs/react';

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

export function ThreadContextPanel({ context, className }: { context: ThreadContext; className?: string }) {
    const rows: { label: string; value: string; danger?: boolean }[] = [
        { label: 'Apartados activos', value: String(context.activeLayaways) },
        { label: 'Saldo pendiente', value: context.remaining },
        {
            label: 'En mora',
            value: plural(context.overdueInstallments, 'cuota', 'cuotas') + (context.overdueAmount ? ` · ${context.overdueAmount}` : ''),
            danger: context.overdueInstallments > 0,
        },
        ...(context.nextDueDate
            ? [{ label: 'Próximo vencimiento', value: `${context.nextDueDate.slice(8, 10)}/${context.nextDueDate.slice(5, 7)}` }]
            : []),
        { label: 'Teléfono', value: context.phone },
    ];

    return (
        <aside className={cn('bg-surface-2 flex flex-col gap-3 p-4', className)} aria-label="Contexto del cliente">
            <h2 className="text-eyebrow text-ink-3">Contexto del cliente</h2>
            <dl className="flex flex-col gap-3 text-[13px]">
                {rows.map((row) => (
                    <div key={row.label} className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-2">{row.label}</dt>
                        <dd className={cn('text-right font-semibold', row.danger && 'text-error')}>{row.value}</dd>
                    </div>
                ))}
            </dl>
            {context.layaways !== undefined && context.layaways.length > 0 ? (
                <>
                    <h3 className="text-eyebrow text-ink-3 mt-2">Apartados</h3>
                    <ul className="flex flex-col gap-2">
                        {context.layaways.map((layaway) => (
                            <li key={layaway.code}>
                                <Link
                                    href={layaway.url}
                                    className="bg-base-100 border-line hover:border-line-strong flex flex-col gap-0.5 rounded-xl border px-3 py-2"
                                >
                                    <span className="flex items-center justify-between gap-2 text-xs">
                                        <span className="font-bold">{layaway.code}</span>
                                        <span className={cn('font-semibold', layaway.status === 'overdue' ? 'text-error' : 'text-success')}>
                                            {layaway.statusLabel}
                                        </span>
                                    </span>
                                    <span className="text-ink-2 truncate text-[13px]">{layaway.productName}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            ) : null}
            <Link href={context.profileUrl} className="btn btn-block mt-2">
                Ver perfil completo
            </Link>
        </aside>
    );
}

export function ThreadContextHeader({ context, className }: { context: ThreadContext; className?: string }) {
    return (
        <Link
            href={context.profileUrl}
            className={cn('border-line bg-base-100 hover:border-line-strong flex items-center gap-2.5 rounded-xl border px-3 py-2.5', className)}
            aria-label={`Contexto del cliente: ver el perfil de ${context.customerName}`}
        >
            <IconChip icon={cilWallet} size={30} />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-[13px] font-semibold">
                    {plural(context.activeLayaways, 'apartado', 'apartados')} · resta {context.remaining}
                </span>
                <span className={cn('truncate text-xs', context.overdueInstallments > 0 ? 'text-error' : 'text-ink-3')}>
                    {plural(context.overdueInstallments, 'cuota vencida', 'cuotas vencidas')} · {context.phone}
                </span>
            </span>
            <Icon icon={cilChevronRight} size={14} className="text-ink-3" />
        </Link>
    );
}
